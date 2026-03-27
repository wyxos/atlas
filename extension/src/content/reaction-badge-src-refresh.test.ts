import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockCollectDeviantArtBatchReactionItems = vi.fn();
const mockCollectCivitAiListingMetadataOverrides = vi.fn();
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
    resolveIdentifiedMediaResolution: () => ({ width: 1200, height: 1800 }),
    resolveMediaUrl: (media: HTMLImageElement | HTMLVideoElement) => media instanceof HTMLImageElement ? media.src : media.currentSrc,
    resolveReactionTargetUrl: (media: HTMLImageElement | HTMLVideoElement) => media instanceof HTMLImageElement ? media.src : media.currentSrc,
}));

vi.mock('./deviantart-batch-reaction', () => ({
    collectDeviantArtBatchReactionItems: mockCollectDeviantArtBatchReactionItems,
}));

vi.mock('./civitai-reaction-context', () => ({
    collectCivitAiListingMetadataOverrides: mockCollectCivitAiListingMetadataOverrides,
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

describe('reaction badge src refresh', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
        history.replaceState({}, '', '/artseize/art/Untitled-1305712740');

        mockCollectDeviantArtBatchReactionItems.mockResolvedValue(null);
        mockCollectCivitAiListingMetadataOverrides.mockResolvedValue(null);
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
            reaction: 'like',
            exists: true,
            fileId: null,
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
        mockSubscribeToDownloadProgress.mockImplementation(() => () => undefined);
        mockGetPersistedBadgeState.mockReturnValue(null);
        mockPersistBadgeCheckResult.mockReturnValue(undefined);
        mockPersistBadgeState.mockReturnValue(undefined);
        mockPersistDownloadProgressEvent.mockReturnValue(undefined);

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
    });

    it('rechecks an already-mounted badge when the media src changes', async () => {
        const { createReactionBadgeHost } = await import('./reaction-badge-app');

        const image = document.createElement('img');
        image.src = 'https://images.example.com/original.jpg';
        document.body.appendChild(image);

        const host = createReactionBadgeHost(image);
        document.body.appendChild(host.element);

        await flushPromises();
        await flushPromises();

        expect(mockEnqueueReactionCheck).toHaveBeenCalledWith(
            'https://images.example.com/original.jpg',
            {
                media: image,
                candidatePageUrls: [window.location.href],
            },
        );

        image.setAttribute('src', 'https://images.example.com/updated.jpg');

        await flushPromises();
        await flushPromises();

        expect(mockEnqueueReactionCheck).toHaveBeenLastCalledWith(
            'https://images.example.com/updated.jpg',
            {
                media: image,
                candidatePageUrls: [window.location.href],
            },
        );
        expect(mockEnqueueReactionCheck).toHaveBeenCalledTimes(2);

        host.unmount();
    });
});
