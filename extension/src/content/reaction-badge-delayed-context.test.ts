import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockEnqueueReactionCheck = vi.fn();
const mockHasRelatedPostThumbnailsBelowMedia = vi.fn();
const mockGetCloseTabAfterQueuePreferenceForHostname = vi.fn();
const mockGetReactAllItemsInPostPreferenceForHostname = vi.fn();
const mockSubscribeToDownloadProgress = vi.fn();

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

vi.mock('./reaction-check-queue', () => ({
    enqueueReactionCheck: mockEnqueueReactionCheck,
}));

vi.mock('./reaction-submit', () => ({
    submitBadgeReaction: vi.fn(),
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
    setCloseTabAfterQueuePreferenceForHostname: vi.fn(),
    setReactAllItemsInPostPreferenceForHostname: vi.fn(),
}));

vi.mock('./badge-state-cache', () => ({
    getPersistedBadgeState: () => null,
    persistBadgeCheckResult: vi.fn(),
    persistBadgeState: vi.fn(),
    persistDownloadProgressEvent: vi.fn(),
}));

function flushPromises(): Promise<void> {
    return new Promise((resolve) => {
        setTimeout(resolve, 0);
    });
}

describe('createReactionBadgeHost delayed DeviantArt context', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';

        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://www.deviantart.com/artseize/art/Untitled-1305712740') as unknown as Location,
        });

        mockHasRelatedPostThumbnailsBelowMedia.mockReturnValue(false);
        mockEnqueueReactionCheck.mockResolvedValue({
            exists: false,
            reaction: null,
            reactedAt: null,
            downloadedAt: null,
            blacklistedAt: null,
        });
        mockGetCloseTabAfterQueuePreferenceForHostname.mockResolvedValue('off');
        mockGetReactAllItemsInPostPreferenceForHostname.mockResolvedValue(false);
        mockSubscribeToDownloadProgress.mockImplementation(() => () => {});
    });

    it('shows the batch checkbox when related thumbnails appear shortly after mount', async () => {
        let hasRelatedThumbnails = false;
        mockHasRelatedPostThumbnailsBelowMedia.mockImplementation(() => hasRelatedThumbnails);

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

        const { createReactionBadgeHost } = await import('./reaction-badge-app');

        const image = document.createElement('img');
        image.src = 'https://images.example.com/delayed-gallery.jpg';
        document.body.appendChild(image);

        const host = createReactionBadgeHost(image);
        document.body.appendChild(host.element);

        await flushPromises();
        await flushPromises();

        expect(host.element.querySelector('input[type="checkbox"]')).toBeNull();

        hasRelatedThumbnails = true;
        await vi.waitFor(() => {
            expect(host.element.querySelector('input[type="checkbox"]')).toBeTruthy();
        });

        host.unmount();
    });
});
