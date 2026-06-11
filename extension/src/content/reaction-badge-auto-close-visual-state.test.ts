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
const mockGetStoredConnectionOptions = vi.fn();

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
    DEFAULT_ATLAS_DOMAIN: 'https://atlas.test',
    STORAGE_KEYS: {
        closeTabAfterQueueByDomain: 'closeTabAfterQueueByDomain',
        reactAllItemsInPostByDomain: 'reactAllItemsInPostByDomain',
        reactAllItemsInPostEnabled: 'reactAllItemsInPostEnabled',
    },
    getStoredConnectionOptions: mockGetStoredConnectionOptions,
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

describe('reaction badge queued auto-close visual state', () => {
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
        mockGetCloseTabAfterQueuePreferenceForHostname.mockResolvedValue('queued');
        mockGetReactAllItemsInPostPreferenceForHostname.mockResolvedValue(false);
        mockGetStoredConnectionOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.test',
            apiToken: '',
        });
        mockSetCloseTabAfterQueuePreferenceForHostname.mockResolvedValue(undefined);
        mockSetReactAllItemsInPostPreferenceForHostname.mockResolvedValue(undefined);
        mockSubscribeToDownloadProgress.mockReturnValue(() => undefined);
        mockGetPersistedBadgeState.mockReturnValue(null);
        mockPersistBadgeCheckResult.mockReturnValue(undefined);
        mockPersistBadgeState.mockReturnValue(undefined);
        mockPersistDownloadProgressEvent.mockReturnValue(undefined);
    });

    it('keeps the queued auto-close reaction visually submitting until the close request resolves', async () => {
        mockSubmitBadgeReaction.mockResolvedValue({
            ok: true,
            reaction: 'love',
            exists: true,
            fileId: 123,
            downloadRequested: false,
            shouldCloseTabAfterQueue: true,
            downloadTransferId: null,
            downloadStatus: null,
            downloadProgressPercent: null,
            downloadCloseTargets: [
                {
                    fileId: 456,
                    transferId: 789,
                    status: 'queued',
                    downloadedAt: null,
                },
            ],
            reverbConfig: null,
        });

        let closeTabCallback: ((response: unknown) => void) | null = null;
        vi.stubGlobal('chrome', {
            runtime: {
                lastError: null,
                sendMessage: vi.fn((message: unknown, callback?: (response: unknown) => void) => {
                    const payload = message as { type?: string };

                    if (payload.type === 'ATLAS_GET_TAB_COUNT') {
                        callback?.({ count: 2 });
                        return;
                    }

                    if (payload.type === 'ATLAS_CLOSE_CURRENT_TAB') {
                        closeTabCallback = callback ?? null;
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

        const { createReactionBadgeHost } = await import('./reaction-badge-app');
        const image = document.createElement('img');
        image.src = 'https://images.example.com/non-primary-queued-close.jpg';
        document.body.appendChild(image);

        const host = createReactionBadgeHost(image);
        document.body.appendChild(host.element);

        await flushPromises();
        await flushPromises();

        host.triggerReaction('love');
        await flushPromises();
        await flushPromises();

        const blankButtons = Array.from(host.element.querySelectorAll('button'))
            .filter((button) => button.textContent?.trim() === '');
        const loveButton = blankButtons[0];

        expect(closeTabCallback).not.toBeNull();
        expect(loveButton).toBeTruthy();
        expect(loveButton?.disabled).toBe(true);
        expect(loveButton?.querySelector('[style*="atlas-badge-spin"]')).toBeTruthy();

        closeTabCallback?.({ ok: false });
        await flushPromises();
        await flushPromises();

        expect(loveButton?.disabled).toBe(false);
        expect(loveButton?.querySelector('[style*="atlas-badge-spin"]')).toBeNull();

        host.unmount();
    });
});
