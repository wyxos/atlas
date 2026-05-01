import { ref, shallowRef } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useTabContentItemInteractions } from './useTabContentItemInteractions';
import type { FeedItem } from './useTabs';

const { mockQueueBatchReaction, mockQueueReaction } = vi.hoisted(() => ({
    mockQueueBatchReaction: vi.fn(),
    mockQueueReaction: vi.fn(),
}));

vi.mock('@/utils/reactionQueue', () => ({
    queueBatchReaction: mockQueueBatchReaction,
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

    it('chunks large blacklist requests before removing loaded items', async () => {
        const items = shallowRef<FeedItem[]>(
            Array.from({ length: 205 }, (_, index) => ({
                id: index + 1,
                width: 500,
                height: 500,
                page: 1,
                key: `1-${index + 1}`,
                index,
                src: `https://example.com/image${index + 1}.jpg`,
                reaction: { type: 'dislike' },
                auto_disliked: true,
                auto_dislike_rule: { id: 1, name: 'Legacy rule' },
            } as FeedItem)),
        );
        const loadedItems = ref(items.value);
        const remove = vi.fn().mockResolvedValue(undefined);

        window.axios.post = vi.fn().mockImplementation((_url: string, payload: { file_ids: number[] }) => {
            return Promise.resolve({
                data: {
                    results: payload.file_ids.map((fileId) => ({
                        id: fileId,
                        blacklisted_at: '2026-04-14T00:00:00Z',
                    })),
                },
            });
        }) as typeof window.axios.post;

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
        expect(window.axios.post).toHaveBeenCalledTimes(3);
        expect(window.axios.post).toHaveBeenNthCalledWith(1, '/api/files/blacklist/batch', {
            file_ids: Array.from({ length: 100 }, (_, index) => index + 1),
        });
        expect(window.axios.post).toHaveBeenNthCalledWith(2, '/api/files/blacklist/batch', {
            file_ids: Array.from({ length: 100 }, (_, index) => index + 101),
        });
        expect(window.axios.post).toHaveBeenNthCalledWith(3, '/api/files/blacklist/batch', {
            file_ids: Array.from({ length: 5 }, (_, index) => index + 201),
        });
        expect(remove).toHaveBeenCalledTimes(1);
        expect(remove).toHaveBeenCalledWith(items.value);
        expect(items.value[0].reaction).toBeNull();
        expect(items.value[0].auto_disliked).toBe(false);
        expect(items.value[0].auto_dislike_rule).toBeNull();
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
            reaction: { type: 'dislike' },
            auto_disliked: true,
            auto_dislike_rule: { id: 7, name: 'Auto dislike' },
            blacklist_rule: { id: 8, name: 'Container rule' },
        } as FeedItem;
        const items = shallowRef<FeedItem[]>([item]);
        const remove = vi.fn().mockResolvedValue(undefined);

        window.axios.post = vi.fn().mockResolvedValue({
            data: {
                results: [
                    {
                        id: 42,
                        blacklisted_at: '2026-04-30T00:00:00Z',
                    },
                ],
            },
        }) as typeof window.axios.post;

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
        expect(window.axios.post).toHaveBeenCalledWith('/api/files/blacklist/batch', {
            file_ids: [42],
        });
        expect(remove).toHaveBeenCalledWith([item]);
        expect(item.blacklisted_at).toBe('2026-04-30T00:00:00Z');
        expect(item.reaction).toBeNull();
        expect(item.auto_disliked).toBe(false);
        expect(item.auto_dislike_rule).toBeNull();
        expect(item.blacklist_rule).toBeNull();
        expect(item.previewed_count).toBe(99999);
    });

    it('removes all locally loaded items after blacklist even when active filters still match', async () => {
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

        window.axios.post = vi.fn().mockResolvedValue({
            data: {
                results: [
                    {
                        id: 1,
                        blacklisted_at: '2026-04-29T00:00:00Z',
                        previewed_count: 99999,
                    },
                    {
                        id: 2,
                        blacklisted_at: '2026-04-30T00:00:00Z',
                        previewed_count: 99999,
                    },
                ],
            },
        }) as typeof window.axios.post;

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
        expect(remove).toHaveBeenCalledWith(items.value);
        expect(items.value.map((item) => item.previewed_count)).toEqual([99999, 99999]);
    });
});
