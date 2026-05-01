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
    applyAnchorMatchDecoration,
    applyAnchorOpenedDecoration,
    clearAnchorMatchDecoration,
} from './anchor-match-decoration';
import { enqueueReferrerCheck, getCachedReferrerCheck, upsertReferrerCheckCache } from './referrer-check-queue';
import { invalidateOpenTabCheckCache, isUrlOpenInAnotherTab, toComparableOpenTabUrl } from './open-anchor-tab-check';
import type { ProgressEvent } from './download-progress-bus';

const ANCHOR_MEDIA_BORDER_ATTR = 'data-atlas-anchor-media-red-border';
const ANCHOR_MEDIA_MATCH_ATTR = 'data-atlas-anchor-media-match';

type AnchorMediaRuntimeOptions = {
    getIsEnabled: () => boolean;
    getRules: () => UrlMatchRule[];
    getReferrerCleanerQueryParams: () => string[];
    getPageHostname: () => string;
};

type AnchorMediaMatchResult = {
    reaction: string | null;
    downloadedAt: string | null;
    blacklistedAt: string | null;
};

function isVisibleInViewport(element: Element): boolean {
    const rect = element.getBoundingClientRect();
    const viewportWidth = window.innerWidth || document.documentElement.clientWidth;
    const viewportHeight = window.innerHeight || document.documentElement.clientHeight;

    return rect.bottom > 0
        && rect.right > 0
        && rect.top < viewportHeight
        && rect.left < viewportWidth
        && rect.width > 0
        && rect.height > 0;
}

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
    const anchorMediaObserver = new IntersectionObserver((entries) => {
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

    function clearAnchorMediaAttributes(media: MediaElement): void {
        media.removeAttribute(ANCHOR_MEDIA_BORDER_ATTR);
        media.removeAttribute(ANCHOR_MEDIA_MATCH_ATTR);
        media.removeAttribute('data-atlas-anchor-checking');
        media.removeAttribute('data-atlas-anchor-opened-elsewhere');
        media.removeAttribute('data-atlas-anchor-reaction');
        media.removeAttribute('data-atlas-anchor-downloaded-at');
        media.removeAttribute('data-atlas-anchor-blacklisted-at');
    }

    function applyAnchorMediaMatch(media: MediaElement, result: AnchorMediaMatchResult): void {
        const reaction = result.reaction === 'love'
            || result.reaction === 'like'
            || result.reaction === 'funny'
            ? result.reaction
            : null;

        applyAnchorMatchDecoration(media, reaction);
        media.setAttribute(ANCHOR_MEDIA_BORDER_ATTR, '1');
        media.setAttribute(ANCHOR_MEDIA_MATCH_ATTR, '1');
        media.removeAttribute('data-atlas-anchor-checking');
        media.removeAttribute('data-atlas-anchor-opened-elsewhere');

        if (result.reaction) {
            media.setAttribute('data-atlas-anchor-reaction', result.reaction);
        } else {
            media.removeAttribute('data-atlas-anchor-reaction');
        }

        if (result.downloadedAt) {
            media.setAttribute('data-atlas-anchor-downloaded-at', result.downloadedAt);
        } else {
            media.removeAttribute('data-atlas-anchor-downloaded-at');
        }

        if (result.blacklistedAt) {
            media.setAttribute('data-atlas-anchor-blacklisted-at', result.blacklistedAt);
        } else {
            media.removeAttribute('data-atlas-anchor-blacklisted-at');
        }
    }

    function applyAnchorMediaOpenedElsewhere(media: MediaElement): void {
        applyAnchorOpenedDecoration(media);
        media.setAttribute(ANCHOR_MEDIA_BORDER_ATTR, '1');
        media.setAttribute(ANCHOR_MEDIA_MATCH_ATTR, '0');
        media.removeAttribute('data-atlas-anchor-checking');
        media.setAttribute('data-atlas-anchor-opened-elsewhere', '1');
        media.removeAttribute('data-atlas-anchor-reaction');
        media.removeAttribute('data-atlas-anchor-downloaded-at');
        media.removeAttribute('data-atlas-anchor-blacklisted-at');
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
        const anchor = media.closest('a[href]');
        if (!(anchor instanceof HTMLAnchorElement)) {
            anchorReferrerKeyByMedia.delete(media);
            clearAnchorMatchDecoration(media);
            clearAnchorMediaAttributes(media);
            return;
        }

        const absoluteHref = anchor.href;
        const referrerCleanerQueryParams = options.getReferrerCleanerQueryParams();
        const anchorHref = resolveEligibleAnchorReferrerUrl(anchor, referrerCleanerQueryParams);
        if (anchorHref === null) {
            anchorReferrerKeyByMedia.delete(media);
            clearAnchorMatchDecoration(media);
            clearAnchorMediaAttributes(media);
            return;
        }

        const referrerKey = anchorHref;
        anchorReferrerKeyByMedia.set(media, referrerKey);
        const isCacheOnly = optionsOverride?.referrerMatchFromCacheOnly === true;
        const cachedResult = getCachedReferrerCheck(anchorHref, referrerCleanerQueryParams);
        if (!isCacheOnly && cachedResult === null) {
            applyAnchorCheckingDecoration(media);
            media.setAttribute(ANCHOR_MEDIA_BORDER_ATTR, '1');
            media.setAttribute(ANCHOR_MEDIA_MATCH_ATTR, '0');
            media.setAttribute('data-atlas-anchor-checking', '1');
            media.removeAttribute('data-atlas-anchor-opened-elsewhere');
            media.removeAttribute('data-atlas-anchor-reaction');
            media.removeAttribute('data-atlas-anchor-downloaded-at');
            media.removeAttribute('data-atlas-anchor-blacklisted-at');
        }
        const referrerResultPromise = isCacheOnly || cachedResult !== null
            ? Promise.resolve(cachedResult)
            : enqueueReferrerCheck(anchorHref, referrerCleanerQueryParams).then((result) => result);

        void referrerResultPromise.then((result) => {
            if (!media.isConnected || anchorReferrerKeyByMedia.get(media) !== referrerKey) {
                return;
            }

            if (isCacheOnly && result === null) {
                void isUrlOpenInAnotherTab(absoluteHref).then((isOpenedElsewhere) => {
                    if (!media.isConnected || anchorReferrerKeyByMedia.get(media) !== referrerKey) {
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
                if (!media.isConnected || anchorReferrerKeyByMedia.get(media) !== referrerKey) {
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
        if (media.closest('a[href]') === null) {
            return;
        }

        if (!observedAnchorMedia.has(media)) {
            observedAnchorMedia.add(media);
            anchorMediaObserver.observe(media);
        }

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

    function applyReactionForReferrerUrl(
        referrerUrl: string,
        reaction: 'love' | 'like' | 'funny' | null | undefined,
        downloadedAt: string | null | undefined,
        blacklistedAt: string | null | undefined,
    ): void {
        const referrerCleanerQueryParams = options.getReferrerCleanerQueryParams();
        const normalizedReferrerUrl = cleanupUrlQueryParams(referrerUrl, referrerCleanerQueryParams);
        if (normalizedReferrerUrl === null) {
            return;
        }

        forEachMatchingReferrerMedia([normalizedReferrerUrl], (mediaElement) => {
            const reactionForDecoration = reaction === undefined
                ? parseKnownReaction(mediaElement.getAttribute('data-atlas-anchor-reaction'))
                : reaction;

            applyAnchorMatchDecoration(mediaElement, reactionForDecoration);
            mediaElement.setAttribute(ANCHOR_MEDIA_BORDER_ATTR, '1');
            mediaElement.setAttribute(ANCHOR_MEDIA_MATCH_ATTR, '1');
            mediaElement.removeAttribute('data-atlas-anchor-checking');
            mediaElement.removeAttribute('data-atlas-anchor-opened-elsewhere');

            if (reaction !== undefined) {
                if (reaction) {
                    mediaElement.setAttribute('data-atlas-anchor-reaction', reaction);
                } else {
                    mediaElement.removeAttribute('data-atlas-anchor-reaction');
                }
            }

            if (downloadedAt !== undefined) {
                if (downloadedAt) {
                    mediaElement.setAttribute('data-atlas-anchor-downloaded-at', downloadedAt);
                } else {
                    mediaElement.removeAttribute('data-atlas-anchor-downloaded-at');
                }
            }

            if (blacklistedAt !== undefined) {
                if (blacklistedAt) {
                    mediaElement.setAttribute('data-atlas-anchor-blacklisted-at', blacklistedAt);
                } else {
                    mediaElement.removeAttribute('data-atlas-anchor-blacklisted-at');
                }
            }
        });
    }

    function applyPendingForReferrerUrls(referrerUrls: string[]): void {
        forEachMatchingReferrerMedia(referrerUrls, (mediaElement) => {
            applyAnchorCheckingDecoration(mediaElement);
            mediaElement.setAttribute(ANCHOR_MEDIA_BORDER_ATTR, '1');
            mediaElement.setAttribute(ANCHOR_MEDIA_MATCH_ATTR, '0');
            mediaElement.setAttribute('data-atlas-anchor-checking', '1');
            mediaElement.removeAttribute('data-atlas-anchor-opened-elsewhere');
            mediaElement.removeAttribute('data-atlas-anchor-reaction');
            mediaElement.removeAttribute('data-atlas-anchor-downloaded-at');
            mediaElement.removeAttribute('data-atlas-anchor-blacklisted-at');
        });
    }

    function refreshReferrerUrlsFromCache(referrerUrls: string[]): void {
        forEachMatchingReferrerMedia(referrerUrls, (mediaElement) => {
            applyAnchorMediaBorder(mediaElement, { referrerMatchFromCacheOnly: true });
        });
    }

    function handleDownloadProgressEvent(event: ProgressEvent): void {
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

        applyReactionForReferrerUrl(normalizedReferrer, event.reaction ?? undefined, event.downloadedAt, event.blacklistedAt);
    }

    function handleTabPresenceChanged(urls: unknown): void {
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
            applyPendingForReferrerUrls(referrerUrls);
            return;
        }

        if (phase === 'failed') {
            refreshReferrerUrlsFromCache(referrerUrls);
            return;
        }

        const reaction = parseKnownReaction(stringOrNull(payload.reaction));
        const reactedAt = stringOrNull(payload.reactedAt);
        const downloadedAt = stringOrNull(payload.downloadedAt);
        const blacklistedAt = stringOrNull(payload.blacklistedAt);

        referrerUrls.forEach((referrerUrl) => {
            upsertReferrerCheckCache(referrerUrl, {
                exists: true,
                reaction,
                reactedAt,
                downloadedAt,
                blacklistedAt,
            }, referrerCleanerQueryParams);
            applyReactionForReferrerUrl(referrerUrl, reaction, downloadedAt, blacklistedAt);
        });
    }

    return {
        handleDownloadProgressEvent,
        handleReferrerReactionSync,
        handleTabPresenceChanged,
        registerFromDocument,
        registerFromNode,
    };
}
