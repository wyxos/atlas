import { getStoredOptions } from './atlas-options';
import { DEFAULT_MATCH_RULES, urlMatchesAnyRule, type UrlMatchRule } from './match-rules';
import { collectMediaFromNode, isMediaElement, normalizeUrl, resolveMediaUrl, type MediaElement } from './content/media-utils';
import { OverlayManager } from './content/overlay-manager';

const OBSERVED_ATTRS = ['src', 'srcset', 'poster'] as const;

let currentRules: UrlMatchRule[] = [...DEFAULT_MATCH_RULES];
let currentPageHostname = window.location.hostname;
const overlayManager = new OverlayManager();

function mediaMatchesRules(element: MediaElement): boolean {
    if (currentRules.length === 0) {
        return false;
    }

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
                processSourceMutation(mutation.target);
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
