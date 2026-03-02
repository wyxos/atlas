import { getStoredOptions } from './atlas-options';
import { DEFAULT_MATCH_RULES, urlMatchesAnyRule, type UrlMatchRule } from './match-rules';
import {
    collectMediaFromNode,
    isMediaElement,
    normalizeUrl,
    resolveMediaUrl,
    shouldExcludeAnchorHref,
    shouldExcludeMediaOrAnchorUrl,
    type MediaElement,
} from './content/media-utils';
import { OverlayManager } from './content/overlay-manager';
import { enqueueReferrerCheck, getCachedReferrerCheck, upsertReferrerCheckCache } from './content/referrer-check-queue';
import { applyAnchorMatchDecoration, applyAnchorOpenedDecoration, clearAnchorMatchDecoration } from './content/anchor-match-decoration';
import { invalidateOpenTabCheckCache, isUrlOpenInAnotherTab, toComparableOpenTabUrl } from './content/open-anchor-tab-check';
import { subscribeToDownloadProgress } from './content/download-progress-bus';
import { createDownloadEventSheet } from './content/download-event-sheet';
import { mountReloadRequiredToastHost } from './content/reload-required-toast-host';

const OBSERVED_ATTRS = ['src', 'srcset', 'poster'] as const;
const ANCHOR_MEDIA_BORDER_ATTR = 'data-atlas-anchor-media-red-border';
const ANCHOR_MEDIA_MATCH_ATTR = 'data-atlas-anchor-media-match';
const MEDIA_WIDGET_APPLIED_ATTR = 'data-atlas-media-red-applied';

let currentRules: UrlMatchRule[] = [...DEFAULT_MATCH_RULES];
let currentPageHostname = window.location.hostname;
const overlayManager = new OverlayManager();
const downloadEventSheet = createDownloadEventSheet();
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

function mediaMatchesRules(element: MediaElement): boolean {
    const rawMediaUrl = resolveMediaUrl(element);
    const mediaUrl = normalizeUrl(rawMediaUrl);
    if (mediaUrl === null || shouldExcludeMediaOrAnchorUrl(rawMediaUrl)) {
        return false;
    }

    return urlMatchesAnyRule(mediaUrl, currentRules, currentPageHostname);
}

function processMedia(media: MediaElement): void {
    if (media.closest('a[href]') !== null) {
        overlayManager.remove(media);
        return;
    }

    if (mediaMatchesRules(media)) {
        overlayManager.apply(media);
        return;
    }

    overlayManager.remove(media);
}

function processNodeAndDescendants(node: Node): void {
    for (const media of collectMediaFromNode(node)) {
        processMedia(media);
    }
}

function scheduleReposition(): void {
    overlayManager.scheduleReposition();
}

function processSourceMutation(sourceElement: HTMLSourceElement): void {
    const pictureParent = sourceElement.closest('picture');
    const pictureImg = pictureParent?.querySelector('img');
    if (pictureImg && isMediaElement(pictureImg)) {
        processMedia(pictureImg);
        scheduleReposition();
        return;
    }

    const videoParent = sourceElement.closest('video');
    if (videoParent && isMediaElement(videoParent)) {
        processMedia(videoParent);
        scheduleReposition();
    }
}

function processAllCurrentMedia(): void {
    const mediaElements = document.querySelectorAll('img,video');
    for (const mediaElement of mediaElements) {
        if (isMediaElement(mediaElement)) {
            processMedia(mediaElement);
        }
    }
}

function tryApplyMediaWidgetFromInteractionTarget(target: EventTarget | null): void {
    if (!(target instanceof Element)) {
        return;
    }

    const mediaCandidate = target.closest('img,video');
    if (!mediaCandidate || !isMediaElement(mediaCandidate)) {
        return;
    }

    if (mediaCandidate.closest('a[href]') !== null) {
        return;
    }

    if (mediaCandidate.getAttribute(MEDIA_WIDGET_APPLIED_ATTR) === '1') {
        return;
    }

    if (!mediaMatchesRules(mediaCandidate)) {
        return;
    }

    overlayManager.apply(mediaCandidate);
}

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

function clearAnchorMediaAttributes(media: MediaElement): void {
    media.removeAttribute(ANCHOR_MEDIA_BORDER_ATTR);
    media.removeAttribute(ANCHOR_MEDIA_MATCH_ATTR);
    media.removeAttribute('data-atlas-anchor-opened-elsewhere');
    media.removeAttribute('data-atlas-anchor-reaction');
    media.removeAttribute('data-atlas-anchor-downloaded-at');
    media.removeAttribute('data-atlas-anchor-blacklisted-at');
}

function applyAnchorMediaMatch(media: MediaElement, result: {
    reaction: string | null;
    downloadedAt: string | null;
    blacklistedAt: string | null;
}): void {
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
    options?: { referrerMatchFromCacheOnly?: boolean },
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
    const anchorHref = normalizeUrl(absoluteHref);
    const isValid = anchorHref !== null
        && !shouldExcludeAnchorHref(rawHref, absoluteHref)
        && urlMatchesAnyRule(anchorHref, currentRules, currentPageHostname);
    if (!isValid) {
        anchorReferrerKeyByMedia.delete(media);
        clearAnchorMatchDecoration(media);
        clearAnchorMediaAttributes(media);
        return;
    }

    const referrerKey = anchorHref;
    anchorReferrerKeyByMedia.set(media, referrerKey);
    const isCacheOnly = options?.referrerMatchFromCacheOnly === true;
    const referrerResultPromise = isCacheOnly
        ? Promise.resolve(getCachedReferrerCheck(anchorHref))
        : enqueueReferrerCheck(anchorHref).then((result) => result);
    void referrerResultPromise.then((result) => {
        if (!media.isConnected) {
            return;
        }

        if (anchorReferrerKeyByMedia.get(media) !== referrerKey) {
            return;
        }

        if (isCacheOnly && result === null) {
            return;
        }

        const isMatch = result?.exists === true;
        if (isMatch) {
            applyAnchorMediaMatch(media, {
                reaction: result.reaction,
                downloadedAt: result.downloadedAt,
                blacklistedAt: result.blacklistedAt,
            });
            return;
        }

        void isUrlOpenInAnotherTab(absoluteHref).then((isOpenedElsewhere) => {
            if (!media.isConnected) {
                return;
            }

            if (anchorReferrerKeyByMedia.get(media) !== referrerKey) {
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

function registerAnchorMediaFromNode(node: Node): void {
    if (!(node instanceof Element)) {
        return;
    }

    if (isMediaElement(node)) {
        registerAnchorMediaCandidate(node);
    }

    for (const mediaElement of node.querySelectorAll('a[href] img, a[href] video')) {
        if (!isMediaElement(mediaElement)) {
            continue;
        }

        registerAnchorMediaCandidate(mediaElement);
    }
}

function registerAnchorMediaFromDocument(): void {
    for (const mediaElement of document.querySelectorAll('a[href] img, a[href] video')) {
        if (!isMediaElement(mediaElement)) {
            continue;
        }

        registerAnchorMediaCandidate(mediaElement);
    }
}

function refreshVisibleAnchorMediaForUrls(changedUrls: string[]): void {
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
        if (comparableAnchorUrl === null || !changedUrlSet.has(comparableAnchorUrl)) {
            continue;
        }

        applyAnchorMediaBorder(mediaElement, { referrerMatchFromCacheOnly: true });
    }
}

function installMutationObserver(): void {
    const observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            if (mutation.type === 'childList') {
                for (const addedNode of mutation.addedNodes) {
                    processNodeAndDescendants(addedNode);
                    registerAnchorMediaFromNode(addedNode);
                }
                scheduleReposition();
            }

            if (mutation.type === 'attributes' && mutation.target instanceof Element && isMediaElement(mutation.target)) {
                processMedia(mutation.target);
                registerAnchorMediaCandidate(mutation.target);
                scheduleReposition();
            }

            if (mutation.type === 'attributes' && mutation.target instanceof HTMLAnchorElement) {
                registerAnchorMediaFromNode(mutation.target);
            }

            if (mutation.type === 'attributes' && mutation.target instanceof HTMLSourceElement) {
                processSourceMutation(mutation.target);
            }
        }
    });

    observer.observe(document.documentElement, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: [...OBSERVED_ATTRS, 'href'],
    });
}

function installStorageListener(): void {
    if (!chrome.storage?.onChanged) {
        return;
    }

    chrome.storage.onChanged.addListener(() => {
        void loadRulesAndProcess();
    });
}

function installRuntimeMessageListener(): void {
    if (!chrome.runtime?.onMessage) {
        return;
    }

    chrome.runtime.onMessage.addListener((message: unknown) => {
        if (typeof message !== 'object' || message === null) {
            return;
        }

        const type = (message as { type?: unknown }).type;
        if (type !== 'ATLAS_TAB_PRESENCE_CHANGED') {
            return;
        }

        const urls = (message as { urls?: unknown }).urls;
        const changedUrls = Array.isArray(urls)
            ? urls
                .map((url: unknown) => (typeof url === 'string' ? toComparableOpenTabUrl(url) : null))
                .filter((url): url is string => url !== null)
            : [];
        if (changedUrls.length === 0) {
            return;
        }

        invalidateOpenTabCheckCache(changedUrls);
        refreshVisibleAnchorMediaForUrls(changedUrls);
    });
}

function isTerminalTransferStatus(status: string | null): boolean {
    return status === 'completed' || status === 'failed' || status === 'canceled';
}

function parseKnownReaction(value: string | null): 'love' | 'like' | 'dislike' | 'funny' | null {
    return value === 'love' || value === 'like' || value === 'dislike' || value === 'funny'
        ? value
        : null;
}

function payloadString(
    payload: Record<string, unknown>,
    ...keys: string[]
): string | null | undefined {
    for (const key of keys) {
        if (!(key in payload)) {
            continue;
        }

        const value = payload[key];
        return typeof value === 'string' && value.trim() !== '' ? value : null;
    }

    return undefined;
}

function applyReactionForReferrerUrl(
    referrerUrl: string,
    reaction: 'love' | 'like' | 'dislike' | 'funny' | null | undefined,
    downloadedAt: string | null | undefined,
    blacklistedAt: string | null | undefined,
): void {
    const normalizedReferrerUrl = normalizeUrl(referrerUrl);
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

        const anchorHref = normalizeUrl(anchor.href);
        if (anchorHref !== normalizedReferrerUrl) {
            continue;
        }

        if (!isVisibleInViewport(mediaElement)) {
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

function installDownloadProgressListener(): void {
    subscribeToDownloadProgress((event) => {
        downloadEventSheet.push(event);

        const isLifecycleEvent = event.event === 'DownloadTransferCreated'
            || event.event === 'DownloadTransferQueued'
            || isTerminalTransferStatus(event.status);

        if (isLifecycleEvent && event.referrerUrl) {
            const normalizedReferrer = normalizeUrl(event.referrerUrl);
            if (normalizedReferrer) {
                const reaction = event.reaction ?? undefined;
                const reactedAt = payloadString(event.payload, 'reacted_at', 'reactedAt');
                const downloadedAt = payloadString(event.payload, 'downloaded_at', 'downloadedAt');
                const blacklistedAt = payloadString(event.payload, 'blacklisted_at', 'blacklistedAt');

                upsertReferrerCheckCache(normalizedReferrer, {
                    exists: true,
                    reaction,
                    reactedAt,
                    downloadedAt,
                    blacklistedAt,
                });

                applyReactionForReferrerUrl(normalizedReferrer, reaction, downloadedAt, blacklistedAt);
            }
        }

    });
}

function installViewportListeners(): void {
    window.addEventListener('scroll', scheduleReposition, { passive: true });
    window.addEventListener('resize', scheduleReposition, { passive: true });
}

function installInteractionFallbackListeners(): void {
    const handleInteraction = (event: MouseEvent): void => {
        tryApplyMediaWidgetFromInteractionTarget(event.target);
    };

    document.addEventListener('mouseover', handleInteraction, { passive: true });
    document.addEventListener('mouseup', handleInteraction, { passive: true });
}

async function loadRulesAndProcess(): Promise<void> {
    try {
        const stored = await getStoredOptions();
        currentRules = stored.matchRules;
    } catch {
        currentRules = [...DEFAULT_MATCH_RULES];
    }

    currentPageHostname = window.location.hostname;
    processAllCurrentMedia();
    registerAnchorMediaFromDocument();
}

function bootstrap(): void {
    mountReloadRequiredToastHost();
    installMutationObserver();
    installStorageListener();
    installRuntimeMessageListener();
    installDownloadProgressListener();
    installViewportListeners();
    installInteractionFallbackListeners();
    void loadRulesAndProcess();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
} else {
    bootstrap();
}
