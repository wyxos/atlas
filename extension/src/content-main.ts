import { getStoredOptions } from './atlas-options';
import { DEFAULT_MATCH_RULES, urlMatchesAnyRule, type UrlMatchRule } from './match-rules';
import {
    collectMediaFromNode,
    isMediaElement,
    normalizeUrl,
    resolveMediaResolution,
    resolveReactionTargetUrl,
    shouldExcludeMediaOrAnchorUrl,
    type MediaElement,
} from './content/media-utils';
import { OverlayManager } from './content/overlay-manager';
import { createAnchorMediaRuntime } from './content/anchor-media-runtime';
import { subscribeToDownloadProgress } from './content/download-progress-bus';
import { createDownloadEventSheet } from './content/download-event-sheet';

const OBSERVED_ATTRS = ['src', 'srcset', 'poster'] as const;
const MEDIA_WIDGET_APPLIED_ATTR = 'data-atlas-media-red-applied';
const MIN_WIDGET_MEDIA_WIDTH = 200;

let currentRules: UrlMatchRule[] = [...DEFAULT_MATCH_RULES];
let currentPageHostname = window.location.hostname;
const overlayManager = new OverlayManager();
const downloadEventSheet = createDownloadEventSheet();
const anchorMediaRuntime = createAnchorMediaRuntime({
    getRules: () => currentRules,
    getPageHostname: () => currentPageHostname,
});

function mediaMatchesRules(element: MediaElement): boolean {
    const pageUrl = normalizeUrl(window.location.href);
    const mediaUrl = resolveReactionTargetUrl(element, pageUrl);
    if (mediaUrl === null || shouldExcludeMediaOrAnchorUrl(mediaUrl)) {
        return false;
    }

    return urlMatchesAnyRule(mediaUrl, currentRules, currentPageHostname);
}

function mediaHasEligibleWidgetWidth(element: MediaElement): boolean {
    if (element instanceof HTMLVideoElement) {
        return true;
    }

    const resolution = resolveMediaResolution(element);
    if (resolution === null) {
        // Dimensions are unknown until media metadata is available.
        return true;
    }

    return resolution.width > MIN_WIDGET_MEDIA_WIDTH;
}

function processMedia(media: MediaElement): void {
    if (media.closest('a[href]') !== null) {
        overlayManager.remove(media);
        return;
    }

    if (mediaMatchesRules(media) && mediaHasEligibleWidgetWidth(media)) {
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
    for (const mediaElement of document.querySelectorAll('img,video')) {
        if (isMediaElement(mediaElement)) {
            processMedia(mediaElement);
        }
    }
}

function resolveMediaCandidateFromInteraction(event: MouseEvent): MediaElement | null {
    const target = event.target;
    if (target instanceof Element) {
        const fromTarget = target.closest('img,video');
        if (fromTarget && isMediaElement(fromTarget)) {
            return fromTarget;
        }
    }

    for (const element of document.elementsFromPoint(event.clientX, event.clientY)) {
        if (isMediaElement(element)) {
            return element;
        }
    }

    return null;
}

function tryApplyMediaWidgetFromInteraction(event: MouseEvent): void {
    const mediaCandidate = resolveMediaCandidateFromInteraction(event);
    if (!mediaCandidate || mediaCandidate.closest('a[href]') !== null) {
        return;
    }

    if (mediaCandidate.getAttribute(MEDIA_WIDGET_APPLIED_ATTR) === '1' || !mediaMatchesRules(mediaCandidate)) {
        return;
    }

    overlayManager.apply(mediaCandidate);
}

function installMutationObserver(): void {
    const observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            if (mutation.type === 'childList') {
                for (const addedNode of mutation.addedNodes) {
                    processNodeAndDescendants(addedNode);
                    anchorMediaRuntime.registerFromNode(addedNode);
                }
                scheduleReposition();
            }

            if (mutation.type === 'attributes' && mutation.target instanceof Element && isMediaElement(mutation.target)) {
                processMedia(mutation.target);
                anchorMediaRuntime.registerFromNode(mutation.target);
                scheduleReposition();
            }

            if (mutation.type === 'attributes' && mutation.target instanceof HTMLAnchorElement) {
                anchorMediaRuntime.registerFromNode(mutation.target);
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

        if ((message as { type?: unknown }).type === 'ATLAS_TAB_PRESENCE_CHANGED') {
            anchorMediaRuntime.handleTabPresenceChanged((message as { urls?: unknown }).urls);
        }
    });
}

function installDownloadProgressListener(): void {
    subscribeToDownloadProgress((event) => {
        downloadEventSheet.push(event);
        anchorMediaRuntime.handleDownloadProgressEvent(event);
    });
}

function installViewportListeners(): void {
    window.addEventListener('scroll', scheduleReposition, { passive: true });
    window.addEventListener('resize', scheduleReposition, { passive: true });
}

function installInteractionFallbackListeners(): void {
    const handleInteraction = (event: MouseEvent): void => {
        tryApplyMediaWidgetFromInteraction(event);
    };

    document.addEventListener('mouseover', handleInteraction, { passive: true });
    document.addEventListener('mouseup', handleInteraction, { passive: true });
}

function installMediaDimensionListeners(): void {
    const handleMediaDimensionResolved = (event: Event): void => {
        const target = event.target;
        if (!(target instanceof Element) || !isMediaElement(target)) {
            return;
        }

        processMedia(target);
        scheduleReposition();
    };

    document.addEventListener('load', handleMediaDimensionResolved, true);
    document.addEventListener('loadedmetadata', handleMediaDimensionResolved, true);
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
    anchorMediaRuntime.registerFromDocument();
}

function bootstrap(): void {
    installMutationObserver();
    installStorageListener();
    installRuntimeMessageListener();
    installDownloadProgressListener();
    installViewportListeners();
    installInteractionFallbackListeners();
    installMediaDimensionListeners();
    void loadRulesAndProcess();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
} else {
    bootstrap();
}
