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
const progressListeners = new Set<(event: {
    fileId: number | null;
    transferId: number | null;
    sourceUrl: string | null;
    referrerUrl: string | null;
    status: string | null;
    percent: number | null;
    reaction: 'love' | 'like' | 'dislike' | 'funny' | null;
    reactedAt?: string | null;
    downloadedAt?: string | null;
    blacklistedAt?: string | null;
    payload: Record<string, unknown>;
}) => void>();

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

describe('createReactionBadgeHost', () => {
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
        mockGetCloseTabAfterQueuePreferenceForHostname.mockResolvedValue('completed');
        mockGetReactAllItemsInPostPreferenceForHostname.mockResolvedValue(false);
        mockSetCloseTabAfterQueuePreferenceForHostname.mockResolvedValue(undefined);
        mockSetReactAllItemsInPostPreferenceForHostname.mockResolvedValue(undefined);
        progressListeners.clear();
        mockSubscribeToDownloadProgress.mockImplementation((listener) => {
            progressListeners.add(listener);

            return () => {
                progressListeners.delete(listener);
            };
        });
        mockGetPersistedBadgeState.mockReturnValue(null);
        mockPersistBadgeCheckResult.mockReturnValue(undefined);
        mockPersistBadgeState.mockReturnValue(undefined);
        mockPersistDownloadProgressEvent.mockReturnValue(undefined);
    });

    it('closes the tab after tracked download completion even if the badge unmounted before the response resolved', async () => {
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
            downloadCloseTargets: Array<{
                fileId: number | null;
                transferId: number | null;
                status: string | null;
                downloadedAt: string | null;
            }>;
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
            downloadCloseTargets: [
                {
                    fileId: 123,
                    transferId: 456,
                    status: 'queued',
                    downloadedAt: null,
                },
            ],
            reverbConfig: null,
        });

        await flushPromises();
        await flushPromises();

        expect(runtimeSendMessage).not.toHaveBeenCalledWith(
            { type: 'ATLAS_CLOSE_CURRENT_TAB' },
            expect.any(Function),
        );

        for (const listener of progressListeners) {
            listener({
                fileId: 123,
                transferId: 456,
                sourceUrl: image.src,
                referrerUrl: window.location.href,
                status: 'completed',
                percent: 100,
                reaction: 'love',
                reactedAt: null,
                downloadedAt: '2026-03-12T00:00:00Z',
                blacklistedAt: null,
                payload: {},
            });
        }

        await flushPromises();
        await flushPromises();

        expect(runtimeSendMessage).toHaveBeenCalledWith(
            { type: 'ATLAS_CLOSE_CURRENT_TAB' },
            expect.any(Function),
        );
    });

    it('keeps the close-tab toggle synchronized across mounted badges on the same hostname', async () => {
        mockGetCloseTabAfterQueuePreferenceForHostname.mockResolvedValue('off');

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
            .find((button) => button.textContent === 'Queued');
        const secondToggleAfter = Array.from(secondHost.element.querySelectorAll('button'))
            .find((button) => button.textContent === 'Queued');

        expect(mockSetCloseTabAfterQueuePreferenceForHostname).toHaveBeenCalledWith(window.location.hostname, 'queued');
        expect(firstToggleAfter).toBeTruthy();
        expect(secondToggleAfter).toBeTruthy();

        firstHost.unmount();
        secondHost.unmount();
    });

    it('keeps the react-all-items toggle synchronized across mounted badges on the same hostname', async () => {
        mockHasRelatedPostThumbnailsBelowMedia.mockReturnValue(true);
        mockGetReactAllItemsInPostPreferenceForHostname.mockResolvedValue(false);

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

        const firstCheckbox = firstHost.element.querySelector('input[type="checkbox"]') as HTMLInputElement | null;
        const secondCheckboxBefore = secondHost.element.querySelector('input[type="checkbox"]') as HTMLInputElement | null;

        expect(firstCheckbox?.checked).toBe(false);
        expect(secondCheckboxBefore?.checked).toBe(false);

        firstCheckbox?.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();
        await flushPromises();

        const secondCheckboxAfter = secondHost.element.querySelector('input[type="checkbox"]') as HTMLInputElement | null;

        expect(mockSetReactAllItemsInPostPreferenceForHostname).toHaveBeenCalledWith(window.location.hostname, true);
        expect(firstCheckbox?.checked).toBe(true);
        expect(secondCheckboxAfter?.checked).toBe(true);

        firstHost.unmount();
        secondHost.unmount();
    });

    it('refreshes the hostname batch preference before deciding whether to batch react', async () => {
        mockHasRelatedPostThumbnailsBelowMedia.mockReturnValue(true);
        mockGetReactAllItemsInPostPreferenceForHostname
            .mockResolvedValueOnce(false)
            .mockResolvedValue(true);
        mockCollectDeviantArtBatchReactionItems.mockResolvedValue([
            {
                candidateId: 'image-1',
                url: 'https://images.example.com/first.jpg',
                referrerUrlHashAware: 'https://www.deviantart.com/artist/art/post-1',
                pageUrl: 'https://www.deviantart.com/artist/art/post-1',
                tagName: 'img',
            },
            {
                candidateId: 'image-2',
                url: 'https://images.example.com/second.jpg',
                referrerUrlHashAware: 'https://www.deviantart.com/artist/art/post-1#image-2',
                pageUrl: 'https://www.deviantart.com/artist/art/post-1',
                tagName: 'img',
            },
        ]);
        mockSubmitBadgeReaction.mockResolvedValue({
            ok: true,
            reaction: 'love',
            exists: true,
            fileId: 123,
            downloadRequested: true,
            shouldCloseTabAfterQueue: true,
            downloadTransferId: 456,
            downloadStatus: 'queued',
            downloadProgressPercent: 0,
            downloadCloseTargets: [
                {
                    fileId: 123,
                    transferId: 456,
                    status: 'queued',
                    downloadedAt: null,
                },
            ],
            reverbConfig: null,
        });

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
                        callback?.({ ok: true });
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
        image.src = 'https://images.example.com/first.jpg';
        document.body.appendChild(image);

        const host = createReactionBadgeHost(image);
        document.body.appendChild(host.element);

        await flushPromises();
        await flushPromises();

        host.triggerReaction('love');
        await flushPromises();
        await flushPromises();

        expect(mockCollectDeviantArtBatchReactionItems).toHaveBeenCalledWith(image, {
            hostname: window.location.hostname,
        });
        expect(mockSubmitBadgeReaction).toHaveBeenCalledWith(image, 'love', {
            batchItems: [
                {
                    candidateId: 'image-1',
                    url: 'https://images.example.com/first.jpg',
                    referrerUrlHashAware: 'https://www.deviantart.com/artist/art/post-1',
                    pageUrl: 'https://www.deviantart.com/artist/art/post-1',
                    tagName: 'img',
                },
                {
                    candidateId: 'image-2',
                    url: 'https://images.example.com/second.jpg',
                    referrerUrlHashAware: 'https://www.deviantart.com/artist/art/post-1#image-2',
                    pageUrl: 'https://www.deviantart.com/artist/art/post-1',
                    tagName: 'img',
                },
            ],
        });

        host.unmount();
    });

    it('closes the tab immediately when auto-close mode is queued', async () => {
        mockGetCloseTabAfterQueuePreferenceForHostname.mockResolvedValue('queued');
        mockSubmitBadgeReaction.mockResolvedValue({
            ok: true,
            reaction: 'love',
            exists: true,
            fileId: 123,
            downloadRequested: true,
            shouldCloseTabAfterQueue: true,
            downloadTransferId: 456,
            downloadStatus: 'queued',
            downloadProgressPercent: 0,
            downloadCloseTargets: [
                {
                    fileId: 123,
                    transferId: 456,
                    status: 'queued',
                    downloadedAt: null,
                },
            ],
            reverbConfig: null,
        });

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
        image.src = 'https://images.example.com/queued-close.jpg';
        document.body.appendChild(image);

        const host = createReactionBadgeHost(image);
        document.body.appendChild(host.element);

        await flushPromises();
        await flushPromises();

        host.triggerReaction('love');
        await flushPromises();
        await flushPromises();

        expect(runtimeSendMessage).toHaveBeenCalledWith(
            { type: 'ATLAS_CLOSE_CURRENT_TAB' },
            expect.any(Function),
        );

        host.unmount();
    });
});
