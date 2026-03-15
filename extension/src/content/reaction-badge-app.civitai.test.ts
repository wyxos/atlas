import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockClassifyCivitAiReactionPage = vi.fn();
const mockCollectCivitAiBatchReactionItems = vi.fn();
const mockCollectCivitAiListingMetadataOverrides = vi.fn();
const mockHasCivitAiBatchReactionItems = vi.fn();
const mockEnqueueReactionCheck = vi.fn();
const mockSubmitBadgeReaction = vi.fn();
const mockHasRelatedPostThumbnailsBelowMedia = vi.fn();
const mockGetCloseTabAfterQueuePreferenceForHostname = vi.fn();
const mockGetReactAllItemsInPostPreferenceForHostname = vi.fn();
const mockSetCloseTabAfterQueuePreferenceForHostname = vi.fn();
const mockSetReactAllItemsInPostPreferenceForHostname = vi.fn();
const mockSubscribeToDownloadProgress = vi.fn();
const mockGetPersistedBadgeState = vi.fn();
const mockPersistBadgeCheckResult = vi.fn();
const mockPersistBadgeState = vi.fn();
const mockPersistDownloadProgressEvent = vi.fn();

vi.mock('./match-timestamp', () => ({
    formatMatchTimestamp: () => null,
}));

vi.mock('./media-utils', () => ({
    hasRelatedPostThumbnailsBelowMedia: mockHasRelatedPostThumbnailsBelowMedia,
    normalizeUrl: (value: string | null | undefined) => typeof value === 'string' ? value : null,
    resolveIdentifiedMediaResolution: () => '1200 x 1800',
    resolveMediaUrl: (media: HTMLImageElement | HTMLVideoElement) => media instanceof HTMLImageElement ? media.src : media.currentSrc,
    resolveReactionTargetUrl: (media: HTMLImageElement | HTMLVideoElement) => media instanceof HTMLImageElement ? media.src : media.currentSrc,
}));

vi.mock('./deviantart-batch-reaction', () => ({
    collectDeviantArtBatchReactionItems: vi.fn(),
}));

vi.mock('./civitai-reaction-context', () => ({
    classifyCivitAiReactionPage: mockClassifyCivitAiReactionPage,
    collectCivitAiBatchReactionItems: mockCollectCivitAiBatchReactionItems,
    collectCivitAiListingMetadataOverrides: mockCollectCivitAiListingMetadataOverrides,
    hasCivitAiBatchReactionItems: mockHasCivitAiBatchReactionItems,
}));

vi.mock('./reaction-check-queue', () => ({
    enqueueReactionCheck: mockEnqueueReactionCheck,
}));

vi.mock('./reaction-submit', () => ({
    submitBadgeReaction: mockSubmitBadgeReaction,
}));

vi.mock('./download-progress-bus', () => ({
    subscribeToDownloadProgress: mockSubscribeToDownloadProgress,
}));

vi.mock('../atlas-options', () => ({
    STORAGE_KEYS: {
        closeTabAfterQueueByDomain: 'closeTabAfterQueueByDomain',
        reactAllItemsInPostByDomain: 'reactAllItemsInPostByDomain',
        reactAllItemsInPostEnabled: 'reactAllItemsInPostEnabled',
    },
    getCloseTabAfterQueuePreferenceForHostname: mockGetCloseTabAfterQueuePreferenceForHostname,
    getReactAllItemsInPostPreferenceForHostname: mockGetReactAllItemsInPostPreferenceForHostname,
    setCloseTabAfterQueuePreferenceForHostname: mockSetCloseTabAfterQueuePreferenceForHostname,
    setReactAllItemsInPostPreferenceForHostname: mockSetReactAllItemsInPostPreferenceForHostname,
}));

vi.mock('./badge-state-cache', () => ({
    getPersistedBadgeState: mockGetPersistedBadgeState,
    persistBadgeCheckResult: mockPersistBadgeCheckResult,
    persistBadgeState: mockPersistBadgeState,
    persistDownloadProgressEvent: mockPersistDownloadProgressEvent,
}));

function flushPromises(): Promise<void> {
    return new Promise((resolve) => {
        setTimeout(resolve, 0);
    });
}

function stubChromeRuntime(): void {
    vi.stubGlobal('chrome', {
        runtime: {
            lastError: null,
            sendMessage: vi.fn((message: unknown, callback?: (response: unknown) => void) => {
                const payload = message as { type?: string };

                if (payload.type === 'ATLAS_GET_TAB_COUNT') {
                    callback?.({ count: 2 });
                    return;
                }

                callback?.(null);
            }),
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
}

describe('createReactionBadgeHost civitai visibility', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
        history.replaceState({}, '', '/posts/16973563');

        mockClassifyCivitAiReactionPage.mockReturnValue(null);
        mockCollectCivitAiBatchReactionItems.mockResolvedValue(null);
        mockCollectCivitAiListingMetadataOverrides.mockResolvedValue(null);
        mockHasCivitAiBatchReactionItems.mockReturnValue(false);
        mockHasRelatedPostThumbnailsBelowMedia.mockReturnValue(false);
        mockEnqueueReactionCheck.mockResolvedValue({
            exists: false,
            reaction: null,
            reactedAt: null,
            downloadedAt: null,
            blacklistedAt: null,
        });
        mockSubmitBadgeReaction.mockResolvedValue({
            ok: true,
            reaction: 'love',
            exists: true,
            fileId: 1,
            downloadRequested: false,
            shouldCloseTabAfterQueue: false,
            downloadTransferId: null,
            downloadStatus: null,
            downloadProgressPercent: null,
            downloadCloseTargets: [],
            reverbConfig: null,
        });
        mockGetCloseTabAfterQueuePreferenceForHostname.mockResolvedValue('completed');
        mockGetReactAllItemsInPostPreferenceForHostname.mockResolvedValue(false);
        mockSetCloseTabAfterQueuePreferenceForHostname.mockResolvedValue(undefined);
        mockSetReactAllItemsInPostPreferenceForHostname.mockResolvedValue(undefined);
        mockSubscribeToDownloadProgress.mockReturnValue(() => {});
        mockGetPersistedBadgeState.mockReturnValue(null);
        mockPersistBadgeCheckResult.mockReturnValue(undefined);
        mockPersistBadgeState.mockReturnValue(undefined);
        mockPersistDownloadProgressEvent.mockReturnValue(undefined);
    });

    it('shows the batch checkbox on CivitAI post pages when batch items are available', async () => {
        mockClassifyCivitAiReactionPage.mockReturnValue('post-page');
        mockHasCivitAiBatchReactionItems.mockReturnValue(true);
        stubChromeRuntime();

        const { createReactionBadgeHost } = await import('./reaction-badge-app');

        const image = document.createElement('img');
        image.src = 'https://image.civitai.com/example/post-image.jpeg';
        document.body.appendChild(image);

        const host = createReactionBadgeHost(image);
        document.body.appendChild(host.element);

        await flushPromises();
        await flushPromises();

        expect(host.element.querySelector('input[type="checkbox"]')).toBeTruthy();

        host.unmount();
    });

    it('does not show the batch checkbox on CivitAI image pages', async () => {
        mockClassifyCivitAiReactionPage.mockReturnValue('image-page');
        mockHasCivitAiBatchReactionItems.mockReturnValue(true);
        history.replaceState({}, '', '/images/76477306');
        stubChromeRuntime();

        const { createReactionBadgeHost } = await import('./reaction-badge-app');

        const image = document.createElement('img');
        image.src = 'https://image.civitai.com/example/single-image.jpeg';
        document.body.appendChild(image);

        const host = createReactionBadgeHost(image);
        document.body.appendChild(host.element);

        await flushPromises();
        await flushPromises();

        expect(host.element.querySelector('input[type="checkbox"]')).toBeNull();

        host.unmount();
    });
});
