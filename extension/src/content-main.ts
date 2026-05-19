import { getStoredOptions } from './atlas-options';
import type { UrlMatchRule } from './match-rules';
import {
    createCustomizationMatchRules,
    resolveSiteCustomizationForHostname,
    type SiteCustomization,
} from './site-customizations';
import { setActivePageSiteCustomization } from './page-customization-state';
import {
    collectOpenShadowRootsFromNode,
    collectMediaFromNode,
    isMediaElement,
    resolveMediaResolution,
    type MediaElement,
} from './content/media-utils';
import { mediaMatchesRulesForPage } from './content/media-rule-match';
import { OverlayManager } from './content/overlay-manager';
import { createAnchorMediaRuntime } from './content/anchor-media-runtime';
import { subscribeToDownloadProgress } from './content/download-progress-bus';
import { createDownloadEventSheet } from './content/download-event-sheet';
import { createDuplicateAnchorTabGuard } from './content/duplicate-anchor-tab-guard';
import { clearDeviantArtBackgroundImageStyle } from './content/deviantart-background-image-style';
import { installCivitAiModelBrowseCtas } from './content/civitai-model-browse-cta';
import { installCivitAiUserBrowseLinks } from './content/civitai-user-browse-link';

const OBSERVED_ATTRS = ['src', 'srcset', 'poster'] as const;
const MEDIA_WIDGET_APPLIED_ATTR = 'data-atlas-media-red-applied';
const MIN_WIDGET_MEDIA_WIDTH = 200;
const VISIBLE_RESUME_SCAN_LIMIT = 100;

type LoadRulesAndProcessOptions = {
    fullScan?: boolean;
};

let currentSiteCustomization: SiteCustomization | null = null;
let currentRules: UrlMatchRule[] = [];
let currentReferrerCleanerQueryParams: string[] = [];
let currentPageHostname = window.location.hostname;
let isCurrentSiteEnabled = false;
const overlayManager = new OverlayManager();
const downloadEventSheet = createDownloadEventSheet();
const anchorMediaRuntime = createAnchorMediaRuntime({
    getIsEnabled: () => isCurrentSiteEnabled,
    getRules: () => currentRules,
    getReferrerCleanerQueryParams: () => currentReferrerCleanerQueryParams,
    getPageHostname: () => currentPageHostname,
});
let duplicateAnchorTabGuard: ReturnType<typeof createDuplicateAnchorTabGuard> | null = null;
let mainMutationObserver: MutationObserver | null = null;
const shadowRootMutationObservers = new Map<ShadowRoot, MutationObserver>();
let unsubscribeDownloadProgress: (() => void) | null = null;
let isPageWorkActive = false;
let pageEnhancementsInstalled = false;
let pageEnhancementCleanups: Array<() => void> = [];
let viewportListenersInstalled = false;
let interactionFallbackListenersInstalled = false;
let mediaDimensionListenersInstalled = false;
let visiblePageWorkFrame: number | null = null;

function isPageVisible(): boolean {
    return document.visibilityState !== 'hidden';
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

function mediaMatchesRules(element: MediaElement): boolean {
    if (!isCurrentSiteEnabled) {
        return false;
    }

    return mediaMatchesRulesForPage(element, window.location.href, currentRules, currentPageHostname);
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

function collectKnownMediaElements(): MediaElement[] {
    const media: MediaElement[] = [];
    const seen = new WeakSet<MediaElement>();
    const addMedia = (element: Element): void => {
        if (!isMediaElement(element) || seen.has(element)) {
            return;
        }

        seen.add(element);
        media.push(element);
    };

    for (const mediaElement of document.querySelectorAll('img,video')) {
        addMedia(mediaElement);
    }

    for (const shadowRoot of shadowRootMutationObservers.keys()) {
        for (const mediaElement of collectMediaFromNode(shadowRoot)) {
            addMedia(mediaElement);
        }
    }

    return media;
}

function processAllCurrentMedia(): void {
    observeOpenShadowRootsFromNode(document);
    for (const mediaElement of collectKnownMediaElements()) {
        processMedia(mediaElement);
    }
}

function processVisibleCurrentMedia(limit = VISIBLE_RESUME_SCAN_LIMIT): void {
    let processedCount = 0;
    for (const mediaElement of collectKnownMediaElements()) {
        if (processedCount >= limit) {
            return;
        }

        if (!isVisibleInViewport(mediaElement)) {
            continue;
        }

        processMedia(mediaElement);
        processedCount += 1;
    }
}

function processVisiblePageWork(): void {
    processVisibleCurrentMedia();
    anchorMediaRuntime.registerVisibleFromDocument(VISIBLE_RESUME_SCAN_LIMIT);
}

function scheduleVisiblePageWork(): void {
    if (!isPageWorkActive || visiblePageWorkFrame !== null) {
        return;
    }

    visiblePageWorkFrame = window.requestAnimationFrame(() => {
        visiblePageWorkFrame = null;
        if (isPageWorkActive) {
            processVisiblePageWork();
        }
    });
}

function cancelVisiblePageWork(): void {
    if (visiblePageWorkFrame === null) {
        return;
    }

    window.cancelAnimationFrame(visiblePageWorkFrame);
    visiblePageWorkFrame = null;
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
    if (!isCurrentSiteEnabled) {
        return;
    }

    const mediaCandidate = resolveMediaCandidateFromInteraction(event);
    if (!mediaCandidate || mediaCandidate.closest('a[href]') !== null) {
        return;
    }

    if (mediaCandidate.getAttribute(MEDIA_WIDGET_APPLIED_ATTR) === '1' || !mediaMatchesRules(mediaCandidate)) {
        return;
    }

    overlayManager.apply(mediaCandidate);
}

function handlePageMutations(mutations: MutationRecord[]): void {
    if (!isPageWorkActive) {
        return;
    }

    for (const mutation of mutations) {
        if (mutation.type === 'childList') {
            for (const addedNode of mutation.addedNodes) {
                clearDeviantArtBackgroundImageStyle(addedNode);
                observeOpenShadowRootsFromNode(addedNode);
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
}

function observeOpenShadowRootsFromNode(node: Node): void {
    for (const shadowRoot of collectOpenShadowRootsFromNode(node)) {
        if (shadowRootMutationObservers.has(shadowRoot)) {
            continue;
        }

        const observer = new MutationObserver(handlePageMutations);
        shadowRootMutationObservers.set(shadowRoot, observer);
        observer.observe(shadowRoot, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: [...OBSERVED_ATTRS, 'href'],
        });
        processNodeAndDescendants(shadowRoot);
        anchorMediaRuntime.registerFromNode(shadowRoot);
    }
}

function installMutationObserver(): void {
    if (mainMutationObserver !== null) {
        return;
    }

    const observer = new MutationObserver(handlePageMutations);

    mainMutationObserver = observer;
    observer.observe(document.documentElement, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: [...OBSERVED_ATTRS, 'href'],
    });
    observeOpenShadowRootsFromNode(document);
}

function disconnectMutationObserver(): void {
    mainMutationObserver?.disconnect();
    mainMutationObserver = null;
    for (const observer of shadowRootMutationObservers.values()) {
        observer.disconnect();
    }
    shadowRootMutationObservers.clear();
}

function installStorageListener(): void {
    if (!chrome.storage?.onChanged) {
        return;
    }

    chrome.storage.onChanged.addListener(() => {
        if (isPageWorkActive) {
            void loadRulesAndProcess();
        }
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
            if (!isPageWorkActive) {
                return;
            }

            anchorMediaRuntime.handleTabPresenceChanged((message as { urls?: unknown }).urls);
            duplicateAnchorTabGuard?.handleTabPresenceChanged(message);
            return;
        }

        if ((message as { type?: unknown }).type === 'ATLAS_REFERRER_REACTION_SYNC') {
            if (!isPageWorkActive) {
                return;
            }

            anchorMediaRuntime.handleReferrerReactionSync(message);
        }
    });
}

function connectDownloadProgressListener(): void {
    if (unsubscribeDownloadProgress !== null) {
        return;
    }

    unsubscribeDownloadProgress = subscribeToDownloadProgress((event) => {
        if (!isPageWorkActive) {
            return;
        }

        downloadEventSheet.push(event);
        anchorMediaRuntime.handleDownloadProgressEvent(event);
    });
}

function disconnectDownloadProgressListener(): void {
    unsubscribeDownloadProgress?.();
    unsubscribeDownloadProgress = null;
}

const handleViewportUpdate = (): void => {
    if (!isPageWorkActive) {
        return;
    }

    scheduleReposition();
    scheduleVisiblePageWork();
};

function installViewportListeners(): void {
    if (viewportListenersInstalled) {
        return;
    }

    window.addEventListener('scroll', handleViewportUpdate, { passive: true });
    window.addEventListener('resize', handleViewportUpdate, { passive: true });
    viewportListenersInstalled = true;
}

function removeViewportListeners(): void {
    if (!viewportListenersInstalled) {
        return;
    }

    window.removeEventListener('scroll', handleViewportUpdate);
    window.removeEventListener('resize', handleViewportUpdate);
    viewportListenersInstalled = false;
}

const handleInteraction = (event: MouseEvent): void => {
    if (!isPageWorkActive) {
        return;
    }

    tryApplyMediaWidgetFromInteraction(event);
};

function installInteractionFallbackListeners(): void {
    if (interactionFallbackListenersInstalled) {
        return;
    }

    document.addEventListener('mouseover', handleInteraction, { passive: true });
    document.addEventListener('mouseup', handleInteraction, { passive: true });
    interactionFallbackListenersInstalled = true;
}

function removeInteractionFallbackListeners(): void {
    if (!interactionFallbackListenersInstalled) {
        return;
    }

    document.removeEventListener('mouseover', handleInteraction);
    document.removeEventListener('mouseup', handleInteraction);
    interactionFallbackListenersInstalled = false;
}

const handleMediaDimensionResolved = (event: Event): void => {
    if (!isPageWorkActive) {
        return;
    }

    const target = event.target;
    if (!(target instanceof Element) || !isMediaElement(target)) {
        return;
    }

    processMedia(target);
    scheduleReposition();
};

function installMediaDimensionListeners(): void {
    if (mediaDimensionListenersInstalled) {
        return;
    }

    document.addEventListener('load', handleMediaDimensionResolved, true);
    document.addEventListener('loadedmetadata', handleMediaDimensionResolved, true);
    mediaDimensionListenersInstalled = true;
}

function removeMediaDimensionListeners(): void {
    if (!mediaDimensionListenersInstalled) {
        return;
    }

    document.removeEventListener('load', handleMediaDimensionResolved, true);
    document.removeEventListener('loadedmetadata', handleMediaDimensionResolved, true);
    mediaDimensionListenersInstalled = false;
}

function installPageEnhancements(): void {
    if (pageEnhancementsInstalled) {
        return;
    }

    pageEnhancementsInstalled = true;
    clearDeviantArtBackgroundImageStyle();
    const cleanups = [
        installCivitAiModelBrowseCtas(),
        installCivitAiUserBrowseLinks(),
    ].filter((cleanup): cleanup is () => void => typeof cleanup === 'function');
    pageEnhancementCleanups.push(...cleanups);
}

function cleanupPageEnhancements(): void {
    pageEnhancementCleanups.forEach((cleanup) => {
        cleanup();
    });
    pageEnhancementCleanups = [];
    pageEnhancementsInstalled = false;
}

async function loadRulesAndProcess(options: LoadRulesAndProcessOptions = {}): Promise<void> {
    try {
        const stored = await getStoredOptions();
        currentPageHostname = window.location.hostname;
        currentSiteCustomization = resolveSiteCustomizationForHostname(stored.siteCustomizations, currentPageHostname);
        isCurrentSiteEnabled = currentSiteCustomization !== null;
        currentRules = currentSiteCustomization ? createCustomizationMatchRules(currentSiteCustomization) : [];
        currentReferrerCleanerQueryParams = currentSiteCustomization?.referrerCleaner.stripQueryParams ?? [];
    } catch {
        currentPageHostname = window.location.hostname;
        currentSiteCustomization = null;
        isCurrentSiteEnabled = false;
        currentRules = [];
        currentReferrerCleanerQueryParams = [];
    }

    setActivePageSiteCustomization(currentSiteCustomization);
    if (!isPageWorkActive) {
        return;
    }

    if (options.fullScan ?? true) {
        processAllCurrentMedia();
        anchorMediaRuntime.registerFromDocument();
        return;
    }

    processVisiblePageWork();
}

function startPageWork(options: { fullScan?: boolean } = {}): void {
    if (isPageWorkActive) {
        return;
    }

    isPageWorkActive = true;
    duplicateAnchorTabGuard ??= createDuplicateAnchorTabGuard();
    installPageEnhancements();
    installMutationObserver();
    connectDownloadProgressListener();
    installViewportListeners();
    installInteractionFallbackListeners();
    installMediaDimensionListeners();
    anchorMediaRuntime.resume();
    void loadRulesAndProcess({ fullScan: options.fullScan ?? true });
}

function stopPageWork(): void {
    if (!isPageWorkActive) {
        return;
    }

    isPageWorkActive = false;
    disconnectMutationObserver();
    disconnectDownloadProgressListener();
    removeViewportListeners();
    removeInteractionFallbackListeners();
    removeMediaDimensionListeners();
    cancelVisiblePageWork();
    cleanupPageEnhancements();
    duplicateAnchorTabGuard?.destroy();
    duplicateAnchorTabGuard = null;
    anchorMediaRuntime.suspend();
}

function handleVisibilityChange(): void {
    if (isPageVisible()) {
        startPageWork({ fullScan: false });
        return;
    }

    stopPageWork();
}

function installVisibilityListener(): void {
    document.addEventListener('visibilitychange', handleVisibilityChange);
}

function bootstrap(): void {
    installStorageListener();
    installRuntimeMessageListener();
    installVisibilityListener();

    if (isPageVisible()) {
        startPageWork({ fullScan: true });
    } else {
        anchorMediaRuntime.suspend();
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
} else {
    bootstrap();
}
