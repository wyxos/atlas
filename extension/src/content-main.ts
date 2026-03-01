import { getStoredOptions } from './atlas-options';
import { DEFAULT_MATCH_RULES, urlMatchesAnyRule, type UrlMatchRule } from './match-rules';
import { collectMediaFromNode, isMediaElement, normalizeUrl, resolveMediaUrl, type MediaElement } from './content/media-utils';
import { OverlayManager } from './content/overlay-manager';
import { enqueueReferrerCheck } from './content/referrer-check-queue';

const OBSERVED_ATTRS = ['src', 'srcset', 'poster'] as const;
const ANCHOR_MEDIA_BORDER_ATTR = 'data-atlas-anchor-media-red-border';
const ANCHOR_MEDIA_MATCH_ATTR = 'data-atlas-anchor-media-match';
const BORDER_COLOR_MISS = '#ef4444';
const BORDER_COLOR_MATCH = '#22c55e';

let currentRules: UrlMatchRule[] = [...DEFAULT_MATCH_RULES];
let currentPageHostname = window.location.hostname;
const overlayManager = new OverlayManager();
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
    const mediaUrl = normalizeUrl(resolveMediaUrl(element));
    return mediaUrl !== null && urlMatchesAnyRule(mediaUrl, currentRules, currentPageHostname);
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

function applyAnchorMediaBorder(media: MediaElement): void {
    const anchor = media.closest('a[href]');
    if (!(anchor instanceof HTMLAnchorElement)) {
        anchorReferrerKeyByMedia.delete(media);
        media.style.outline = '';
        media.style.outlineOffset = '';
        media.removeAttribute(ANCHOR_MEDIA_BORDER_ATTR);
        media.removeAttribute(ANCHOR_MEDIA_MATCH_ATTR);
        media.removeAttribute('data-atlas-anchor-reaction');
        media.removeAttribute('data-atlas-anchor-downloaded-at');
        media.removeAttribute('data-atlas-anchor-blacklisted-at');
        return;
    }

    const anchorHref = normalizeUrl(anchor.href);
    const isValid = anchorHref !== null && urlMatchesAnyRule(anchorHref, currentRules, currentPageHostname);
    if (!isValid) {
        anchorReferrerKeyByMedia.delete(media);
        media.style.outline = '';
        media.style.outlineOffset = '';
        media.removeAttribute(ANCHOR_MEDIA_BORDER_ATTR);
        media.removeAttribute(ANCHOR_MEDIA_MATCH_ATTR);
        media.removeAttribute('data-atlas-anchor-reaction');
        media.removeAttribute('data-atlas-anchor-downloaded-at');
        media.removeAttribute('data-atlas-anchor-blacklisted-at');
        return;
    }

    const referrerKey = anchorHref;
    anchorReferrerKeyByMedia.set(media, referrerKey);
    media.style.outline = `4px solid ${BORDER_COLOR_MISS}`;
    media.style.outlineOffset = '-4px';
    media.setAttribute(ANCHOR_MEDIA_BORDER_ATTR, '1');
    media.setAttribute(ANCHOR_MEDIA_MATCH_ATTR, '0');

    void enqueueReferrerCheck(anchorHref).then((result) => {
        if (!media.isConnected) {
            return;
        }

        if (anchorReferrerKeyByMedia.get(media) !== referrerKey) {
            return;
        }

        const isMatch = result.exists === true;
        media.style.outline = `4px solid ${isMatch ? BORDER_COLOR_MATCH : BORDER_COLOR_MISS}`;
        media.style.outlineOffset = '-4px';
        media.setAttribute(ANCHOR_MEDIA_BORDER_ATTR, '1');
        media.setAttribute(ANCHOR_MEDIA_MATCH_ATTR, isMatch ? '1' : '0');

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

function installViewportListeners(): void {
    window.addEventListener('scroll', scheduleReposition, { passive: true });
    window.addEventListener('resize', scheduleReposition, { passive: true });
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
    installMutationObserver();
    installStorageListener();
    installViewportListeners();
    void loadRulesAndProcess();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
} else {
    bootstrap();
}
