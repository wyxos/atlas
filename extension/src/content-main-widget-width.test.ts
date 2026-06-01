import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { SiteCustomization } from './site-customizations';

const mockGetStoredOptions = vi.fn();
const mockResolveSiteCustomizationForHostname = vi.fn();
const mockCreateCustomizationMatchRules = vi.fn();
const mockSetActivePageSiteCustomization = vi.fn();
const mockResolveMediaResolution = vi.fn();
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
const mockSubscribeToDownloadProgress = vi.fn(() => vi.fn());
const mockRequestAnimationFrame = vi.fn();
const mockCancelAnimationFrame = vi.fn();

vi.mock('./atlas-options', () => ({
    getStoredOptions: mockGetStoredOptions,
}));

vi.mock('./site-customizations', () => ({
    DEFAULT_WIDGET_MIN_IMAGE_WIDTH: 200,
    createCustomizationMatchRules: mockCreateCustomizationMatchRules,
    resolveSiteCustomizationForHostname: mockResolveSiteCustomizationForHostname,
}));

vi.mock('./page-customization-state', () => ({
    setActivePageSiteCustomization: mockSetActivePageSiteCustomization,
}));

vi.mock('./content/media-utils', () => ({
    collectOpenShadowRootsFromNode: vi.fn(() => []),
    collectMediaFromNode: (node: Node) => {
        const media: Array<HTMLImageElement | HTMLVideoElement> = [];
        if (node instanceof HTMLImageElement || node instanceof HTMLVideoElement) {
            media.push(node);
        }

        if (node instanceof Element || node instanceof Document || node instanceof DocumentFragment) {
            media.push(...Array.from(node.querySelectorAll('img,video')) as Array<HTMLImageElement | HTMLVideoElement>);
        }

        return media;
    },
    isMediaElement: (value: unknown) => value instanceof HTMLImageElement || value instanceof HTMLVideoElement,
    normalizeUrl: (value: string | null | undefined) => {
        if (typeof value !== 'string' || value.trim() === '' || !/^https?:\/\//i.test(value.trim())) {
            return null;
        }

        return value.trim().replace(/#.*$/, '');
    },
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
        push: vi.fn(),
    })),
}));

vi.mock('./content/duplicate-anchor-tab-guard', () => ({
    createDuplicateAnchorTabGuard: vi.fn(() => ({
        destroy: vi.fn(),
        handleTabPresenceChanged: vi.fn(),
        refreshSnapshot: vi.fn(),
    })),
}));

vi.mock('./content/deviantart-background-image-style', () => ({
    clearDeviantArtBackgroundImageStyle: vi.fn(),
}));

vi.mock('./content/civitai-model-browse-cta', () => ({
    installCivitAiModelBrowseCtas: vi.fn(),
}));

vi.mock('./content/civitai-user-browse-link', () => ({
    installCivitAiUserBrowseLinks: vi.fn(),
}));

vi.mock('./content/deviantart-artist-browse-cta', () => ({
    installDeviantArtArtistBrowseCtas: vi.fn(),
}));

vi.mock('./content/page-work-lifecycle', () => ({
    installPageVisibilityLifecycle: vi.fn(),
    isPageVisible: vi.fn(() => true),
}));

vi.mock('./content/restored-page-badge-check', () => ({
    shouldBypassBadgeCheckCacheForPageStart: vi.fn(async () => false),
}));

vi.mock('./content/anchor-referrer-shortcut-listener', () => ({
    createAnchorReferrerShortcutListener: vi.fn(() => vi.fn()),
}));

function flushPromises(): Promise<void> {
    return new Promise((resolve) => {
        setTimeout(resolve, 0);
    });
}

function createCustomization(minImageWidth: number | null): SiteCustomization {
    return {
        enabled: true,
        domain: 'example.com',
        matchRules: ['.*'],
        widget: {
            minImageWidth,
        },
        referrerCleaner: {
            stripQueryParams: [],
        },
        mediaCleaner: {
            stripQueryParams: [],
            rewriteRules: [],
            strategies: [],
        },
    };
}

async function importContentMainWithImage(customization: SiteCustomization, imageWidth: number): Promise<HTMLImageElement> {
    mockGetStoredOptions.mockResolvedValue({
        siteCustomizations: [customization],
    });
    mockResolveSiteCustomizationForHostname.mockReturnValue(customization);
    mockCreateCustomizationMatchRules.mockReturnValue(['compiled-rule']);
    mockResolveMediaResolution.mockReturnValue({
        width: imageWidth,
        height: 120,
    });

    const image = document.createElement('img');
    image.src = 'https://cdn.example.com/thumbnail.jpg';
    document.body.appendChild(image);

    await import('./content-main');
    await flushPromises();
    await flushPromises();

    return image;
}

describe('content-main widget width guard', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';

        Object.defineProperty(document, 'readyState', {
            configurable: true,
            value: 'complete',
        });
        Object.defineProperty(document, 'visibilityState', {
            configurable: true,
            value: 'visible',
        });

        class MockMutationObserver {
            disconnect = vi.fn();
            observe = vi.fn();
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
                    addListener: vi.fn(),
                },
            },
            storage: {
                onChanged: {
                    addListener: vi.fn(),
                },
            },
        });

        mockMediaMatchesRulesForPage.mockReturnValue(true);
    });

    it('uses the global image width guard unless a site profile overrides it', async () => {
        const image = await importContentMainWithImage(createCustomization(120), 180);

        expect(mockSetActivePageSiteCustomization).toHaveBeenCalledWith(createCustomization(120));
        expect(mockOverlayApply).toHaveBeenCalledWith(image);
        expect(mockOverlayRemove).not.toHaveBeenCalledWith(image);
    });

    it('blocks small images on the interaction fallback when the site uses the global width guard', async () => {
        const addEventListenerSpy = vi.spyOn(document, 'addEventListener');
        const image = await importContentMainWithImage(createCustomization(null), 180);

        expect(mockOverlayApply).not.toHaveBeenCalledWith(image);

        const mouseoverHandler = addEventListenerSpy.mock.calls.find(([type]) => type === 'mouseover')?.[1];
        expect(mouseoverHandler).toEqual(expect.any(Function));

        const event = new MouseEvent('mouseover', {
            bubbles: true,
            clientX: 10,
            clientY: 10,
        });
        Object.defineProperty(event, 'target', {
            configurable: true,
            value: image,
        });
        (mouseoverHandler as EventListener)(event);

        expect(mockOverlayApply).not.toHaveBeenCalledWith(image);
        expect(mockOverlayRemove).toHaveBeenCalledWith(image);
    });
});
