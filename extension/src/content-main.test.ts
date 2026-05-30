import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetStoredOptions = vi.fn();
const mockResolveSiteCustomizationForHostname = vi.fn();
const mockCreateCustomizationMatchRules = vi.fn();
const mockSetActivePageSiteCustomization = vi.fn();
const mockResolveMediaResolution = vi.fn();
const mockCollectOpenShadowRootsFromNode = vi.fn((node: Node): ShadowRoot[] => {
    if (
        !(node instanceof Element)
        && !(node instanceof Document)
        && !(node instanceof DocumentFragment)
    ) {
        return [];
    }

    const roots: ShadowRoot[] = [];
    const collect = (element: Element): void => {
        if (element.shadowRoot !== null) {
            roots.push(element.shadowRoot);
        }
    };

    if (node instanceof Element) {
        collect(node);
    }

    for (const element of node.querySelectorAll('*')) {
        collect(element);
    }

    return roots;
});
const mockMediaMatchesRulesForPage = vi.fn();
const mockOverlayApply = vi.fn();
const mockOverlayRemove = vi.fn();
const mockOverlayScheduleReposition = vi.fn();
const mockOverlayRefreshVisibleChecks = vi.fn();
const mockAnchorRuntime = {
    handleDownloadProgressEvent: vi.fn(),
    handleReferrerReactionSync: vi.fn(),
    handleTabPresenceChanged: vi.fn(),
    registerFromDocument: vi.fn(),
    registerFromNode: vi.fn(),
    registerVisibleFromDocument: vi.fn(),
    resume: vi.fn(),
    suspend: vi.fn(),
};
const mockSubscribeToDownloadProgress = vi.fn();
const mockUnsubscribeDownloadProgress = vi.fn();
const mockDownloadEventSheetPush = vi.fn();
const mockDuplicateAnchorTabGuard = {
    destroy: vi.fn(),
    handleTabPresenceChanged: vi.fn(),
    refreshSnapshot: vi.fn(),
};
const mockInstallCivitAiModelBrowseCtas = vi.fn();
const mockCleanupCivitAiModelBrowseCtas = vi.fn();
const mockInstallCivitAiUserBrowseLinks = vi.fn();
const mockCleanupCivitAiUserBrowseLinks = vi.fn();
const mockClearDeviantArtBackgroundImageStyle = vi.fn();
const mockMutationObserverObserve = vi.fn();
const mockMutationObserverDisconnect = vi.fn();
const mockRequestAnimationFrame = vi.fn();
const mockCancelAnimationFrame = vi.fn();

let mutationObserverCallback: MutationCallback | null = null;
let progressListener: ((event: Record<string, unknown>) => void) | null = null;
let runtimeMessageListener: ((message: unknown) => void) | null = null;
let storageChangeListener: (() => void) | null = null;

vi.mock('./atlas-options', () => ({
    getStoredOptions: mockGetStoredOptions,
}));

vi.mock('./site-customizations', () => ({
    createCustomizationMatchRules: mockCreateCustomizationMatchRules,
    resolveSiteCustomizationForHostname: mockResolveSiteCustomizationForHostname,
}));

vi.mock('./page-customization-state', () => ({
    setActivePageSiteCustomization: mockSetActivePageSiteCustomization,
}));

vi.mock('./content/media-utils', () => ({
    collectOpenShadowRootsFromNode: mockCollectOpenShadowRootsFromNode,
    collectMediaFromNode: (node: Node) => {
        const media: Array<HTMLImageElement | HTMLVideoElement> = [];
        const seen = new WeakSet<HTMLImageElement | HTMLVideoElement>();

        const collectFromRoot = (root: Element | Document | DocumentFragment): void => {
            if ((root instanceof HTMLImageElement || root instanceof HTMLVideoElement) && !seen.has(root)) {
                seen.add(root);
                media.push(root);
            }

            for (const element of root.querySelectorAll('img,video')) {
                if ((element instanceof HTMLImageElement || element instanceof HTMLVideoElement) && !seen.has(element)) {
                    seen.add(element);
                    media.push(element);
                }
            }

            for (const shadowRoot of mockCollectOpenShadowRootsFromNode(root)) {
                collectFromRoot(shadowRoot);
            }
        };

        if (
            node instanceof Element
            || node instanceof Document
            || node instanceof DocumentFragment
        ) {
            collectFromRoot(node);
        }

        return media;
    },
    isMediaElement: (value: unknown) => value instanceof HTMLImageElement || value instanceof HTMLVideoElement,
    resolveMediaResolution: mockResolveMediaResolution,
}));

vi.mock('./content/media-rule-match', () => ({
    mediaMatchesRulesForPage: mockMediaMatchesRulesForPage,
}));

vi.mock('./content/overlay-manager', () => ({
    OverlayManager: class MockOverlayManager {
        apply = mockOverlayApply;
        remove = mockOverlayRemove;
        scheduleReposition = mockOverlayScheduleReposition;
        refreshVisibleChecks = mockOverlayRefreshVisibleChecks;
    },
}));

vi.mock('./content/anchor-media-runtime', () => ({
    createAnchorMediaRuntime: vi.fn(() => mockAnchorRuntime),
}));

vi.mock('./content/download-progress-bus', () => ({
    subscribeToDownloadProgress: mockSubscribeToDownloadProgress,
}));

vi.mock('./content/download-event-sheet', () => ({
    createDownloadEventSheet: vi.fn(() => ({
        push: mockDownloadEventSheetPush,
    })),
}));

vi.mock('./content/duplicate-anchor-tab-guard', () => ({
    createDuplicateAnchorTabGuard: vi.fn(() => mockDuplicateAnchorTabGuard),
}));

vi.mock('./content/deviantart-background-image-style', () => ({
    clearDeviantArtBackgroundImageStyle: mockClearDeviantArtBackgroundImageStyle,
}));

vi.mock('./content/civitai-model-browse-cta', () => ({
    installCivitAiModelBrowseCtas: mockInstallCivitAiModelBrowseCtas,
}));

vi.mock('./content/civitai-user-browse-link', () => ({
    installCivitAiUserBrowseLinks: mockInstallCivitAiUserBrowseLinks,
}));

function flushPromises(): Promise<void> {
    return new Promise((resolve) => {
        setTimeout(resolve, 0);
    });
}

function setDocumentVisibility(value: DocumentVisibilityState): void {
    Object.defineProperty(document, 'visibilityState', {
        configurable: true,
        value,
    });
}

describe('content-main', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
        mutationObserverCallback = null;
        progressListener = null;
        runtimeMessageListener = null;
        storageChangeListener = null;

        Object.defineProperty(document, 'readyState', {
            configurable: true,
            value: 'complete',
        });
        Object.defineProperty(document, 'visibilityState', {
            configurable: true,
            value: 'visible',
        });

        class MockMutationObserver {
            constructor(callback: MutationCallback) {
                mutationObserverCallback = callback;
            }

            disconnect = mockMutationObserverDisconnect;

            observe = mockMutationObserverObserve;
        }

        vi.stubGlobal('MutationObserver', MockMutationObserver as unknown as typeof MutationObserver);
        mockRequestAnimationFrame.mockImplementation((callback: FrameRequestCallback) => {
            callback(0);
            return 1;
        });
        vi.stubGlobal('requestAnimationFrame', mockRequestAnimationFrame);
        vi.stubGlobal('cancelAnimationFrame', mockCancelAnimationFrame);
        vi.stubGlobal('chrome', {
            runtime: {
                onMessage: {
                    addListener: vi.fn((listener: (message: unknown) => void) => {
                        runtimeMessageListener = listener;
                    }),
                },
            },
            storage: {
                onChanged: {
                    addListener: vi.fn((listener: () => void) => {
                        storageChangeListener = listener;
                    }),
                },
            },
        });

        mockSubscribeToDownloadProgress.mockImplementation((listener: (event: Record<string, unknown>) => void) => {
            progressListener = listener;
            return mockUnsubscribeDownloadProgress;
        });
        mockInstallCivitAiModelBrowseCtas.mockReturnValue(mockCleanupCivitAiModelBrowseCtas);
        mockInstallCivitAiUserBrowseLinks.mockReturnValue(mockCleanupCivitAiUserBrowseLinks);
        mockResolveMediaResolution.mockReturnValue({
            width: 320,
            height: 240,
        });
        mockMediaMatchesRulesForPage.mockReturnValue(true);
    });

    it('bootstraps media widgets and forwards runtime, mutation, and download events', async () => {
        const customization = {
            domain: 'example.com',
            matchRules: ['.*'],
            referrerCleaner: {
                stripQueryParams: ['tag'],
            },
            mediaCleaner: {
                stripQueryParams: [],
                rewriteRules: [],
                strategies: [],
            },
        };

        mockGetStoredOptions.mockResolvedValue({
            siteCustomizations: [customization],
        });
        mockResolveSiteCustomizationForHostname.mockReturnValue(customization);
        mockCreateCustomizationMatchRules.mockReturnValue(['compiled-rule']);

        const standaloneImage = document.createElement('img');
        standaloneImage.src = 'https://cdn.example.com/standalone.jpg';
        document.body.appendChild(standaloneImage);

        const shadowHost = document.createElement('shreddit-player');
        const shadowRoot = shadowHost.attachShadow({ mode: 'open' });
        const shadowVideo = document.createElement('video');
        shadowVideo.src = 'https://v.redd.it/example/CMAF_720.mp4';
        shadowRoot.appendChild(shadowVideo);
        document.body.appendChild(shadowHost);

        const linkedAnchor = document.createElement('a');
        linkedAnchor.href = 'https://example.com/post';
        const linkedImage = document.createElement('img');
        linkedImage.src = 'https://cdn.example.com/linked.jpg';
        linkedAnchor.appendChild(linkedImage);
        document.body.appendChild(linkedAnchor);

        await import('./content-main');
        await flushPromises();
        await flushPromises();

        expect(mockSetActivePageSiteCustomization).toHaveBeenCalledWith(customization);
        expect(mockOverlayApply).toHaveBeenCalledWith(standaloneImage);
        expect(mockOverlayApply).toHaveBeenCalledWith(shadowVideo);
        expect(mockOverlayRemove).toHaveBeenCalledWith(linkedImage);
        expect(mockAnchorRuntime.registerFromDocument).toHaveBeenCalledTimes(1);
        expect(mockClearDeviantArtBackgroundImageStyle).toHaveBeenCalledTimes(1);
        expect(mockInstallCivitAiModelBrowseCtas).toHaveBeenCalledTimes(1);
        expect(mockInstallCivitAiUserBrowseLinks).toHaveBeenCalledTimes(1);
        expect(runtimeMessageListener).toBeTypeOf('function');
        expect(storageChangeListener).toBeTypeOf('function');
        expect(progressListener).toBeTypeOf('function');
        expect(mockMutationObserverObserve).toHaveBeenCalledTimes(2);

        runtimeMessageListener?.({
            type: 'ATLAS_TAB_PRESENCE_CHANGED',
            urls: ['https://example.com/post#image-1'],
        });

        expect(mockAnchorRuntime.handleTabPresenceChanged).toHaveBeenCalledWith(['https://example.com/post#image-1']);
        expect(mockDuplicateAnchorTabGuard.handleTabPresenceChanged).toHaveBeenCalledWith({
            type: 'ATLAS_TAB_PRESENCE_CHANGED',
            urls: ['https://example.com/post#image-1'],
        });

        runtimeMessageListener?.({
            type: 'ATLAS_REFERRER_REACTION_SYNC',
            phase: 'pending',
            urls: ['https://example.com/post#image-1'],
        });

        expect(mockAnchorRuntime.handleReferrerReactionSync).toHaveBeenCalledWith({
            type: 'ATLAS_REFERRER_REACTION_SYNC',
            phase: 'pending',
            urls: ['https://example.com/post#image-1'],
        });

        const progressEvent = {
            event: 'DownloadTransferQueued',
            transferId: 55,
            fileId: 12,
            status: 'queued',
            percent: 10,
            payload: {
                file_id: 12,
            },
        };
        progressListener?.(progressEvent);

        expect(mockDownloadEventSheetPush).toHaveBeenCalledWith(progressEvent);
        expect(mockAnchorRuntime.handleDownloadProgressEvent).toHaveBeenCalledWith(progressEvent);

        const addedImage = document.createElement('img');
        addedImage.src = 'https://cdn.example.com/added.jpg';
        mutationObserverCallback?.([
            {
                addedNodes: [addedImage],
                attributeName: null,
                oldValue: null,
                removedNodes: [],
                target: document.body,
                type: 'childList',
            } as MutationRecord,
        ], {} as MutationObserver);

        expect(mockClearDeviantArtBackgroundImageStyle).toHaveBeenCalledWith(addedImage);
        expect(mockOverlayApply).toHaveBeenCalledWith(addedImage);
        expect(mockAnchorRuntime.registerFromNode).toHaveBeenCalledWith(addedImage);
        expect(mockOverlayScheduleReposition).toHaveBeenCalled();

        storageChangeListener?.();
        await flushPromises();
        await flushPromises();

        expect(mockGetStoredOptions).toHaveBeenCalledTimes(2);
    });

    it('bypasses the badge check cache when the background marks the page as restored', async () => {
        const customization = {
            domain: 'example.com',
            matchRules: ['.*'],
            referrerCleaner: {
                stripQueryParams: [],
            },
        };
        mockGetStoredOptions.mockResolvedValue({
            siteCustomizations: [customization],
        });
        mockResolveSiteCustomizationForHostname.mockReturnValue(customization);
        mockCreateCustomizationMatchRules.mockReturnValue([{ pattern: '.*' }]);

        const runtimeSendMessage = vi.fn((message: unknown, callback?: (response: unknown) => void) => {
            const payload = message as { type?: string };
            if (payload.type === 'ATLAS_SHOULD_FORCE_BADGE_CHECK_ON_PAGE') {
                callback?.({ shouldForce: true });
                return;
            }

            callback?.(null);
        });

        vi.stubGlobal('chrome', {
            runtime: {
                lastError: null,
                sendMessage: runtimeSendMessage,
                onMessage: {
                    addListener: vi.fn((listener: (message: unknown) => void) => {
                        runtimeMessageListener = listener;
                    }),
                },
            },
            storage: {
                onChanged: {
                    addListener: vi.fn((listener: () => void) => {
                        storageChangeListener = listener;
                    }),
                },
            },
        });

        const image = document.createElement('img');
        image.src = 'https://cdn.example.com/restored.jpg';
        document.body.appendChild(image);

        await import('./content-main');
        await flushPromises();
        await flushPromises();

        expect(runtimeSendMessage).toHaveBeenCalledWith(
            {
                type: 'ATLAS_SHOULD_FORCE_BADGE_CHECK_ON_PAGE',
                url: window.location.href,
            },
            expect.any(Function),
        );
        expect(mockOverlayApply).toHaveBeenCalledWith(image, {
            refreshCheck: {
                bypassCheckCache: true,
            },
        });
    });

    it('does not start page work while hidden and resumes with a bounded visible scan', async () => {
        setDocumentVisibility('hidden');
        mockGetStoredOptions.mockResolvedValue({
            siteCustomizations: [],
        });
        mockResolveSiteCustomizationForHostname.mockReturnValue(null);

        await import('./content-main');
        await flushPromises();

        expect(mockAnchorRuntime.suspend).toHaveBeenCalledTimes(1);
        expect(mockGetStoredOptions).not.toHaveBeenCalled();
        expect(mockSubscribeToDownloadProgress).not.toHaveBeenCalled();
        expect(mockMutationObserverObserve).not.toHaveBeenCalled();

        setDocumentVisibility('visible');
        document.dispatchEvent(new Event('visibilitychange'));
        await flushPromises();
        await flushPromises();

        expect(mockAnchorRuntime.resume).toHaveBeenCalledTimes(1);
        expect(mockGetStoredOptions).toHaveBeenCalledTimes(1);
        expect(mockAnchorRuntime.registerVisibleFromDocument).toHaveBeenCalledWith(100);
        expect(mockAnchorRuntime.registerFromDocument).not.toHaveBeenCalled();

        mockAnchorRuntime.registerVisibleFromDocument.mockClear();
        window.dispatchEvent(new Event('scroll'));

        expect(mockAnchorRuntime.registerVisibleFromDocument).toHaveBeenCalledWith(100);
    });

    it('disconnects active page observers and keeps referrer reaction cache syncs when the tab becomes hidden', async () => {
        mockGetStoredOptions.mockResolvedValue({
            siteCustomizations: [],
        });
        mockResolveSiteCustomizationForHostname.mockReturnValue(null);

        await import('./content-main');
        await flushPromises();
        await flushPromises();

        expect(mockSubscribeToDownloadProgress).toHaveBeenCalledTimes(1);
        expect(progressListener).toBeTypeOf('function');

        const mutationObserverDisconnectsBeforeHide = mockMutationObserverDisconnect.mock.calls.length;
        const unsubscribeCallsBeforeHide = mockUnsubscribeDownloadProgress.mock.calls.length;
        const modelCleanupCallsBeforeHide = mockCleanupCivitAiModelBrowseCtas.mock.calls.length;
        const userCleanupCallsBeforeHide = mockCleanupCivitAiUserBrowseLinks.mock.calls.length;
        const duplicateGuardDestroyCallsBeforeHide = mockDuplicateAnchorTabGuard.destroy.mock.calls.length;
        const anchorSuspendCallsBeforeHide = mockAnchorRuntime.suspend.mock.calls.length;

        setDocumentVisibility('hidden');
        document.dispatchEvent(new Event('visibilitychange'));

        expect(mockMutationObserverDisconnect.mock.calls.length).toBeGreaterThan(mutationObserverDisconnectsBeforeHide);
        expect(mockUnsubscribeDownloadProgress.mock.calls.length).toBeGreaterThan(unsubscribeCallsBeforeHide);
        expect(mockCleanupCivitAiModelBrowseCtas.mock.calls.length).toBeGreaterThan(modelCleanupCallsBeforeHide);
        expect(mockCleanupCivitAiUserBrowseLinks.mock.calls.length).toBeGreaterThan(userCleanupCallsBeforeHide);
        expect(mockDuplicateAnchorTabGuard.destroy.mock.calls.length).toBeGreaterThan(duplicateGuardDestroyCallsBeforeHide);
        expect(mockAnchorRuntime.suspend.mock.calls.length).toBeGreaterThan(anchorSuspendCallsBeforeHide);

        progressListener?.({
            event: 'DownloadTransferQueued',
            transferId: 55,
        });
        runtimeMessageListener?.({
            type: 'ATLAS_REFERRER_REACTION_SYNC',
            phase: 'pending',
            urls: ['https://example.com/post'],
        });

        expect(mockDownloadEventSheetPush).not.toHaveBeenCalled();
        expect(mockAnchorRuntime.handleReferrerReactionSync).toHaveBeenCalledWith({
            type: 'ATLAS_REFERRER_REACTION_SYNC',
            phase: 'pending',
            urls: ['https://example.com/post'],
        });
    });
});
