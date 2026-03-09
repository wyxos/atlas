import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockCollectDeviantArtBatchReactionItems = vi.fn();
const mockEnqueueReactionCheck = vi.fn();
const mockSubmitBadgeReaction = vi.fn();
const mockGetCloseTabAfterQueuePreferenceForHostname = vi.fn();
const mockGetReactAllItemsInPostPreference = vi.fn();
const mockSetCloseTabAfterQueuePreferenceForHostname = vi.fn();
const mockSetReactAllItemsInPostPreference = vi.fn();
const mockSubscribeToDownloadProgress = vi.fn();
const mockGetPersistedBadgeState = vi.fn();
const mockPersistBadgeCheckResult = vi.fn();
const mockPersistBadgeState = vi.fn();
const mockPersistDownloadProgressEvent = vi.fn();

vi.mock('./match-timestamp', () => ({
    formatMatchTimestamp: () => null,
}));

vi.mock('./media-utils', () => ({
    hasRelatedPostThumbnailsBelowMedia: () => false,
    normalizeUrl: (value: string | null | undefined) => typeof value === 'string' ? value : null,
    resolveIdentifiedMediaResolution: () => '1200 x 1800',
    resolveMediaUrl: (media: HTMLImageElement | HTMLVideoElement) => media instanceof HTMLImageElement ? media.src : media.currentSrc,
    resolveReactionTargetUrl: (media: HTMLImageElement | HTMLVideoElement) => media instanceof HTMLImageElement ? media.src : media.currentSrc,
}));

vi.mock('./deviantart-batch-reaction', () => ({
    collectDeviantArtBatchReactionItems: mockCollectDeviantArtBatchReactionItems,
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
    },
    getCloseTabAfterQueuePreferenceForHostname: mockGetCloseTabAfterQueuePreferenceForHostname,
    getReactAllItemsInPostPreference: mockGetReactAllItemsInPostPreference,
    setCloseTabAfterQueuePreferenceForHostname: mockSetCloseTabAfterQueuePreferenceForHostname,
    setReactAllItemsInPostPreference: mockSetReactAllItemsInPostPreference,
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

describe('createReactionBadgeHost', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
        history.replaceState({}, '', '/artseize/art/Untitled-1305712740');

        mockCollectDeviantArtBatchReactionItems.mockResolvedValue(null);
        mockEnqueueReactionCheck.mockResolvedValue({
            exists: false,
            reaction: null,
            reactedAt: null,
            downloadedAt: null,
            blacklistedAt: null,
        });
        mockGetCloseTabAfterQueuePreferenceForHostname.mockResolvedValue(true);
        mockGetReactAllItemsInPostPreference.mockResolvedValue(false);
        mockSetCloseTabAfterQueuePreferenceForHostname.mockResolvedValue(undefined);
        mockSetReactAllItemsInPostPreference.mockResolvedValue(undefined);
        mockSubscribeToDownloadProgress.mockReturnValue(() => {});
        mockGetPersistedBadgeState.mockReturnValue(null);
        mockPersistBadgeCheckResult.mockReturnValue(undefined);
        mockPersistBadgeState.mockReturnValue(undefined);
        mockPersistDownloadProgressEvent.mockReturnValue(undefined);
    });

    it('closes the tab after a successful submit even if the badge unmounted before the response resolved', async () => {
        let resolveSubmit: ((value: {
            ok: boolean;
            reaction: 'love';
            exists: boolean;
            fileId: number;
            downloadRequested: boolean;
            shouldCloseTabAfterQueue: boolean;
            downloadTransferId: number | null;
            downloadStatus: string | null;
            downloadProgressPercent: number | null;
            reverbConfig: null;
        }) => void) | null = null;

        mockSubmitBadgeReaction.mockImplementation(() => new Promise((resolve) => {
            resolveSubmit = resolve;
        }));

        const runtimeSendMessage = vi.fn((message: unknown, callback?: (response: unknown) => void) => {
            const payload = message as { type?: string };

            if (payload.type === 'ATLAS_GET_TAB_COUNT') {
                callback?.({ count: 2 });
                return;
            }

            if (payload.type === 'ATLAS_CLOSE_CURRENT_TAB') {
                callback?.({ ok: true });
                return;
            }

            callback?.(null);
        });

        vi.stubGlobal('chrome', {
            runtime: {
                lastError: null,
                sendMessage: runtimeSendMessage,
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
        image.src = 'https://images.example.com/main.jpg';
        document.body.appendChild(image);

        const host = createReactionBadgeHost(image);
        document.body.appendChild(host.element);

        await flushPromises();
        await flushPromises();

        host.triggerReaction('love');
        await flushPromises();

        host.unmount();

        resolveSubmit?.({
            ok: true,
            reaction: 'love',
            exists: true,
            fileId: 123,
            downloadRequested: true,
            shouldCloseTabAfterQueue: true,
            downloadTransferId: 456,
            downloadStatus: 'queued',
            downloadProgressPercent: 0,
            reverbConfig: null,
        });

        await flushPromises();
        await flushPromises();

        expect(runtimeSendMessage).toHaveBeenCalledWith(
            { type: 'ATLAS_CLOSE_CURRENT_TAB' },
            expect.any(Function),
        );
    });

    it('keeps the close-tab toggle synchronized across mounted badges on the same hostname', async () => {
        mockGetCloseTabAfterQueuePreferenceForHostname.mockResolvedValue(false);

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

        const firstImage = document.createElement('img');
        firstImage.src = 'https://images.example.com/first.jpg';
        document.body.appendChild(firstImage);

        const secondImage = document.createElement('img');
        secondImage.src = 'https://images.example.com/second.jpg';
        document.body.appendChild(secondImage);

        const firstHost = createReactionBadgeHost(firstImage);
        const secondHost = createReactionBadgeHost(secondImage);
        document.body.appendChild(firstHost.element);
        document.body.appendChild(secondHost.element);

        await flushPromises();
        await flushPromises();

        const firstToggle = Array.from(firstHost.element.querySelectorAll('button'))
            .find((button) => button.textContent === 'Off');
        const secondToggleBefore = Array.from(secondHost.element.querySelectorAll('button'))
            .find((button) => button.textContent === 'Off');

        expect(firstToggle).toBeTruthy();
        expect(secondToggleBefore).toBeTruthy();

        firstToggle?.click();
        await flushPromises();
        await flushPromises();

        const firstToggleAfter = Array.from(firstHost.element.querySelectorAll('button'))
            .find((button) => button.textContent === 'On');
        const secondToggleAfter = Array.from(secondHost.element.querySelectorAll('button'))
            .find((button) => button.textContent === 'On');

        expect(mockSetCloseTabAfterQueuePreferenceForHostname).toHaveBeenCalledWith(window.location.hostname, true);
        expect(firstToggleAfter).toBeTruthy();
        expect(secondToggleAfter).toBeTruthy();

        firstHost.unmount();
        secondHost.unmount();
    });
});
