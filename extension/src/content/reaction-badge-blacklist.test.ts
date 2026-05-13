import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockEnqueueReactionCheck = vi.fn();
const mockSubmitBadgeReaction = vi.fn();
const mockHasRelatedPostThumbnailsBelowMedia = vi.fn();

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
    collectCivitAiListingMetadataOverrides: vi.fn(),
}));

vi.mock('./reaction-check-queue', () => ({
    enqueueReactionCheck: mockEnqueueReactionCheck,
}));

vi.mock('./reaction-submit', () => ({
    submitBadgeReaction: mockSubmitBadgeReaction,
}));

vi.mock('./download-progress-bus', () => ({
    subscribeToDownloadProgress: vi.fn(() => () => {}),
}));

vi.mock('./badge-state-cache', () => ({
    getPersistedBadgeState: () => null,
    persistBadgeCheckResult: vi.fn(),
    persistBadgeState: vi.fn(),
    persistDownloadProgressEvent: vi.fn(),
}));

vi.mock('./downloaded-reaction-dialog', () => ({
    createDownloadedReactionDialog: () => ({ destroy: vi.fn(), prompt: vi.fn() }),
}));

vi.mock('./reaction-badge-runtime-style', () => ({
    ensureReactionBadgeRuntimeStyles: vi.fn(),
}));

vi.mock('./reaction-badge-tab-runtime', () => ({
    requestCloseCurrentTab: vi.fn(),
    requestTabCount: vi.fn().mockResolvedValue(2),
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

describe('createReactionBadgeHost blacklist control', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        document.body.innerHTML = '';
        history.replaceState({}, '', '/artseize/art/Untitled-1305712740');
        mockHasRelatedPostThumbnailsBelowMedia.mockReturnValue(false);
        mockEnqueueReactionCheck.mockResolvedValue({
            exists: true,
            reaction: 'like',
            reactedAt: '2026-05-10T11:00:00Z',
            downloadedAt: null,
            blacklistedAt: null,
        });
        mockSubmitBadgeReaction.mockResolvedValue({
            ok: true,
            reaction: null,
            exists: true,
            fileId: 123,
            blacklistedAt: '2026-05-10T12:00:00Z',
            downloadRequested: false,
            shouldCloseTabAfterQueue: false,
            downloadTransferId: null,
            downloadStatus: null,
            downloadProgressPercent: null,
            downloadCloseTargets: [],
            reverbConfig: null,
        });
    });

    it('submits blacklist from the badge and marks the button pressed', async () => {
        const { createReactionBadgeHost } = await import('./reaction-badge-app');
        const image = document.createElement('img');
        image.src = 'https://images.example.com/blacklist-me.jpg';
        document.body.appendChild(image);

        const host = createReactionBadgeHost(image);
        document.body.appendChild(host.element);

        await flushPromises();
        await flushPromises();

        const blacklistButton = host.element.querySelector('button[aria-label="Blacklist"]') as HTMLButtonElement | null;
        blacklistButton?.click();
        await flushPromises();
        await flushPromises();

        expect(mockSubmitBadgeReaction).toHaveBeenCalledWith(image, 'blacklist', {});

        const updated = host.element.querySelector('button[aria-label="Blacklist"]') as HTMLButtonElement | null;
        expect(updated?.getAttribute('aria-pressed')).toBe('true');
        expect(updated?.disabled).toBe(true);

        host.unmount();
    });

    it('submits blacklist from the shortcut handler', async () => {
        const { createReactionBadgeHost } = await import('./reaction-badge-app');
        const image = document.createElement('img');
        image.src = 'https://images.example.com/shortcut-blacklist-me.jpg';
        document.body.appendChild(image);

        const host = createReactionBadgeHost(image);
        document.body.appendChild(host.element);

        await flushPromises();
        await flushPromises();

        host.triggerReaction('blacklist');
        await flushPromises();
        await flushPromises();

        expect(mockSubmitBadgeReaction).toHaveBeenCalledWith(image, 'blacklist', {});

        host.unmount();
    });
});
