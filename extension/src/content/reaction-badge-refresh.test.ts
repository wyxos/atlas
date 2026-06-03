import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockEnqueueReactionCheck = vi.fn();
const mockSubmitBadgeReaction = vi.fn();
const mockSubscribeToDownloadProgress = vi.fn();
const mockGetPersistedBadgeState = vi.fn();
const mockGetStoredConnectionOptions = vi.fn();

vi.mock('./match-timestamp', () => ({
    formatMatchTimestamp: () => null,
}));

vi.mock('./media-utils', () => ({
    hasRelatedPostThumbnailsBelowMedia: () => false,
    normalizeUrl: (value: string | null | undefined) => typeof value === 'string' ? value : null,
    resolveIdentifiedMediaResolution: () => null,
    resolveMediaUrl: (media: HTMLImageElement | HTMLVideoElement) => media instanceof HTMLImageElement ? media.src : media.currentSrc,
    resolveReactionTargetUrl: (media: HTMLImageElement | HTMLVideoElement) => media instanceof HTMLImageElement ? media.src : media.currentSrc,
}));

vi.mock('./deviantart-batch-reaction', () => ({
    collectDeviantArtBatchReactionItems: vi.fn().mockResolvedValue(null),
}));

vi.mock('./civitai-reaction-context', () => ({
    collectCivitAiListingMetadataOverrides: vi.fn().mockResolvedValue(null),
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
    getCloseTabAfterQueuePreferenceForHostname: vi.fn().mockResolvedValue('completed'),
    getReactAllItemsInPostPreferenceForHostname: vi.fn().mockResolvedValue(false),
    setCloseTabAfterQueuePreferenceForHostname: vi.fn().mockResolvedValue(undefined),
    setReactAllItemsInPostPreferenceForHostname: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('./badge-state-cache', () => ({
    getPersistedBadgeState: mockGetPersistedBadgeState,
    persistBadgeCheckResult: vi.fn(),
    persistBadgeState: vi.fn(),
    persistDownloadProgressEvent: vi.fn(),
}));

function flushPromises(): Promise<void> {
    return new Promise((resolve) => {
        setTimeout(resolve, 0);
    });
}

describe('reaction badge refresh checks', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
        history.replaceState({}, '', '/restored');

        mockEnqueueReactionCheck.mockResolvedValue({
            exists: false,
            reaction: null,
            reactedAt: null,
            downloadedAt: null,
            blacklistedAt: null,
        });
        mockSubmitBadgeReaction.mockResolvedValue({ ok: true });
        mockSubscribeToDownloadProgress.mockReturnValue(() => {});
        mockGetPersistedBadgeState.mockReturnValue(null);
        mockGetStoredConnectionOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.test',
            apiToken: '',
        });

        vi.stubGlobal('chrome', {
            runtime: {
                lastError: null,
                sendMessage: vi.fn((message: unknown, callback?: (response: unknown) => void) => {
                    const payload = message as { type?: string };
                    callback?.(payload.type === 'ATLAS_GET_TAB_COUNT' ? { count: 2 } : null);
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

    it('can force a cache-bypassing badge check after the badge is mounted', async () => {
        const { createReactionBadgeHost } = await import('./reaction-badge-app');

        const image = document.createElement('img');
        image.src = 'https://images.example.com/restored.jpg';
        document.body.appendChild(image);

        const host = createReactionBadgeHost(image, { bypassCheckCache: true });
        document.body.appendChild(host.element);

        await flushPromises();
        await flushPromises();

        expect(mockEnqueueReactionCheck).toHaveBeenCalledWith(
            image.src,
            expect.objectContaining({
                media: image,
                bypassCache: true,
            }),
        );

        host.refreshCheck({ bypassCheckCache: true });
        await flushPromises();
        await flushPromises();

        expect(mockEnqueueReactionCheck).toHaveBeenCalledTimes(2);
        expect(mockEnqueueReactionCheck).toHaveBeenLastCalledWith(
            image.src,
            expect.objectContaining({
                media: image,
                bypassCache: true,
            }),
        );

        host.unmount();
    });
});
