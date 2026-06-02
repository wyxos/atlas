import type { UrlMatchRule } from '../match-rules';
import { cleanupUrlQueryParams } from '../referrer-cleanup';
import {
    isMediaElement,
    normalizeHashAwareUrl,
    normalizeUrl,
    shouldExcludeAnchorHref,
    type MediaElement,
} from './media-utils';
import { urlMatchesAnyRule } from '../match-rules';
import {
    applyAnchorCheckingDecoration,
    clearAnchorMatchDecoration,
} from './anchor-match-decoration';
import {
    ANCHOR_MEDIA_BORDER_ATTR,
    ANCHOR_MEDIA_MATCH_ATTR,
    ANCHOR_MEDIA_SAME_PAGE_ATTR,
    applyAnchorMediaMatch,
    applyAnchorMediaOpenedElsewhere,
    applyAnchorMediaSamePage,
    clearAnchorMediaAttributes,
} from './anchor-media-state';
import {
    enqueueReferrerCheck,
    getCachedReferrerCheck,
    upsertReferrerCheckCache,
    type ReferrerMatchResult,
} from './referrer-check-queue';
import { invalidateOpenTabCheckCache, isUrlOpenInAnotherTab, toComparableOpenTabUrl } from './open-anchor-tab-check';
import type { ProgressEvent } from './download-progress-bus';
import { handleAltRightClickReferrerBlacklist } from './anchor-referrer-blacklist-shortcut';
import { createAnchorReferrerSyncDecorations } from './anchor-referrer-sync-decorations';
import { isVisibleInViewport } from './viewport-visibility';

type AnchorMediaRuntimeOptions = {
    getIsEnabled: () => boolean;
    getRules: () => UrlMatchRule[];
    getReferrerCleanerQueryParams: () => string[];
    getPageHostname: () => string;
};

function isTerminalTransferStatus(status: string | null): boolean {
    return status === 'completed' || status === 'failed' || status === 'canceled';
}

function parseKnownReaction(value: string | null): 'love' | 'like' | 'funny' | null {
    return value === 'love' || value === 'like' || value === 'funny'
        ? value
        : null;
}

function stringOrNull(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

export function createAnchorMediaRuntime(options: AnchorMediaRuntimeOptions) {
    const observedAnchorMedia = new WeakSet<MediaElement>();
    const anchorReferrerKeyByMedia = new WeakMap<MediaElement, string>();
    const anchorCheckSequenceByMedia = new WeakMap<MediaElement, number>();
    const referrerCheckStateByMedia = new WeakMap<MediaElement, { key: string; phase: 'pending' | 'settled' }>();
    const localReferrerResultByKey = new Map<string, ReferrerMatchResult>();
    let isPaused = false;
    let pauseSequence = 0;
    let anchorCheckSequence = 0;
    const anchorMediaObserver = new IntersectionObserver((entries) => {
        if (isPaused) {
            return;
        }

        for (const entry of entries) {
            if (!entry.isIntersecting) {
                continue;
            }

            const target = entry.target;
            if (!isMediaElement(target)) {
                continue;
            }

            applyAnchorMediaBorder(target);
        }
    }, {
        root: null,
        rootMargin: '160px 0px',
        threshold: 0.01,
    });

    function isCurrentPageAnchor(anchor: HTMLAnchorElement): boolean {
        const normalizedAnchorHref = normalizeHashAwareUrl(anchor.href);
        const normalizedCurrentPageHref = normalizeHashAwareUrl(window.location.href);

        return normalizedAnchorHref !== null && normalizedAnchorHref === normalizedCurrentPageHref;
    }

    function resolveEligibleAnchorReferrerUrl(
        anchor: HTMLAnchorElement,
        referrerCleanerQueryParams: string[],
    ): string | null {
        if (!options.getIsEnabled()) {
            return null;
        }

        const rawHref = anchor.getAttribute('href');
        const normalizedAnchorHref = normalizeHashAwareUrl(anchor.href);
        const anchorHref = cleanupUrlQueryParams(normalizedAnchorHref, referrerCleanerQueryParams);
        const isEligibleAnchor = normalizedAnchorHref !== null
            && anchorHref !== null
            && !shouldExcludeAnchorHref(rawHref, anchor.href)
            && urlMatchesAnyRule(normalizedAnchorHref, options.getRules(), options.getPageHostname());

        return isEligibleAnchor ? anchorHref : null;
    }

    function forEachMatchingReferrerMedia(
        referrerUrls: string[],
        callback: (mediaElement: MediaElement) => void,
    ): void {
        const dedupedReferrerUrls = Array.from(new Set(referrerUrls));
        if (dedupedReferrerUrls.length === 0) {
            return;
        }

        const referrerCleanerQueryParams = options.getReferrerCleanerQueryParams();
        const referrerUrlSet = new Set(dedupedReferrerUrls);

        for (const mediaElement of document.querySelectorAll('a[href] img, a[href] video')) {
            if (!isMediaElement(mediaElement)) {
                continue;
            }

            const anchor = mediaElement.closest('a[href]');
            if (!(anchor instanceof HTMLAnchorElement)) {
                continue;
            }

            const anchorHref = resolveEligibleAnchorReferrerUrl(anchor, referrerCleanerQueryParams);
            if (anchorHref === null || !referrerUrlSet.has(anchorHref)) {
                continue;
            }

            callback(mediaElement);
        }
    }

    function applyAnchorMediaBorder(
        media: MediaElement,
        optionsOverride?: { referrerMatchFromCacheOnly?: boolean },
    ): void {
        if (isPaused) {
            return;
        }

        const anchor = media.closest('a[href]');
        if (!(anchor instanceof HTMLAnchorElement)) {
            anchorReferrerKeyByMedia.delete(media);
            referrerCheckStateByMedia.delete(media);
            clearAnchorMatchDecoration(media);
            clearAnchorMediaAttributes(media);
            return;
        }

        const absoluteHref = anchor.href;
        const referrerCleanerQueryParams = options.getReferrerCleanerQueryParams();
        const anchorHref = resolveEligibleAnchorReferrerUrl(anchor, referrerCleanerQueryParams);
        if (anchorHref === null) {
            anchorReferrerKeyByMedia.delete(media);
            referrerCheckStateByMedia.delete(media);
            clearAnchorMatchDecoration(media);
            clearAnchorMediaAttributes(media);
            return;
        }

        const referrerKey = anchorHref;
        if (isCurrentPageAnchor(anchor)) {
            anchorCheckSequence += 1;
            anchorReferrerKeyByMedia.set(media, referrerKey);
            anchorCheckSequenceByMedia.set(media, anchorCheckSequence);
            applyAnchorMediaSamePage(media);
            return;
        }

        const isCacheOnly = optionsOverride?.referrerMatchFromCacheOnly === true;
        const localResult = localReferrerResultByKey.get(referrerKey) ?? null;
        const cachedResult = localResult ?? getCachedReferrerCheck(anchorHref, referrerCleanerQueryParams);
        const previousCheckState = referrerCheckStateByMedia.get(media);
        if (
            !isCacheOnly
            && previousCheckState?.key === referrerKey
            && (
                previousCheckState.phase === 'pending'
                || (previousCheckState.phase === 'settled' && cachedResult?.exists !== true)
            )
        ) {
            return;
        }

        const currentPauseSequence = pauseSequence;
        anchorCheckSequence += 1;
        const currentAnchorCheckSequence = anchorCheckSequence;
        anchorReferrerKeyByMedia.set(media, referrerKey);
        anchorCheckSequenceByMedia.set(media, currentAnchorCheckSequence);

        if (!isCacheOnly && cachedResult === null) {
            referrerCheckStateByMedia.set(media, { key: referrerKey, phase: 'pending' });
            applyAnchorCheckingDecoration(media);
            media.setAttribute(ANCHOR_MEDIA_BORDER_ATTR, '1');
            media.setAttribute(ANCHOR_MEDIA_MATCH_ATTR, '0');
            media.setAttribute('data-atlas-anchor-checking', '1');
            media.removeAttribute('data-atlas-anchor-opened-elsewhere');
            media.removeAttribute(ANCHOR_MEDIA_SAME_PAGE_ATTR);
            media.removeAttribute('data-atlas-anchor-reaction');
            media.removeAttribute('data-atlas-anchor-downloaded-at');
            media.removeAttribute('data-atlas-anchor-blacklisted-at');
        }
        const referrerResultPromise = isCacheOnly || cachedResult !== null
            ? Promise.resolve(cachedResult)
            : enqueueReferrerCheck(anchorHref, referrerCleanerQueryParams).then((result) => result);

        void referrerResultPromise.then((result) => {
            if (!isCacheOnly && result !== null) {
                localReferrerResultByKey.set(referrerKey, result);
                referrerCheckStateByMedia.set(media, { key: referrerKey, phase: 'settled' });
            }

            if (
                isPaused
                || currentPauseSequence !== pauseSequence
                || !media.isConnected
                || anchorReferrerKeyByMedia.get(media) !== referrerKey
                || anchorCheckSequenceByMedia.get(media) !== currentAnchorCheckSequence
            ) {
                return;
            }

            if (isCacheOnly && result === null) {
                void isUrlOpenInAnotherTab(absoluteHref).then((isOpenedElsewhere) => {
                    if (
                        isPaused
                        || currentPauseSequence !== pauseSequence
                        || !media.isConnected
                        || anchorReferrerKeyByMedia.get(media) !== referrerKey
                        || anchorCheckSequenceByMedia.get(media) !== currentAnchorCheckSequence
                    ) {
                        return;
                    }

                    if (isOpenedElsewhere) {
                        applyAnchorMediaOpenedElsewhere(media);
                        return;
                    }

                    if (media.getAttribute('data-atlas-anchor-opened-elsewhere') === '1') {
                        clearAnchorMatchDecoration(media);
                        clearAnchorMediaAttributes(media);
                    }
                });
                return;
            }

            if (result?.exists === true) {
                applyAnchorMediaMatch(media, {
                    reaction: result.reaction,
                    downloadedAt: result.downloadedAt,
                    blacklistedAt: result.blacklistedAt,
                });
                return;
            }

            void isUrlOpenInAnotherTab(absoluteHref).then((isOpenedElsewhere) => {
                if (
                    isPaused
                    || currentPauseSequence !== pauseSequence
                    || !media.isConnected
                    || anchorReferrerKeyByMedia.get(media) !== referrerKey
                    || anchorCheckSequenceByMedia.get(media) !== currentAnchorCheckSequence
                ) {
                    return;
                }

                if (isOpenedElsewhere) {
                    applyAnchorMediaOpenedElsewhere(media);
                    return;
                }

                clearAnchorMatchDecoration(media);
                clearAnchorMediaAttributes(media);
            });
        });
    }

    function registerAnchorMediaCandidate(media: MediaElement): void {
        if (isPaused) {
            return;
        }

        if (media.closest('a[href]') === null) {
            return;
        }

        if (!observedAnchorMedia.has(media)) {
            observedAnchorMedia.add(media);
        }
        anchorMediaObserver.observe(media);

        if (isVisibleInViewport(media)) {
            applyAnchorMediaBorder(media);
        }
    }

    function registerFromNode(node: Node): void {
        if (!(node instanceof Element)) {
            return;
        }

        if (isMediaElement(node)) {
            registerAnchorMediaCandidate(node);
        }

        for (const mediaElement of node.querySelectorAll('a[href] img, a[href] video')) {
            if (isMediaElement(mediaElement)) {
                registerAnchorMediaCandidate(mediaElement);
            }
        }
    }

    function registerFromDocument(): void {
        for (const mediaElement of document.querySelectorAll('a[href] img, a[href] video')) {
            if (isMediaElement(mediaElement)) {
                registerAnchorMediaCandidate(mediaElement);
            }
        }
    }

    function registerVisibleFromDocument(limit = 100): void {
        let registeredCount = 0;
        for (const mediaElement of document.querySelectorAll('a[href] img, a[href] video')) {
            if (registeredCount >= limit) {
                return;
            }

            if (!isMediaElement(mediaElement) || !isVisibleInViewport(mediaElement)) {
                continue;
            }

            registerAnchorMediaCandidate(mediaElement);
            registeredCount += 1;
        }
    }

    function refreshVisibleForUrls(changedUrls: string[]): void {
        const changedUrlSet = new Set(changedUrls);
        if (changedUrlSet.size === 0) {
            return;
        }

        for (const mediaElement of document.querySelectorAll('a[href] img, a[href] video')) {
            if (!isMediaElement(mediaElement)) {
                continue;
            }

            const anchor = mediaElement.closest('a[href]');
            if (!(anchor instanceof HTMLAnchorElement)) {
                continue;
            }

            const comparableAnchorUrl = toComparableOpenTabUrl(anchor.href);
            if (comparableAnchorUrl !== null && changedUrlSet.has(comparableAnchorUrl)) {
                applyAnchorMediaBorder(mediaElement, { referrerMatchFromCacheOnly: true });
            }
        }
    }

    const syncDecorations = createAnchorReferrerSyncDecorations({
        getReferrerCleanerQueryParams: options.getReferrerCleanerQueryParams,
        forEachMatchingReferrerMedia,
        markReferrerSettled: (mediaElement, referrerKey) => {
            referrerCheckStateByMedia.set(mediaElement, { key: referrerKey, phase: 'settled' });
        },
        applyAnchorMediaBorderFromCache: (mediaElement) => {
            applyAnchorMediaBorder(mediaElement, { referrerMatchFromCacheOnly: true });
        },
    });

    function handleAltRightClick(event: MouseEvent): boolean {
        return handleAltRightClickReferrerBlacklist({
            event,
            isPaused: () => isPaused,
            resolveEligibleAnchorReferrerUrl,
            getReferrerCleanerQueryParams: options.getReferrerCleanerQueryParams,
            applyPendingForReferrerUrls: syncDecorations.applyPendingForReferrerUrls,
            refreshReferrerUrlsFromCache: syncDecorations.refreshReferrerUrlsFromCache,
        });
    }

    function handleDownloadProgressEvent(event: ProgressEvent): void {
        if (isPaused) {
            return;
        }

        const isLifecycleEvent = event.event === 'DownloadTransferCreated'
            || event.event === 'DownloadTransferQueued'
            || isTerminalTransferStatus(event.status);

        if (!isLifecycleEvent || !event.referrerUrl) {
            return;
        }

        const referrerCleanerQueryParams = options.getReferrerCleanerQueryParams();
        const normalizedReferrer = normalizeUrl(cleanupUrlQueryParams(event.referrerUrl, referrerCleanerQueryParams));
        if (!normalizedReferrer) {
            return;
        }

        upsertReferrerCheckCache(normalizedReferrer, {
            exists: true,
            reaction: event.reaction ?? undefined,
            reactedAt: event.reactedAt,
            downloadedAt: event.downloadedAt,
            blacklistedAt: event.blacklistedAt,
        }, referrerCleanerQueryParams);
        localReferrerResultByKey.set(normalizedReferrer, {
            exists: true,
            reaction: event.reaction ?? null,
            reactedAt: event.reactedAt ?? null,
            downloadedAt: event.downloadedAt ?? null,
            blacklistedAt: event.blacklistedAt ?? null,
        });

        syncDecorations.applyReactionForReferrerUrl(normalizedReferrer, event.reaction ?? undefined, event.downloadedAt, event.blacklistedAt);
    }

    function handleTabPresenceChanged(urls: unknown): void {
        if (isPaused) {
            return;
        }

        const changedUrls = Array.isArray(urls)
            ? urls
                .map((url: unknown) => (typeof url === 'string' ? toComparableOpenTabUrl(url) : null))
                .filter((url): url is string => url !== null)
            : [];

        if (changedUrls.length === 0) {
            return;
        }

        invalidateOpenTabCheckCache(changedUrls);
        refreshVisibleForUrls(changedUrls);
    }

    function handleReferrerReactionSync(message: unknown): void {
        if (!message || typeof message !== 'object') {
            return;
        }

        const payload = message as {
            phase?: unknown;
            urls?: unknown;
            reaction?: unknown;
            reactedAt?: unknown;
            downloadedAt?: unknown;
            blacklistedAt?: unknown;
        };
        const phase = payload.phase;
        if (phase !== 'pending' && phase !== 'settled' && phase !== 'failed') {
            return;
        }

        const referrerCleanerQueryParams = options.getReferrerCleanerQueryParams();
        const referrerUrls = Array.isArray(payload.urls)
            ? payload.urls
                .map((url) => cleanupUrlQueryParams(stringOrNull(url), referrerCleanerQueryParams))
                .filter((url): url is string => url !== null)
            : [];
        if (referrerUrls.length === 0) {
            return;
        }

        if (phase === 'pending') {
            if (!isPaused) {
                syncDecorations.applyPendingForReferrerUrls(referrerUrls);
            }
            return;
        }

        if (phase === 'failed') {
            if (!isPaused) {
                syncDecorations.refreshReferrerUrlsFromCache(referrerUrls);
            }
            return;
        }

        const reaction = parseKnownReaction(stringOrNull(payload.reaction));
        const reactedAt = stringOrNull(payload.reactedAt);
        const downloadedAt = stringOrNull(payload.downloadedAt);
        const blacklistedAt = stringOrNull(payload.blacklistedAt);
        const settledResult = {
            exists: true,
            reaction,
            reactedAt,
            downloadedAt,
            blacklistedAt,
        } satisfies ReferrerMatchResult;

        referrerUrls.forEach((referrerUrl) => {
            localReferrerResultByKey.set(referrerUrl, settledResult);
            upsertReferrerCheckCache(referrerUrl, {
                exists: true,
                reaction,
                reactedAt,
                downloadedAt,
                blacklistedAt,
            }, referrerCleanerQueryParams);
            if (!isPaused) {
                syncDecorations.applyReactionForReferrerUrl(referrerUrl, reaction, downloadedAt, blacklistedAt);
            }
        });
    }

    return {
        handleAltRightClick,
        handleDownloadProgressEvent,
        handleReferrerReactionSync,
        handleTabPresenceChanged,
        registerFromDocument,
        registerVisibleFromDocument,
        registerFromNode,
        resume: () => {
            if (!isPaused) {
                return;
            }

            isPaused = false;
        },
        suspend: () => {
            pauseSequence += 1;
            isPaused = true;
            anchorMediaObserver.disconnect();
        },
    };
}
