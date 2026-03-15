import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockEnqueueReactionCheck = vi.fn();
const mockSubmitBadgeReaction = vi.fn();
const mockHasRelatedPostThumbnailsBelowMedia = vi.fn();
const mockSubscribeToDownloadProgress = vi.fn();
const mockRequestTabCount = vi.fn();
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

vi.mock('./reaction-check-queue', () => ({
    enqueueReactionCheck: mockEnqueueReactionCheck,
}));

vi.mock('./reaction-submit', () => ({
    submitBadgeReaction: mockSubmitBadgeReaction,
}));

vi.mock('./download-progress-bus', () => ({
    subscribeToDownloadProgress: mockSubscribeToDownloadProgress,
}));

vi.mock('./badge-state-cache', () => ({
    getPersistedBadgeState: () => null,
    persistBadgeCheckResult: vi.fn(),
    persistBadgeState: vi.fn(),
    persistDownloadProgressEvent: vi.fn(),
}));

vi.mock('./downloaded-reaction-dialog', () => ({
    createDownloadedReactionDialog: () => ({
        destroy: vi.fn(),
        prompt: vi.fn(),
    }),
}));

vi.mock('./reaction-badge-runtime-style', () => ({
    ensureReactionBadgeRuntimeStyles: vi.fn(),
}));

vi.mock('./reaction-badge-tab-runtime', () => ({
    requestCloseCurrentTab: vi.fn(),
    requestTabCount: mockRequestTabCount,
    subscribeToTabCountChanged: vi.fn(() => () => {}),
}));

vi.mock('./close-tab-after-queue-state', () => ({
    useCloseTabAfterQueuePreference: () => ({
        cycleMode: vi.fn(),
        mode: { value: 'completed' as const },
        saving: { value: false },
    }),
}));

vi.mock('./react-all-items-in-post-state', () => ({
    useReactAllItemsInPostPreference: () => ({
        enabled: { value: false },
        refresh: vi.fn().mockResolvedValue(undefined),
        saving: { value: false },
        toggle: vi.fn().mockResolvedValue(undefined),
    }),
}));

function flushPromises(): Promise<void> {
    return new Promise((resolve) => {
        setTimeout(resolve, 0);
    });
}

describe('createReactionBadgeHost progress persistence', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
        history.replaceState({}, '', '/artseize/art/Untitled-1305712740');

        mockHasRelatedPostThumbnailsBelowMedia.mockReturnValue(false);
        mockEnqueueReactionCheck.mockResolvedValue({
            exists: false,
            reaction: null,
            reactedAt: null,
            downloadedAt: null,
            blacklistedAt: null,
        });
        mockRequestTabCount.mockResolvedValue(2);
        progressListeners.clear();
        mockSubscribeToDownloadProgress.mockImplementation((listener) => {
            progressListeners.add(listener);
            return () => {
                progressListeners.delete(listener);
            };
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

    it('keeps tracked download progress after the media source changes mid-download', async () => {
        mockSubmitBadgeReaction.mockResolvedValue({
            ok: true,
            reaction: 'love',
            exists: true,
            fileId: 123,
            downloadRequested: true,
            shouldCloseTabAfterQueue: false,
            downloadTransferId: 456,
            downloadStatus: null,
            downloadProgressPercent: null,
            downloadCloseTargets: [],
            reverbConfig: null,
        });

        const { createReactionBadgeHost } = await import('./reaction-badge-app');
        const image = document.createElement('img');
        image.src = 'https://images.example.com/original.jpg';
        document.body.appendChild(image);

        const host = createReactionBadgeHost(image);
        document.body.appendChild(host.element);

        await flushPromises();
        await flushPromises();

        host.triggerReaction('love');
        await flushPromises();
        await flushPromises();

        image.setAttribute('src', 'https://images.example.com/variant.jpg');
        await flushPromises();
        await flushPromises();

        expect(host.element.textContent).toContain('idle');

        for (const listener of progressListeners) {
            listener({
                fileId: 123,
                transferId: 456,
                sourceUrl: 'https://images.example.com/original.jpg',
                referrerUrl: window.location.href,
                status: 'downloading',
                percent: 37,
                reaction: 'love',
                reactedAt: null,
                downloadedAt: null,
                blacklistedAt: null,
                payload: {},
            });
        }

        await flushPromises();
        await flushPromises();

        expect(host.element.textContent).toContain('downloading');
        expect(host.element.textContent).toContain('37%');

        host.unmount();
    });
});
