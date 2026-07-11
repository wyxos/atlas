import { computed, ref, shallowRef } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useLocalFileDeletion } from './useLocalFileDeletion';
import type { FeedItem } from './useTabs';

const deleteRequest = vi.fn();

function makeItem(): FeedItem {
    return {
        id: 42,
        width: 100,
        height: 100,
        page: 1,
        key: 'file-42',
        index: 0,
        src: '/files/42.jpg',
        filename: 'managed-file.jpg',
        downloaded: true,
    };
}

describe('useLocalFileDeletion', () => {
    beforeEach(() => {
        deleteRequest.mockReset();
        deleteRequest.mockResolvedValue({ data: { message: 'deleted' } });
        window.axios = { delete: deleteRequest } as never;
    });

    it.each([
        { feed: 'local', initialTotal: 5, openFromFileSheet: false },
        { feed: 'online', initialTotal: 381, openFromFileSheet: true },
    ])('keeps the $feed source total immutable and lets Vibe own the visible subtraction', async ({ initialTotal, openFromFileSheet }) => {
        const item = makeItem();
        const sourceTotal = ref(initialTotal);
        const removedCount = ref(0);
        const displayedAvailable = computed(() => Math.max(0, sourceTotal.value - removedCount.value));
        const remove = vi.fn(async () => {
            removedCount.value += 1;
        });
        const deletion = useLocalFileDeletion({
            items: shallowRef(openFromFileSheet ? [] : [item]),
            masonry: ref({ remove } as never),
            isLocal: ref(!openFromFileSheet),
            clearHover: vi.fn(),
        });

        if (openFromFileSheet) {
            deletion.actions.openFromFileSheet(item);
        } else {
            deletion.actions.open(item);
        }

        expect(deletion.state.dialogOpen.value).toBe(true);
        expect(deletion.state.itemToDelete.value?.id).toBe(42);
        expect(deleteRequest).not.toHaveBeenCalled();

        await expect(deletion.actions.confirm()).resolves.toBe(true);
        expect(deleteRequest).toHaveBeenCalledWith('/api/files/42', {
            data: {
                also_from_disk: true,
                also_delete_record: true,
            },
        });
        expect(remove).toHaveBeenCalledWith(item);
        expect(sourceTotal.value).toBe(initialTotal);
        expect(removedCount.value).toBe(1);
        expect(displayedAvailable.value).toBe(initialTotal - 1);
        expect(deletion.state.dialogOpen.value).toBe(false);
    });
});
