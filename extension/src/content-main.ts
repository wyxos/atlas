import { getStoredOptions } from './atlas-options';
import { DEFAULT_MATCH_RULES, urlMatchesAnyRule, type UrlMatchRule } from './match-rules';

const BADGE_ATTR = 'data-atlas-media-red-badge';
const APPLIED_ATTR = 'data-atlas-media-red-applied';
const OBSERVED_ATTRS = ['src', 'srcset', 'poster'] as const;

type MediaElement = HTMLImageElement | HTMLVideoElement;

let currentRules: UrlMatchRule[] = [...DEFAULT_MATCH_RULES];
let currentPageHostname = window.location.hostname;
const badgesByMedia = new WeakMap<MediaElement, HTMLDivElement>();
const activeMedia = new Set<MediaElement>();
let repositionQueued = false;

function isMediaElement(element: Element): element is MediaElement {
    return element instanceof HTMLImageElement || element instanceof HTMLVideoElement;
}

function resolveMediaUrl(element: MediaElement): string | null {
    if (element instanceof HTMLImageElement) {
        return element.currentSrc || element.src || element.getAttribute('src') || null;
    }

    return element.currentSrc || element.src || element.poster || element.getAttribute('src') || null;
}

function normalizeUrl(value: string | null | undefined): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }

    if (!/^https?:\/\//i.test(trimmed)) {
        return null;
    }

    return trimmed.replace(/#.*$/, '');
}

function mediaMatchesRules(element: MediaElement): boolean {
    if (currentRules.length === 0) {
        return false;
    }

    const mediaUrl = normalizeUrl(resolveMediaUrl(element));
    return mediaUrl !== null && urlMatchesAnyRule(mediaUrl, currentRules, currentPageHostname);
}

function ensureBadge(media: MediaElement): HTMLDivElement {
    const existing = badgesByMedia.get(media);
    if (existing) {
        return existing;
    }

    const badge = document.createElement('div');
    badge.setAttribute(BADGE_ATTR, '1');
    badge.style.position = 'absolute';
    badge.style.width = '320px';
    badge.style.height = '40px';
    badge.style.background = '#dc2626';
    badge.style.border = '2px solid #ef4444';
    badge.style.borderRadius = '8px';
    badge.style.boxSizing = 'border-box';
    badge.style.pointerEvents = 'none';
    badge.style.zIndex = '2147483647';
    document.body.appendChild(badge);
    badgesByMedia.set(media, badge);
    return badge;
}

function removeIndicator(media: MediaElement): void {
    activeMedia.delete(media);
    const badge = badgesByMedia.get(media);
    if (badge) {
        badge.remove();
        badgesByMedia.delete(media);
    }
    media.removeAttribute(APPLIED_ATTR);
}

function positionBadge(media: MediaElement): void {
    const badge = ensureBadge(media);
    const rect = media.getBoundingClientRect();
    const style = window.getComputedStyle(media);
    const isHidden = style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) === 0;
    const hasSize = rect.width > 0 && rect.height > 0;
    const isOnScreen = rect.bottom >= 0 && rect.right >= 0 && rect.top <= window.innerHeight && rect.left <= window.innerWidth;

    if (isHidden || !hasSize || !isOnScreen) {
        badge.style.display = 'none';
        return;
    }

    const left = window.scrollX + rect.left + ((rect.width - 320) / 2);
    const top = window.scrollY + rect.top + rect.height - 48;
    badge.style.display = 'block';
    badge.style.left = `${Math.round(left)}px`;
    badge.style.top = `${Math.round(top)}px`;
}

function scheduleReposition(): void {
    if (repositionQueued) {
        return;
    }

    repositionQueued = true;
    window.requestAnimationFrame(() => {
        repositionQueued = false;

        for (const media of Array.from(activeMedia)) {
            if (!media.isConnected) {
                removeIndicator(media);
                continue;
            }

            positionBadge(media);
        }
    });
}

function applyIndicator(media: MediaElement): void {
    activeMedia.add(media);
    media.setAttribute(APPLIED_ATTR, '1');
    positionBadge(media);
}

function processMedia(media: MediaElement): void {
    if (media.closest('a[href]') !== null) {
        removeIndicator(media);
        return;
    }

    if (mediaMatchesRules(media)) {
        applyIndicator(media);
        return;
    }

    removeIndicator(media);
}

function processNodeAndDescendants(node: Node): void {
    if (node instanceof Element && isMediaElement(node)) {
        processMedia(node);
    }

    if (!(node instanceof Element)) {
        return;
    }

    const mediaElements = node.querySelectorAll('img,video');
    for (const mediaElement of mediaElements) {
        if (isMediaElement(mediaElement)) {
            processMedia(mediaElement);
        }
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

function installMutationObserver(): void {
    const observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            if (mutation.type === 'childList') {
                for (const addedNode of mutation.addedNodes) {
                    processNodeAndDescendants(addedNode);
                }
                scheduleReposition();
            }

            if (mutation.type === 'attributes' && mutation.target instanceof Element && isMediaElement(mutation.target)) {
                processMedia(mutation.target);
                scheduleReposition();
            }

            if (mutation.type === 'attributes' && mutation.target instanceof HTMLSourceElement) {
                const pictureParent = mutation.target.closest('picture');
                const pictureImg = pictureParent?.querySelector('img');
                if (pictureImg && isMediaElement(pictureImg)) {
                    processMedia(pictureImg);
                    scheduleReposition();
                    continue;
                }

                const videoParent = mutation.target.closest('video');
                if (videoParent && isMediaElement(videoParent)) {
                    processMedia(videoParent);
                    scheduleReposition();
                }
            }
        }
    });

    observer.observe(document.documentElement, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: [...OBSERVED_ATTRS],
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
