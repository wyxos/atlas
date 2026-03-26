import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetStoredOptions = vi.fn();
const mockResolveSiteCustomizationForHostname = vi.fn();
const mockCreateCustomizationMatchRules = vi.fn();
const mockSetActivePageSiteCustomization = vi.fn();
const mockResolveMediaResolution = vi.fn();
const mockMediaMatchesRulesForPage = vi.fn();
const mockOverlayApply = vi.fn();
const mockOverlayRemove = vi.fn();
const mockOverlayScheduleReposition = vi.fn();
const mockAnchorRuntime = {
    handleDownloadProgressEvent: vi.fn(),
    handleReferrerReactionSync: vi.fn(),
    handleTabPresenceChanged: vi.fn(),
    registerFromDocument: vi.fn(),
    registerFromNode: vi.fn(),
};
const mockSubscribeToDownloadProgress = vi.fn();
const mockDownloadEventSheetPush = vi.fn();
const mockDuplicateAnchorTabGuard = {
    destroy: vi.fn(),
    handleTabPresenceChanged: vi.fn(),
    refreshSnapshot: vi.fn(),
};
const mockClearDeviantArtBackgroundImageStyle = vi.fn();
const mockMutationObserverObserve = vi.fn();

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
    collectMediaFromNode: (node: Node) => {
        const media: Array<HTMLImageElement | HTMLVideoElement> = [];

        if (node instanceof HTMLImageElement || node instanceof HTMLVideoElement) {
            media.push(node);
        }

        if (node instanceof Element) {
            for (const element of node.querySelectorAll('img,video')) {
                if (element instanceof HTMLImageElement || element instanceof HTMLVideoElement) {
                    media.push(element);
                }
            }
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

function flushPromises(): Promise<void> {
    return new Promise((resolve) => {
        setTimeout(resolve, 0);
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

        class MockMutationObserver {
            constructor(callback: MutationCallback) {
                mutationObserverCallback = callback;
            }

            disconnect(): void {}

            observe = mockMutationObserverObserve;
        }

        vi.stubGlobal('MutationObserver', MockMutationObserver as unknown as typeof MutationObserver);
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
        });
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
        expect(mockOverlayRemove).toHaveBeenCalledWith(linkedImage);
        expect(mockAnchorRuntime.registerFromDocument).toHaveBeenCalledTimes(1);
        expect(mockClearDeviantArtBackgroundImageStyle).toHaveBeenCalledTimes(1);
        expect(runtimeMessageListener).toBeTypeOf('function');
        expect(storageChangeListener).toBeTypeOf('function');
        expect(progressListener).toBeTypeOf('function');
        expect(mockMutationObserverObserve).toHaveBeenCalledTimes(1);

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
});
