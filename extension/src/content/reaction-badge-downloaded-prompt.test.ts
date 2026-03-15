import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockEnqueueReactionCheck = vi.fn();
const mockSubmitBadgeReaction = vi.fn();
const mockHasRelatedPostThumbnailsBelowMedia = vi.fn();
const mockSubscribeToDownloadProgress = vi.fn();
const mockGetPersistedBadgeState = vi.fn();
const mockPersistBadgeCheckResult = vi.fn();
const mockPersistBadgeState = vi.fn();
const mockPersistDownloadProgressEvent = vi.fn();
const mockDownloadedReactionPrompt = vi.fn();
const mockRequestTabCount = vi.fn();
const mockSubscribeToTabCountChanged = vi.fn();
const mockEnsureReactionBadgeRuntimeStyles = vi.fn();
const mockCloseTabAfterQueuePreference = {
    cycleMode: vi.fn(),
    mode: { value: 'completed' as const },
    saving: { value: false },
};
const mockReactAllItemsInPostPreference = {
    enabled: { value: false },
    refresh: vi.fn().mockResolvedValue(undefined),
    saving: { value: false },
    toggle: vi.fn().mockResolvedValue(undefined),
};

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
    getPersistedBadgeState: mockGetPersistedBadgeState,
    persistBadgeCheckResult: mockPersistBadgeCheckResult,
    persistBadgeState: mockPersistBadgeState,
    persistDownloadProgressEvent: mockPersistDownloadProgressEvent,
}));

vi.mock('./downloaded-reaction-dialog', () => ({
    createDownloadedReactionDialog: () => ({
        destroy: vi.fn(),
        prompt: mockDownloadedReactionPrompt,
    }),
}));

vi.mock('./reaction-badge-runtime-style', () => ({
    ensureReactionBadgeRuntimeStyles: mockEnsureReactionBadgeRuntimeStyles,
}));

vi.mock('./reaction-badge-tab-runtime', () => ({
    requestCloseCurrentTab: vi.fn(),
    requestTabCount: mockRequestTabCount,
    subscribeToTabCountChanged: mockSubscribeToTabCountChanged,
}));

vi.mock('./close-tab-after-queue-state', () => ({
    useCloseTabAfterQueuePreference: () => mockCloseTabAfterQueuePreference,
}));

vi.mock('./react-all-items-in-post-state', () => ({
    useReactAllItemsInPostPreference: () => mockReactAllItemsInPostPreference,
}));

function flushPromises(): Promise<void> {
    return new Promise((resolve) => {
        setTimeout(resolve, 0);
    });
}

describe('createReactionBadgeHost downloaded prompt', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
        history.replaceState({}, '', '/artseize/art/Untitled-1305712740');

        mockHasRelatedPostThumbnailsBelowMedia.mockReturnValue(false);
        mockSubscribeToDownloadProgress.mockImplementation(() => () => {});
        mockGetPersistedBadgeState.mockReturnValue(null);
        mockPersistBadgeCheckResult.mockReturnValue(undefined);
        mockPersistBadgeState.mockReturnValue(undefined);
        mockPersistDownloadProgressEvent.mockReturnValue(undefined);
        mockDownloadedReactionPrompt.mockResolvedValue('react');
        mockRequestTabCount.mockResolvedValue(2);
        mockSubscribeToTabCountChanged.mockImplementation(() => () => {});
        mockEnsureReactionBadgeRuntimeStyles.mockReturnValue(undefined);
        mockCloseTabAfterQueuePreference.cycleMode.mockReset();
        mockReactAllItemsInPostPreference.refresh.mockResolvedValue(undefined);
        mockReactAllItemsInPostPreference.toggle.mockResolvedValue(undefined);

        vi.stubGlobal('chrome', {
            runtime: {
                lastError: null,
                sendMessage: vi.fn(),
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

    it('prompts before reacting to an already-downloaded file and can skip the re-download', async () => {
        mockEnqueueReactionCheck.mockResolvedValue({
            exists: true,
            reaction: 'like',
            reactedAt: '2026-03-12T00:00:00Z',
            downloadedAt: '2026-03-12T00:00:01Z',
            blacklistedAt: null,
        });
        mockDownloadedReactionPrompt.mockResolvedValue('react');
        mockSubmitBadgeReaction.mockResolvedValue({
            ok: true,
            reaction: 'love',
            exists: true,
            fileId: 123,
            downloadRequested: false,
            shouldCloseTabAfterQueue: false,
            downloadTransferId: null,
            downloadStatus: null,
            downloadProgressPercent: null,
            downloadCloseTargets: [],
            reverbConfig: null,
        });

        const { createReactionBadgeHost } = await import('./reaction-badge-app');

        const image = document.createElement('img');
        image.src = 'https://images.example.com/already-downloaded.jpg';
        document.body.appendChild(image);

        const host = createReactionBadgeHost(image);
        document.body.appendChild(host.element);

        await flushPromises();
        await flushPromises();

        host.triggerReaction('love');
        await flushPromises();
        await flushPromises();

        expect(mockDownloadedReactionPrompt).toHaveBeenCalledTimes(1);
        expect(mockSubmitBadgeReaction).toHaveBeenCalledWith(image, 'love', {
            batchItems: null,
            downloadBehavior: 'skip',
        });

        host.unmount();
    });

    it('stops before submitting when the already-downloaded prompt is cancelled', async () => {
        mockEnqueueReactionCheck.mockResolvedValue({
            exists: true,
            reaction: 'like',
            reactedAt: '2026-03-12T00:00:00Z',
            downloadedAt: '2026-03-12T00:00:01Z',
            blacklistedAt: null,
        });
        mockDownloadedReactionPrompt.mockResolvedValue('cancel');

        const { createReactionBadgeHost } = await import('./reaction-badge-app');

        const image = document.createElement('img');
        image.src = 'https://images.example.com/already-downloaded-cancel.jpg';
        document.body.appendChild(image);

        const host = createReactionBadgeHost(image);
        document.body.appendChild(host.element);

        await flushPromises();
        await flushPromises();

        host.triggerReaction('love');
        await flushPromises();
        await flushPromises();

        expect(mockDownloadedReactionPrompt).toHaveBeenCalledTimes(1);
        expect(mockSubmitBadgeReaction).not.toHaveBeenCalled();

        host.unmount();
    });
});
