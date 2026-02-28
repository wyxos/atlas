import { getStoredOptions } from './atlas-options';
import { DEFAULT_MATCH_RULES, urlMatchesAnyRule, type UrlMatchRule } from './match-rules';

const WRAPPER_ATTR = 'data-atlas-media-wrapper';
const BADGE_ATTR = 'data-atlas-media-red-badge';
const APPLIED_ATTR = 'data-atlas-media-red-applied';
const OBSERVED_ATTRS = ['src', 'srcset', 'poster'] as const;

type MediaElement = HTMLImageElement | HTMLVideoElement;
type OverlayTarget = MediaElement | HTMLPictureElement;

let currentRules: UrlMatchRule[] = [...DEFAULT_MATCH_RULES];
let currentPageHostname = window.location.hostname;

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

function resolveOverlayTarget(media: MediaElement): OverlayTarget {
    if (media.parentElement instanceof HTMLPictureElement) {
        return media.parentElement;
    }

    return media;
}

function createWrapper(target: OverlayTarget): HTMLDivElement {
    const wrapper = document.createElement('div');
    wrapper.setAttribute(WRAPPER_ATTR, '1');
    wrapper.style.position = 'relative';
    wrapper.style.display = window.getComputedStyle(target).display === 'block' ? 'block' : 'inline-block';
    return wrapper;
}

function ensureWrapper(target: OverlayTarget): HTMLDivElement {
    const parent = target.parentElement;
    if (parent instanceof HTMLDivElement && parent.getAttribute(WRAPPER_ATTR) === '1') {
        return parent;
    }

    const wrapper = createWrapper(target);
    if (parent) {
        parent.insertBefore(wrapper, target);
        wrapper.appendChild(target);
    }

    return wrapper;
}

function ensureBadge(wrapper: HTMLDivElement): HTMLDivElement {
    const existing = wrapper.querySelector<HTMLDivElement>(`[${BADGE_ATTR}="1"]`);
    if (existing) {
        return existing;
    }

    const badge = document.createElement('div');
    badge.setAttribute(BADGE_ATTR, '1');
    badge.style.position = 'absolute';
    badge.style.left = '50%';
    badge.style.bottom = '8px';
    badge.style.transform = 'translateX(-50%)';
    badge.style.width = '320px';
    badge.style.height = '40px';
    badge.style.background = '#dc2626';
    badge.style.border = '2px solid #ef4444';
    badge.style.borderRadius = '8px';
    badge.style.boxSizing = 'border-box';
    badge.style.pointerEvents = 'none';
    badge.style.zIndex = '2147483647';

    wrapper.appendChild(badge);
    return badge;
}

function unwrapTarget(target: OverlayTarget, media: MediaElement): void {
    const wrapper = target.parentElement;
    if (!(wrapper instanceof HTMLDivElement) || wrapper.getAttribute(WRAPPER_ATTR) !== '1') {
        media.removeAttribute(APPLIED_ATTR);
        return;
    }

    const parent = wrapper.parentElement;
    if (!parent) {
        media.removeAttribute(APPLIED_ATTR);
        return;
    }

    const wrapperNextSibling = wrapper.nextSibling;
    wrapper.remove();
    parent.insertBefore(target, wrapperNextSibling);
    media.removeAttribute(APPLIED_ATTR);
}

function applyIndicator(media: MediaElement): void {
    const target = resolveOverlayTarget(media);
    const wrapper = ensureWrapper(target);
    ensureBadge(wrapper);
    media.setAttribute(APPLIED_ATTR, '1');
}

function processMedia(media: MediaElement): void {
    if (media.closest('a[href]') !== null) {
        const anchoredTarget = resolveOverlayTarget(media);
        unwrapTarget(anchoredTarget, media);
        return;
    }

    if (mediaMatchesRules(media)) {
        applyIndicator(media);
        return;
    }

    const target = resolveOverlayTarget(media);
    unwrapTarget(target, media);
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
            }

            if (mutation.type === 'attributes' && mutation.target instanceof Element && isMediaElement(mutation.target)) {
                processMedia(mutation.target);
            }

            if (mutation.type === 'attributes' && mutation.target instanceof HTMLSourceElement) {
                const pictureParent = mutation.target.closest('picture');
                const pictureImg = pictureParent?.querySelector('img');
                if (pictureImg && isMediaElement(pictureImg)) {
                    processMedia(pictureImg);
                    continue;
                }

                const videoParent = mutation.target.closest('video');
                if (videoParent && isMediaElement(videoParent)) {
                    processMedia(videoParent);
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
    void loadRulesAndProcess();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
} else {
    bootstrap();
}
