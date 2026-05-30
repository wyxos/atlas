import { ref } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useTabContentVibeRemoval } from './useTabContentVibeRemoval';
import type { FeedItem, TabData } from './useTabs';
import type { BrowseFeedHandle } from '@/types/browse';

function createFeedItem(id: number): FeedItem {
    return {
        id,
        width: 100,
        height: 100,
        page: 1,
        key: `1-${id}`,
        index: id,
        src: `/file-${id}.jpg`,
    } as FeedItem;
}

function createTab(): TabData {
    return {
        id: 7,
        label: 'Browse',
        params: {},
        position: 0,
        isActive: true,
        updatedAt: null,
    };
}

function createHarness(items = [createFeedItem(1), createFeedItem(2)]) {
    const masonry = ref<BrowseFeedHandle>({
        cancel: vi.fn(),
        isLoading: false,
        remove: vi.fn().mockResolvedValue({ ids: [] }),
        restore: vi.fn().mockResolvedValue({ ids: [] }),
    });
    const toast = {
        error: vi.fn(),
        success: vi.fn(),
    };
    const clearHover = vi.fn();
    const removal = useTabContentVibeRemoval({
        tab: ref(createTab()),
        getLoadedItems: () => items,
        masonry,
        isLoading: ref(false),
        toast,
        clearHover,
    });

    return {
        clearHover,
        items,
        masonry,
        removal,
        toast,
    };
}

describe('useTabContentVibeRemoval', () => {
    beforeEach(() => {
        window.axios = {
            delete: vi.fn().mockResolvedValue({ data: { detached_count: 1 } }),
        } as unknown as typeof window.axios;
    });

    it('removes one item from Vibe before detaching it from the tab', async () => {
        const harness = createHarness();

        await harness.removal.actions.removeItem(harness.items[0]);

        expect(harness.clearHover).toHaveBeenCalledTimes(1);
        expect(harness.masonry.value.remove).toHaveBeenCalledWith([harness.items[0]]);
        expect(window.axios.delete).toHaveBeenCalledWith('/api/tabs/7/files', {
            data: {
                file_ids: [1],
            },
        });
        expect(harness.masonry.value.restore).not.toHaveBeenCalled();
    });

    it('restores removed Vibe items when the backend detach fails', async () => {
        const harness = createHarness();
        const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        vi.mocked(window.axios.delete).mockRejectedValue(new Error('detach failed'));

        try {
            await harness.removal.actions.removeItem(harness.items[0]);
        } finally {
            consoleError.mockRestore();
        }

        expect(harness.masonry.value.remove).toHaveBeenCalledWith([harness.items[0]]);
        expect(harness.masonry.value.restore).toHaveBeenCalledWith([harness.items[0]]);
        expect(harness.toast.error).toHaveBeenCalledWith('Failed to remove this item from the tab. It was restored.');
    });

    it('confirms and removes all unique loaded items', async () => {
        const duplicate = createFeedItem(1);
        const harness = createHarness([createFeedItem(1), duplicate, createFeedItem(2)]);

        expect(harness.removal.state.loadedItemCount.value).toBe(2);
        expect(harness.removal.state.canRemoveLoadedItems.value).toBe(true);

        harness.removal.actions.openLoadedItemsDialog();

        expect(harness.removal.state.dialogOpen.value).toBe(true);

        await harness.removal.actions.confirmLoadedItemsRemoval();

        expect(harness.masonry.value.remove).toHaveBeenCalledWith([harness.items[0], harness.items[2]]);
        expect(window.axios.delete).toHaveBeenCalledWith('/api/tabs/7/files', {
            data: {
                file_ids: [1, 2],
            },
        });
        expect(harness.removal.state.dialogOpen.value).toBe(false);
        expect(harness.toast.success).toHaveBeenCalledWith('Removed 2 items from this tab.');
    });
});
