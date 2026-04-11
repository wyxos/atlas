import { ref, shallowRef } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useTabContentItemInteractions } from './useTabContentItemInteractions';
import type { FeedItem } from './useTabs';

describe('useTabContentItemInteractions', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        window.axios = {
            post: vi.fn().mockResolvedValue({ data: {} }),
            get: vi.fn(),
            delete: vi.fn(),
        } as typeof window.axios;
    });

    it('sends a single batched remove call for loaded-items reactions', async () => {
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
            },
            onReaction: vi.fn(),
            promptDownloadedReaction: vi.fn(),
            clearHoveredContainer: vi.fn(),
        });

        const count = await interactions.performLoadedItemsBulkAction('like');

        expect(count).toBe(2);
        expect(window.axios.post).toHaveBeenCalledWith('/api/files/reactions/batch/store', {
            reactions: [
                { file_id: 1, type: 'like' },
                { file_id: 2, type: 'like' },
            ],
        });
        expect(remove).toHaveBeenCalledTimes(1);
        expect(remove).toHaveBeenCalledWith([
            expect.objectContaining({ id: 1 }),
            expect.objectContaining({ id: 2 }),
        ]);
    });
});
