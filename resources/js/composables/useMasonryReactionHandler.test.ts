import { describe, it, expect, vi, beforeEach } from 'vitest';
import { ref } from 'vue';
import { useMasonryReactionHandler } from './useMasonryReactionHandler';
import type { FeedItem, TabData } from './useTabs';

const isLocal = ref(false);
vi.mock('@/composables/useBrowseForm', () => ({
    useBrowseForm: () => ({ isLocal }),
}));

describe('useMasonryReactionHandler', () => {
    const createItem = (id: number): FeedItem => ({
        id,
        width: 100,
        height: 100,
        page: 1,
        key: `1-${id}`,
        index: 0,
        src: 'https://example.com/thumb.jpg',
    });

    beforeEach(() => {
        isLocal.value = false;
    });

    it('loads next page when last item is reacted to in online mode', async () => {
        const items = ref<FeedItem[]>([createItem(1)]);
        const masonry = ref({
            remove: vi.fn().mockResolvedValue(undefined),
            loadNextPage: vi.fn().mockResolvedValue(undefined),
            isLoading: false,
        } as any);
        const tab = ref<TabData | undefined>({ id: 1 } as TabData);

        const { handleMasonryReaction } = useMasonryReactionHandler(
            items,
            masonry,
            tab,
            vi.fn()
        );

        await handleMasonryReaction(items.value[0], 'like');

        expect(masonry.value.remove).toHaveBeenCalledWith(items.value[0]);
        expect(masonry.value.loadNextPage).toHaveBeenCalled();
    });

    it('does not load next page in local mode', async () => {
        isLocal.value = true;

        const items = ref<FeedItem[]>([createItem(1)]);
        const masonry = ref({
            remove: vi.fn().mockResolvedValue(undefined),
            loadNextPage: vi.fn().mockResolvedValue(undefined),
            isLoading: false,
        } as any);
        const tab = ref<TabData | undefined>({ id: 1 } as TabData);

        const { handleMasonryReaction } = useMasonryReactionHandler(
            items,
            masonry,
            tab,
            vi.fn()
        );

        await handleMasonryReaction(items.value[0], 'like');

        expect(masonry.value.remove).not.toHaveBeenCalled();
        expect(masonry.value.loadNextPage).not.toHaveBeenCalled();
    });
});
