import { describe, expect, it, vi, beforeEach } from 'vitest';
import { ref } from 'vue';
import type { FeedItem } from './useTabs';
import { useMasonryReactionHandler } from './useMasonryReactionHandler';

const { mockQueueReaction, mockReactionCallback, mockToast } = vi.hoisted(() => ({
    mockQueueReaction: vi.fn(),
    mockReactionCallback: vi.fn(),
    mockToast: Object.assign(vi.fn(), {
        dismiss: vi.fn(),
        error: vi.fn(),
        success: vi.fn(),
        info: vi.fn(),
        warning: vi.fn(),
    }),
}));

vi.mock('@/utils/reactionQueue', () => ({
    queueReaction: mockQueueReaction,
}));

vi.mock('@/utils/reactions', () => ({
    createReactionCallback: () => mockReactionCallback,
}));

vi.mock('@/components/ui/toast/use-toast', () => ({
    useToast: () => mockToast,
    default: {},
}));

function createItem(overrides: Partial<FeedItem> = {}): FeedItem {
    return {
        id: 1,
        width: 100,
        height: 100,
        page: 1,
        key: '1-1',
        index: 0,
        src: 'https://example.com/preview.jpg',
        preview: 'https://example.com/preview.jpg',
        reaction: { type: 'dislike' },
        auto_disliked: true,
        blacklisted_at: '2026-03-19T00:00:00Z',
        blacklist_type: 'auto',
        ...overrides,
    };
}

describe('useMasonryReactionHandler', () => {
    beforeEach(() => {
        mockQueueReaction.mockReset();
        mockReactionCallback.mockReset();
        mockReactionCallback.mockResolvedValue({
            reaction: { type: 'love' },
            should_prompt_redownload: false,
        });
        mockToast.mockClear();
        mockToast.error.mockClear();
    });

    it('optimistically removes local items that stop matching the active filters and restores them on rollback', async () => {
        const item = createItem({ downloaded: false });
        const items = ref([item]);
        const masonry = ref({
            remove: vi.fn().mockResolvedValue(undefined),
            restore: vi.fn().mockResolvedValue(undefined),
        } as any);
        const onReaction = vi.fn();

        const { handleMasonryReaction } = useMasonryReactionHandler({
            items,
            masonry,
            tab: ref({ id: 5 } as any),
            isLocal: ref(true),
            matchesActiveLocalFilters: () => false,
            isPositiveOnlyLocalView: () => false,
            onReaction,
        });

        await handleMasonryReaction(item, 'love');

        expect(item.reaction).toEqual({ type: 'love' });
        expect(item.auto_disliked).toBe(false);
        expect(item.blacklisted_at).toBeNull();
        expect(masonry.value.remove).toHaveBeenCalledWith(item);
        expect(mockQueueReaction).toHaveBeenCalledWith(
            1,
            'love',
            'https://example.com/preview.jpg',
            expect.any(Function),
            items,
            expect.objectContaining({
                forceDownload: false,
                updateLocalState: false,
            }),
        );
        expect(onReaction).toHaveBeenCalledWith(1, 'love');

        const restoreCallback = mockQueueReaction.mock.calls[0]?.[3] as (() => Promise<void>) | undefined;
        expect(restoreCallback).toBeTypeOf('function');

        await restoreCallback?.();

        expect(item.reaction).toEqual({ type: 'dislike' });
        expect(item.auto_disliked).toBe(true);
        expect(item.blacklisted_at).toBe('2026-03-19T00:00:00Z');
        expect(masonry.value.restore).toHaveBeenCalledWith(item);
    });

    it('does not manually remove local filtered items when Vibe is unavailable', async () => {
        const first = createItem({ id: 1, key: '1-1' });
        const second = createItem({ id: 2, key: '1-2' });
        const third = createItem({ id: 3, key: '1-3' });
        const items = ref([first, second, third]);
        const onReaction = vi.fn();

        const { handleMasonryReaction } = useMasonryReactionHandler({
            items,
            masonry: ref(null),
            tab: ref({ id: 5 } as any),
            isLocal: ref(true),
            matchesActiveLocalFilters: () => false,
            isPositiveOnlyLocalView: () => false,
            onReaction,
        });

        await handleMasonryReaction(second, 'love');

        expect(second.reaction).toEqual({ type: 'love' });
        expect(items.value.map((item) => item.id)).toEqual([1, 2, 3]);

        const restoreCallback = mockQueueReaction.mock.calls[0]?.[3] as (() => Promise<void>) | undefined;
        expect(restoreCallback).toBeTypeOf('function');

        await restoreCallback?.();

        expect(second.reaction).toEqual({ type: 'dislike' });
        expect(items.value.map((item) => item.id)).toEqual([1, 2, 3]);
        expect(onReaction).toHaveBeenCalledWith(2, 'love');
    });

    it('prompts before queueing a new positive reaction for an already-downloaded file outside positive-only local views', async () => {
        const item = createItem({
            downloaded: true,
            reaction: { type: 'like' },
            url: 'https://example.com/original.jpg',
        });
        const items = ref([item]);
        const promptDownloadedReaction = vi.fn().mockResolvedValue('redownload');

        const { handleMasonryReaction } = useMasonryReactionHandler({
            items,
            masonry: ref(null),
            tab: ref({ id: 5 } as any),
            isLocal: ref(true),
            isPositiveOnlyLocalView: () => false,
            onReaction: vi.fn(),
            promptDownloadedReaction,
        });

        await handleMasonryReaction(item, 'love');

        expect(promptDownloadedReaction).toHaveBeenCalledTimes(1);
        expect(mockQueueReaction).toHaveBeenCalledWith(
            1,
            'love',
            'https://example.com/preview.jpg',
            expect.any(Function),
            items,
            expect.objectContaining({
                forceDownload: true,
                updateLocalState: false,
            }),
        );
    });

    it('saves positive-to-positive reactions immediately in positive-only local views', async () => {
        const item = createItem({
            reaction: { type: 'like' },
            downloaded: true,
            url: 'https://example.com/original.jpg',
        });
        const items = ref([item]);
        const promptDownloadedReaction = vi.fn().mockResolvedValue('redownload');
        const onReaction = vi.fn();

        mockReactionCallback
            .mockResolvedValueOnce({
                reaction: { type: 'love' },
                should_prompt_redownload: true,
            })
            .mockResolvedValueOnce({
                reaction: { type: 'love' },
                should_prompt_redownload: false,
            });

        const { handleMasonryReaction } = useMasonryReactionHandler({
            items,
            masonry: ref(null),
            tab: ref({ id: 5 } as any),
            isLocal: ref(true),
            matchesActiveLocalFilters: () => true,
            isPositiveOnlyLocalView: () => true,
            onReaction,
            promptDownloadedReaction,
        });

        await handleMasonryReaction(item, 'love');

        expect(item.reaction).toEqual({ type: 'love' });
        expect(mockQueueReaction).not.toHaveBeenCalled();
        expect(mockReactionCallback).toHaveBeenNthCalledWith(1, 1, 'love');
        expect(mockReactionCallback).toHaveBeenNthCalledWith(2, 1, 'love', { forceDownload: true });
        expect(promptDownloadedReaction).toHaveBeenCalledTimes(1);
        expect(mockReactionCallback.mock.invocationCallOrder[0]).toBeLessThan(promptDownloadedReaction.mock.invocationCallOrder[0]);
        expect(onReaction).toHaveBeenCalledWith(1, 'love');
    });

    it('removes immediately-saved positive reactions from masonry before the request when they no longer match local filters', async () => {
        const item = createItem({
            reaction: { type: 'like' },
        });
        const items = ref([item]);
        const masonry = ref({
            remove: vi.fn().mockResolvedValue(undefined),
            restore: vi.fn().mockResolvedValue(undefined),
        } as any);

        const { handleMasonryReaction } = useMasonryReactionHandler({
            items,
            masonry,
            tab: ref({ id: 5 } as any),
            isLocal: ref(true),
            matchesActiveLocalFilters: () => false,
            isPositiveOnlyLocalView: () => true,
            onReaction: vi.fn(),
        });

        await handleMasonryReaction(item, 'funny');

        expect(masonry.value.remove).toHaveBeenCalledWith(item);
        expect(masonry.value.remove.mock.invocationCallOrder[0]).toBeLessThan(mockReactionCallback.mock.invocationCallOrder[0]);
        expect(mockQueueReaction).not.toHaveBeenCalled();
    });

    it('restores immediate positive reactions when the save fails', async () => {
        const item = createItem({
            reaction: { type: 'like' },
        });
        const items = ref([item]);
        const masonry = ref({
            remove: vi.fn().mockResolvedValue(undefined),
            restore: vi.fn().mockResolvedValue(undefined),
        } as any);

        mockReactionCallback.mockRejectedValueOnce(new Error('API Error'));
        vi.spyOn(console, 'error').mockImplementation(() => {});

        const { handleMasonryReaction } = useMasonryReactionHandler({
            items,
            masonry,
            tab: ref({ id: 5 } as any),
            isLocal: ref(true),
            matchesActiveLocalFilters: () => false,
            isPositiveOnlyLocalView: () => true,
            onReaction: vi.fn(),
        });

        await handleMasonryReaction(item, 'love');

        expect(item.reaction).toEqual({ type: 'like' });
        expect(masonry.value.remove).toHaveBeenCalledWith(item);
        expect(masonry.value.restore).toHaveBeenCalledWith(item);
        expect(mockToast.error).toHaveBeenCalledWith(
            'Failed to save reaction',
            expect.objectContaining({
                id: 'reaction-1-error',
            }),
        );
    });

    it('removes items from masonry before queueing a dislike that falls out of a positive-only local view', async () => {
        const item = createItem({
            reaction: { type: 'like' },
        });
        const items = ref([item]);
        const masonry = ref({
            remove: vi.fn().mockResolvedValue(undefined),
            restore: vi.fn().mockResolvedValue(undefined),
        } as any);

        const { handleMasonryReaction } = useMasonryReactionHandler({
            items,
            masonry,
            tab: ref({ id: 5 } as any),
            isLocal: ref(true),
            matchesActiveLocalFilters: () => false,
            isPositiveOnlyLocalView: () => true,
            onReaction: vi.fn(),
        });

        await handleMasonryReaction(item, 'dislike');

        expect(masonry.value.remove).toHaveBeenCalledWith(item);
        expect(mockQueueReaction).toHaveBeenCalledTimes(1);
        expect(masonry.value.remove.mock.invocationCallOrder[0]).toBeLessThan(mockQueueReaction.mock.invocationCallOrder[0]);
    });

    it('does not queue an online reaction when Vibe reports the item was already removed', async () => {
        const item = createItem();
        const items = ref([item]);
        const masonry = ref({
            remove: vi.fn().mockResolvedValue({ ids: [] }),
            restore: vi.fn().mockResolvedValue(undefined),
        } as any);
        const onReaction = vi.fn();

        const { handleMasonryReaction } = useMasonryReactionHandler({
            items,
            masonry,
            tab: ref({ id: 5 } as any),
            isLocal: ref(false),
            onReaction,
        });

        await handleMasonryReaction(item, 'dislike');

        expect(masonry.value.remove).toHaveBeenCalledWith(item);
        expect(mockQueueReaction).not.toHaveBeenCalled();
        expect(onReaction).not.toHaveBeenCalled();
    });

    it('does not queue or mutate local state when the downloaded-file prompt is canceled', async () => {
        const item = createItem({
            downloaded: true,
            reaction: { type: 'like' },
            url: 'https://example.com/original.jpg',
        });
        const items = ref([item]);
        const promptDownloadedReaction = vi.fn().mockResolvedValue('cancel');

        const { handleMasonryReaction } = useMasonryReactionHandler({
            items,
            masonry: ref(null),
            tab: ref({ id: 5 } as any),
            isLocal: ref(true),
            isPositiveOnlyLocalView: () => false,
            onReaction: vi.fn(),
            promptDownloadedReaction,
        });

        await handleMasonryReaction(item, 'love');

        expect(promptDownloadedReaction).toHaveBeenCalledTimes(1);
        expect(mockQueueReaction).not.toHaveBeenCalled();
        expect(item.reaction).toEqual({ type: 'like' });
    });

    it('keeps the dislike while clearing downloaded local state when a downloaded disliked item is disliked again', async () => {
        const item = createItem({
            downloaded: true,
            type: 'image',
            url: 'https://example.com/original.jpg',
            src: '/api/files/1/preview',
            preview: '/api/files/1/preview',
            thumbnail: '/api/files/1/preview',
            original: '/api/files/1/downloaded',
            originalUrl: '/api/files/1/downloaded',
        });
        const items = ref([item]);
        const masonry = ref({
            remove: vi.fn().mockResolvedValue(undefined),
            restore: vi.fn().mockResolvedValue(undefined),
        } as any);

        const { handleMasonryReaction } = useMasonryReactionHandler({
            items,
            masonry,
            tab: ref({ id: 5 } as any),
            isLocal: ref(true),
            matchesActiveLocalFilters: (candidate) => candidate.downloaded === true,
            isPositiveOnlyLocalView: () => false,
            onReaction: vi.fn(),
        });

        await handleMasonryReaction(item, 'dislike');

        expect(item.reaction).toEqual({ type: 'dislike' });
        expect(item.downloaded).toBe(false);
        expect(item.src).toBe('https://example.com/original.jpg');
        expect(item.preview).toBe('https://example.com/original.jpg');
        expect(item.thumbnail).toBe('https://example.com/original.jpg');
        expect(item.original).toBe('https://example.com/original.jpg');
        expect(item.originalUrl).toBe('https://example.com/original.jpg');
        expect(masonry.value.remove).toHaveBeenCalledWith(item);

        const restoreCallback = mockQueueReaction.mock.calls.at(-1)?.[3] as (() => Promise<void>) | undefined;
        expect(restoreCallback).toBeTypeOf('function');

        await restoreCallback?.();

        expect(item.reaction).toEqual({ type: 'dislike' });
        expect(item.downloaded).toBe(true);
        expect(item.src).toBe('/api/files/1/preview');
        expect(item.preview).toBe('/api/files/1/preview');
        expect(item.thumbnail).toBe('/api/files/1/preview');
        expect(item.original).toBe('/api/files/1/downloaded');
        expect(item.originalUrl).toBe('/api/files/1/downloaded');
        expect(masonry.value.restore).toHaveBeenCalledWith(item);
    });
});
