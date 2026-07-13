import { ref, shallowRef } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useTabContentItemInteractions } from './useTabContentItemInteractions';
import type { FeedItem } from './useTabs';

const { mockQueueBatchBlacklist, mockQueueBatchReaction, mockQueueBlacklist, mockQueueReaction } = vi.hoisted(() => ({
    mockQueueBatchBlacklist: vi.fn(),
    mockQueueBatchReaction: vi.fn(),
    mockQueueBlacklist: vi.fn(),
    mockQueueReaction: vi.fn(),
}));

vi.mock('@/utils/reactionQueue', () => ({
    queueBatchBlacklist: mockQueueBatchBlacklist,
    queueBatchReaction: mockQueueBatchReaction,
    queueBlacklist: mockQueueBlacklist,
    queueReaction: mockQueueReaction,
}));

describe('useTabContentItemInteractions', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        window.axios = {
            post: vi.fn().mockResolvedValue({ data: {} }),
            get: vi.fn(),
            delete: vi.fn(),
        } as typeof window.axios;
    });

    it('queues loaded-items reactions through the batch reaction queue', async () => {
        const items = shallowRef<FeedItem[]>([
            {
                id: 1,
                width: 500,
                height: 500,
                page: 1,
                key: '1-1',
                index: 0,
                src: 'https://example.com/image1.jpg',
            } as FeedItem,
            {
                id: 2,
                width: 500,
                height: 500,
                page: 1,
                key: '1-2',
                index: 1,
                src: 'https://example.com/image2.jpg',
            } as FeedItem,
        ]);
        const loadedItems = ref(items.value);
        const remove = vi.fn().mockResolvedValue(undefined);

        const interactions = useTabContentItemInteractions({
            items,
            loadedItems,
            tab: ref(null),
            form: {
                isLocal: ref(false),
                data: {
                    feed: 'online',
                },
            } as any,
            masonry: ref({ remove } as any),
            fileViewer: ref(null),
            itemPreview: {
                incrementPreviewCount: vi.fn(),
                clearPreviewedItems: vi.fn(),
                markPreviewedItems: vi.fn(),
            },
            onReaction: vi.fn(),
            promptDownloadedReaction: vi.fn(),
            clearHoveredContainer: vi.fn(),
        });

        const count = await interactions.performLoadedItemsBulkAction('like');

        expect(count).toBe(2);
        expect(remove).toHaveBeenCalledTimes(1);
        expect(remove).toHaveBeenCalledWith([
            expect.objectContaining({ id: 1 }),
            expect.objectContaining({ id: 2 }),
        ]);
        expect(mockQueueBatchReaction).toHaveBeenCalledTimes(1);
        expect(mockQueueBatchReaction).toHaveBeenCalledWith(
            [1, 2],
            'like',
            [
                { fileId: 1, thumbnail: 'https://example.com/image1.jpg' },
                { fileId: 2, thumbnail: 'https://example.com/image2.jpg' },
            ],
            undefined,
            items,
            { updateLocalState: false },
        );
    });

    it('does not manually remove loaded items when Vibe is unavailable', async () => {
        const items = shallowRef<FeedItem[]>([
            {
                id: 1,
                width: 500,
                height: 500,
                page: 1,
                key: '1-1',
                index: 0,
                src: 'https://example.com/image1.jpg',
            } as FeedItem,
            {
                id: 2,
                width: 500,
                height: 500,
                page: 1,
                key: '1-2',
                index: 1,
                src: 'https://example.com/image2.jpg',
            } as FeedItem,
        ]);
        const loadedItems = ref(items.value);

        const interactions = useTabContentItemInteractions({
            items,
            loadedItems,
            tab: ref(null),
            form: {
                isLocal: ref(false),
                data: {
                    feed: 'online',
                },
            } as any,
            masonry: ref(null),
            fileViewer: ref(null),
            itemPreview: {
                incrementPreviewCount: vi.fn(),
                clearPreviewedItems: vi.fn(),
                markPreviewedItems: vi.fn(),
            },
            onReaction: vi.fn(),
            promptDownloadedReaction: vi.fn(),
            clearHoveredContainer: vi.fn(),
        });

        const count = await interactions.performLoadedItemsBulkAction('like');

        expect(count).toBe(2);
        expect(items.value.map((item) => item.id)).toEqual([1, 2]);
        expect(mockQueueBatchReaction).toHaveBeenCalledTimes(1);
    });

    it('retries dynamic source media once after an asset error and resets after a successful load', () => {
        const item = {
            id: 42,
            width: 500,
            height: 500,
            page: 1,
            key: '1-42',
            index: 0,
            src: '/api/files/42/source-media/preview',
            preview: '/api/files/42/source-media/preview',
            original: '/api/files/42/source-media/original',
            originalUrl: '/api/files/42/source-media/original',
            thumbnail: '/api/files/42/source-media/preview',
            capabilities: {
                refresh_source_media: true,
                dynamic_source_media: true,
                watch_source_and_refresh: false,
                unwatch_source_account: false,
            },
        } as FeedItem;
        const items = shallowRef<FeedItem[]>([item]);
        const incrementPreviewCount = vi.fn();
        const interactions = useTabContentItemInteractions({
            items,
            loadedItems: ref(items.value),
            tab: ref(null),
            form: { isLocal: ref(false), data: { feed: 'online' } } as any,
            masonry: ref(null),
            fileViewer: ref(null),
            itemPreview: {
                incrementPreviewCount,
                clearPreviewedItems: vi.fn(),
                markPreviewedItems: vi.fn(),
            },
            onReaction: vi.fn(),
            promptDownloadedReaction: vi.fn(),
            clearHoveredContainer: vi.fn(),
        });

        interactions.preload.onBatchFailures([{ item, error: new Error('load failed') }]);
        const firstRetryUrl = item.src;

        expect(firstRetryUrl).toContain('refresh=1');
        expect(firstRetryUrl).toContain('retry=1');
        expect(item.original).toContain('refresh=1');

        interactions.preload.onBatchFailures([{ item, error: new Error('still failed') }]);
        expect(item.src).toBe(firstRetryUrl);

        interactions.preload.onBatchPreloaded([item]);
        interactions.preload.onBatchFailures([{ item, error: new Error('failed later') }]);

        expect(item.src).toContain('retry=2');
        expect(incrementPreviewCount).toHaveBeenCalledWith(42);
        expect(window.axios.post).not.toHaveBeenCalledWith('/api/files/42/preview-failure');
    });

    it('clears hover state when reacting to a hovered online item that is removed from view', async () => {
        const item = {
            id: 1,
            width: 500,
            height: 500,
            page: 1,
            key: '1-1',
            index: 0,
            src: 'https://example.com/image1.jpg',
            preview: 'https://example.com/image1.jpg',
        } as FeedItem;
        const items = shallowRef<FeedItem[]>([item]);
        const clearHoveredContainer = vi.fn();
        const remove = vi.fn().mockResolvedValue(undefined);

        const interactions = useTabContentItemInteractions({
            items,
            loadedItems: ref(items.value),
            tab: ref(null),
            form: {
                isLocal: ref(false),
                data: {
                    feed: 'online',
                },
            } as any,
            masonry: ref({ remove } as any),
            fileViewer: ref(null),
            itemPreview: {
                incrementPreviewCount: vi.fn(),
                clearPreviewedItems: vi.fn(),
                markPreviewedItems: vi.fn(),
            },
            onReaction: vi.fn(),
            promptDownloadedReaction: vi.fn(),
            clearHoveredContainer,
        });

        interactions.item.onMouseEnter({ currentTarget: document.createElement('div') } as MouseEvent, item);
        interactions.reactions.onFileReaction(item, 'like');
        await Promise.resolve();

        expect(interactions.state.hoveredItemId.value).toBeNull();
        expect(clearHoveredContainer).toHaveBeenCalledTimes(1);
        expect(remove).toHaveBeenCalledWith(item);
    });

    it('queues large blacklist batches before finalizing the backend update', async () => {
        const items = shallowRef<FeedItem[]>(
            Array.from({ length: 205 }, (_, index) => ({
                id: index + 1,
                width: 500,
                height: 500,
                page: 1,
                key: `1-${index + 1}`,
                index,
                src: `https://example.com/image${index + 1}.jpg`,
                reaction: { type: 'like' },
                auto_blacklisted: true,
                auto_blacklist_rule: { id: 1, name: 'Legacy rule' },
            } as FeedItem)),
        );
        const loadedItems = ref(items.value);
        const remove = vi.fn().mockResolvedValue(undefined);

        const interactions = useTabContentItemInteractions({
            items,
            loadedItems,
            tab: ref(null),
            form: {
                isLocal: ref(false),
                data: {
                    feed: 'online',
                },
            } as any,
            masonry: ref({ remove } as any),
            fileViewer: ref(null),
            itemPreview: {
                incrementPreviewCount: vi.fn(),
                clearPreviewedItems: vi.fn(),
                markPreviewedItems: vi.fn(),
            },
            onReaction: vi.fn(),
            promptDownloadedReaction: vi.fn(),
            clearHoveredContainer: vi.fn(),
        });

        const count = await interactions.performLoadedItemsBulkAction('blacklist');

        expect(count).toBe(205);
        expect(remove).toHaveBeenCalledTimes(1);
        expect(remove).toHaveBeenCalledWith(items.value);
        expect(mockQueueBatchBlacklist).toHaveBeenCalledTimes(1);
        expect(mockQueueBatchBlacklist).toHaveBeenCalledWith(
            Array.from({ length: 205 }, (_, index) => index + 1),
            Array.from({ length: 205 }, (_, index) => ({
                fileId: index + 1,
                thumbnail: `https://example.com/image${index + 1}.jpg`,
            })),
            undefined,
            items,
            expect.objectContaining({ onSuccess: expect.any(Function) }),
        );

        const onSuccess = mockQueueBatchBlacklist.mock.calls[0]?.[4]?.onSuccess as ((results: Array<{ id: number; blacklisted_at: string }>) => void) | undefined;
        onSuccess?.([
            {
                id: 1,
                blacklisted_at: '2026-04-14T00:00:00Z',
            },
        ]);

        expect(items.value[0].reaction).toBeNull();
        expect(items.value[0].auto_blacklisted).toBe(false);
        expect(items.value[0].auto_blacklist_rule).toBeNull();
        expect(items.value[0].blacklisted_at).toBe('2026-04-14T00:00:00Z');
        expect(items.value[0].previewed_count).toBe(99999);
    });

    it('blacklists a single item through the shared blacklist action', async () => {
        const item = {
            id: 42,
            width: 500,
            height: 500,
            page: 1,
            key: '1-42',
            index: 0,
            src: 'https://example.com/image42.jpg',
            reaction: { type: 'like' },
            auto_blacklisted: true,
            auto_blacklist_rule: { id: 7, name: 'Auto blacklist' },
            blacklist_rule: { id: 8, name: 'Container rule' },
        } as FeedItem;
        const items = shallowRef<FeedItem[]>([item]);
        const remove = vi.fn().mockResolvedValue(undefined);

        const interactions = useTabContentItemInteractions({
            items,
            tab: ref(null),
            form: {
                isLocal: ref(false),
                data: {
                    feed: 'online',
                },
            } as any,
            masonry: ref({ remove } as any),
            fileViewer: ref(null),
            itemPreview: {
                incrementPreviewCount: vi.fn(),
                clearPreviewedItems: vi.fn(),
                markPreviewedItems: vi.fn(),
            },
            onReaction: vi.fn(),
            promptDownloadedReaction: vi.fn(),
            clearHoveredContainer: vi.fn(),
        });

        const count = await interactions.reactions.onFileBlacklist(item);

        expect(count).toBe(1);
        expect(remove).toHaveBeenCalledWith([item]);
        expect(mockQueueBlacklist).toHaveBeenCalledTimes(1);
        expect(mockQueueBlacklist).toHaveBeenCalledWith(
            42,
            'https://example.com/image42.jpg',
            undefined,
            items,
            expect.objectContaining({ onSuccess: expect.any(Function) }),
        );

        const onSuccess = mockQueueBlacklist.mock.calls[0]?.[4]?.onSuccess as ((results: Array<{ id: number; blacklisted_at: string }>) => void) | undefined;
        onSuccess?.([
            {
                id: 42,
                blacklisted_at: '2026-04-30T00:00:00Z',
            },
        ]);

        expect(item.blacklisted_at).toBe('2026-04-30T00:00:00Z');
        expect(item.reaction).toBeNull();
        expect(item.auto_blacklisted).toBe(false);
        expect(item.auto_blacklist_rule).toBeNull();
        expect(item.blacklist_rule).toBeNull();
        expect(item.previewed_count).toBe(99999);
    });

    it('clears blacklist for a blacklisted item in the local blacklisted view', async () => {
        const item = {
            id: 42,
            width: 500,
            height: 500,
            page: 1,
            key: '1-42',
            index: 0,
            src: 'https://example.com/image42.jpg',
            blacklisted_at: '2026-04-30T00:00:00Z',
            auto_blacklisted: true,
            auto_blacklist_rule: { id: 7, name: 'Auto blacklist' },
            previewed_count: 99999,
        } as FeedItem;
        const items = shallowRef<FeedItem[]>([item]);
        const remove = vi.fn().mockResolvedValue(undefined);

        (window.axios.delete as any).mockResolvedValue({
            data: {
                file: {
                    id: 42,
                    blacklisted_at: null,
                    auto_blacklisted: false,
                    previewed_count: 4,
                },
            },
        });

        const interactions = useTabContentItemInteractions({
            items,
            tab: ref(null),
            form: {
                isLocal: ref(true),
                data: {
                    feed: 'local',
                    serviceFilters: {
                        blacklisted: 'yes',
                    },
                },
            } as any,
            masonry: ref({ remove, restore: vi.fn() } as any),
            fileViewer: ref(null),
            itemPreview: {
                incrementPreviewCount: vi.fn(),
                clearPreviewedItems: vi.fn(),
                markPreviewedItems: vi.fn(),
            },
            onReaction: vi.fn(),
            promptDownloadedReaction: vi.fn(),
            clearHoveredContainer: vi.fn(),
            matchesActiveLocalFilters: (candidate) => Boolean(candidate.blacklisted_at),
            isBlacklistedOnlyLocalView: () => true,
        });

        const count = await interactions.reactions.onFileBlacklist(item);

        expect(count).toBe(1);
        expect(remove).toHaveBeenCalledWith(item);
        expect(window.axios.delete).toHaveBeenCalledWith('/api/files/42/blacklist');
        expect(mockQueueBlacklist).not.toHaveBeenCalled();
        expect(item.blacklisted_at).toBeNull();
        expect(item.auto_blacklisted).toBe(false);
        expect(item.auto_blacklist_rule).toBeNull();
        expect(item.previewed_count).toBe(4);
    });

    it('keeps locally loaded blacklist items visible when active filters still match and restores them on undo', async () => {
        const items = shallowRef<FeedItem[]>([
            {
                id: 1,
                width: 500,
                height: 500,
                page: 1,
                key: '1-1',
                index: 0,
                src: 'https://example.com/image1.jpg',
                blacklisted_at: '2026-04-29T00:00:00Z',
                previewed_count: 1,
            } as FeedItem,
            {
                id: 2,
                width: 500,
                height: 500,
                page: 1,
                key: '1-2',
                index: 1,
                src: 'https://example.com/image2.jpg',
                previewed_count: 0,
            } as FeedItem,
        ]);
        const remove = vi.fn().mockResolvedValue(undefined);

        const interactions = useTabContentItemInteractions({
            items,
            loadedItems: ref(items.value),
            tab: ref(null),
            form: {
                isLocal: ref(true),
                data: {
                    feed: 'local',
                },
            } as any,
            masonry: ref({ remove } as any),
            fileViewer: ref(null),
            itemPreview: {
                incrementPreviewCount: vi.fn(),
                clearPreviewedItems: vi.fn(),
                markPreviewedItems: vi.fn(),
            },
            onReaction: vi.fn(),
            promptDownloadedReaction: vi.fn(),
            clearHoveredContainer: vi.fn(),
            matchesActiveLocalFilters: vi.fn(() => true),
        });

        const count = await interactions.performLoadedItemsBulkAction('blacklist');

        expect(count).toBe(2);
        expect(remove).not.toHaveBeenCalled();
        expect(mockQueueBatchBlacklist).toHaveBeenCalledTimes(1);
        expect(items.value.map((item) => item.previewed_count)).toEqual([99999, 99999]);

        const restoreCallback = mockQueueBatchBlacklist.mock.calls[0]?.[2] as (() => Promise<void>) | undefined;
        await restoreCallback?.();

        expect(items.value.map((item) => item.previewed_count)).toEqual([1, 0]);
        expect(items.value[1].blacklisted_at).toBeNull();
    });
});
