import type { UrlMatchRule } from '../match-rules';
import {
    isMediaElement,
    normalizeHashAwareUrl,
    normalizeUrl,
    shouldExcludeAnchorHref,
    type MediaElement,
} from './media-utils';
import { urlMatchesAnyRule } from '../match-rules';
import { applyAnchorMatchDecoration, applyAnchorOpenedDecoration, clearAnchorMatchDecoration } from './anchor-match-decoration';
import { enqueueReferrerCheck, getCachedReferrerCheck, upsertReferrerCheckCache } from './referrer-check-queue';
import { invalidateOpenTabCheckCache, isUrlOpenInAnotherTab, toComparableOpenTabUrl } from './open-anchor-tab-check';
import type { ProgressEvent } from './download-progress-bus';

const ANCHOR_MEDIA_BORDER_ATTR = 'data-atlas-anchor-media-red-border';
const ANCHOR_MEDIA_MATCH_ATTR = 'data-atlas-anchor-media-match';

type AnchorMediaRuntimeOptions = {
    getRules: () => UrlMatchRule[];
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

function parseKnownReaction(value: string | null): 'love' | 'like' | 'dislike' | 'funny' | null {
    return value === 'love' || value === 'like' || value === 'dislike' || value === 'funny'
        ? value
        : null;
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
        media.removeAttribute('data-atlas-anchor-opened-elsewhere');
        media.removeAttribute('data-atlas-anchor-reaction');
        media.removeAttribute('data-atlas-anchor-downloaded-at');
        media.removeAttribute('data-atlas-anchor-blacklisted-at');
    }

    function applyAnchorMediaMatch(media: MediaElement, result: AnchorMediaMatchResult): void {
        const reaction = result.reaction === 'love'
            || result.reaction === 'like'
            || result.reaction === 'dislike'
            || result.reaction === 'funny'
            ? result.reaction
            : null;

        applyAnchorMatchDecoration(media, reaction);
        media.setAttribute(ANCHOR_MEDIA_BORDER_ATTR, '1');
        media.setAttribute(ANCHOR_MEDIA_MATCH_ATTR, '1');
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
        media.setAttribute('data-atlas-anchor-opened-elsewhere', '1');
        media.removeAttribute('data-atlas-anchor-reaction');
        media.removeAttribute('data-atlas-anchor-downloaded-at');
        media.removeAttribute('data-atlas-anchor-blacklisted-at');
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

        const rawHref = anchor.getAttribute('href');
        const absoluteHref = anchor.href;
        const anchorHref = normalizeHashAwareUrl(absoluteHref);
        const isValid = anchorHref !== null
            && !shouldExcludeAnchorHref(rawHref, absoluteHref)
            && urlMatchesAnyRule(anchorHref, options.getRules(), options.getPageHostname());
        if (!isValid) {
            anchorReferrerKeyByMedia.delete(media);
            clearAnchorMatchDecoration(media);
            clearAnchorMediaAttributes(media);
            return;
        }

        const referrerKey = anchorHref;
        anchorReferrerKeyByMedia.set(media, referrerKey);
        const isCacheOnly = optionsOverride?.referrerMatchFromCacheOnly === true;
        const referrerResultPromise = isCacheOnly
            ? Promise.resolve(getCachedReferrerCheck(anchorHref))
            : enqueueReferrerCheck(anchorHref).then((result) => result);

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
        reaction: 'love' | 'like' | 'dislike' | 'funny' | null | undefined,
        downloadedAt: string | null | undefined,
        blacklistedAt: string | null | undefined,
    ): void {
        const normalizedReferrerUrl = normalizeHashAwareUrl(referrerUrl);
        if (normalizedReferrerUrl === null) {
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

            const rawHref = anchor.getAttribute('href');
            const anchorHref = normalizeHashAwareUrl(anchor.href);
            const isEligibleAnchor = anchorHref !== null
                && !shouldExcludeAnchorHref(rawHref, anchor.href)
                && urlMatchesAnyRule(anchorHref, options.getRules(), options.getPageHostname());
            if (!isEligibleAnchor || anchorHref !== normalizedReferrerUrl) {
                continue;
            }

            const reactionForDecoration = reaction === undefined
                ? parseKnownReaction(mediaElement.getAttribute('data-atlas-anchor-reaction'))
                : reaction;

            applyAnchorMatchDecoration(mediaElement, reactionForDecoration);
            mediaElement.setAttribute(ANCHOR_MEDIA_BORDER_ATTR, '1');
            mediaElement.setAttribute(ANCHOR_MEDIA_MATCH_ATTR, '1');
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
        }
    }

    function handleDownloadProgressEvent(event: ProgressEvent): void {
        const isLifecycleEvent = event.event === 'DownloadTransferCreated'
            || event.event === 'DownloadTransferQueued'
            || isTerminalTransferStatus(event.status);

        if (!isLifecycleEvent || !event.referrerUrl) {
            return;
        }

        const normalizedReferrer = normalizeUrl(event.referrerUrl);
        if (!normalizedReferrer) {
            return;
        }

        upsertReferrerCheckCache(normalizedReferrer, {
            exists: true,
            reaction: event.reaction ?? undefined,
            reactedAt: event.reactedAt,
            downloadedAt: event.downloadedAt,
            blacklistedAt: event.blacklistedAt,
        });

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

    return {
        handleDownloadProgressEvent,
        handleTabPresenceChanged,
        registerFromDocument,
        registerFromNode,
    };
}
