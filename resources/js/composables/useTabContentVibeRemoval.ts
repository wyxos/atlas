import { computed, ref, type ComputedRef, type Ref } from 'vue';
import { detachFiles as tabsDetachFiles } from '@/actions/App/Http/Controllers/TabController';
import type { FeedItem, TabData } from '@/composables/useTabs';
import type { BrowseFeedHandle } from '@/types/browse';

type ToastApi = {
    error: (content: string) => unknown;
    success: (content: string) => unknown;
};

type UseTabContentVibeRemovalOptions = {
    tab: Ref<TabData | null>;
    getLoadedItems: () => FeedItem[];
    masonry: ComputedRef<BrowseFeedHandle | null> | Ref<BrowseFeedHandle | null>;
    isLoading: ComputedRef<boolean> | Ref<boolean>;
    toast: ToastApi;
    clearHover: () => void;
};

function uniqueItemsById(items: FeedItem[]): FeedItem[] {
    const seenIds = new Set<number>();

    return items.filter((item) => {
        if (seenIds.has(item.id)) {
            return false;
        }

        seenIds.add(item.id);
        return true;
    });
}

function itemLabel(count: number): string {
    return count === 1 ? '1 item' : `${count} items`;
}

export function useTabContentVibeRemoval(options: UseTabContentVibeRemovalOptions) {
    const dialogOpen = ref(false);
    const removingLoadedItems = ref(false);
    const removingItemIds = ref<Set<number>>(new Set());
    const loadedItemCount = computed(() => uniqueItemsById(options.getLoadedItems()).length);
    const canRemoveLoadedItems = computed(() => (
        loadedItemCount.value > 0
        && options.tab.value?.id !== undefined
        && options.masonry.value !== null
        && !options.isLoading.value
        && !removingLoadedItems.value
    ));

    function setItemRemoving(itemId: number, removing: boolean): void {
        const next = new Set(removingItemIds.value);

        if (removing) {
            next.add(itemId);
        } else {
            next.delete(itemId);
        }

        removingItemIds.value = next;
    }

    async function detachFiles(tabId: number, fileIds: number[]): Promise<void> {
        await window.axios.delete(tabsDetachFiles.url(tabId), {
            data: {
                file_ids: fileIds,
            },
        });
    }

    async function removeItemsFromVibe(items: FeedItem[]): Promise<void> {
        options.clearHover();
        await options.masonry.value?.remove(items);
    }

    async function restoreItemsToVibe(items: FeedItem[]): Promise<void> {
        await options.masonry.value?.restore(items);
    }

    async function removeItems(items: FeedItem[], failureMessage: string): Promise<boolean> {
        const targetTabId = options.tab.value?.id;
        const uniqueItems = uniqueItemsById(items);
        const fileIds = uniqueItems.map((item) => item.id);

        if (!targetTabId || uniqueItems.length === 0 || !options.masonry.value) {
            return false;
        }

        try {
            await removeItemsFromVibe(uniqueItems);
            await detachFiles(targetTabId, fileIds);
            return true;
        } catch (error) {
            console.error('Failed to remove Vibe items from tab:', error);
            await restoreItemsToVibe(uniqueItems);
            options.toast.error(failureMessage);
            return false;
        }
    }

    async function removeItem(item: FeedItem): Promise<void> {
        if (removingItemIds.value.has(item.id)) {
            return;
        }

        setItemRemoving(item.id, true);

        try {
            await removeItems([item], 'Failed to remove this item from the tab. It was restored.');
        } finally {
            setItemRemoving(item.id, false);
        }
    }

    function openLoadedItemsDialog(): void {
        if (!canRemoveLoadedItems.value) {
            return;
        }

        dialogOpen.value = true;
    }

    function cancelLoadedItemsRemoval(): void {
        if (removingLoadedItems.value) {
            return;
        }

        dialogOpen.value = false;
    }

    async function confirmLoadedItemsRemoval(): Promise<void> {
        if (removingLoadedItems.value) {
            return;
        }

        const loadedItems = uniqueItemsById(options.getLoadedItems());

        if (loadedItems.length === 0) {
            dialogOpen.value = false;
            return;
        }

        removingLoadedItems.value = true;

        try {
            const removed = await removeItems(loadedItems, 'Failed to remove loaded items from the tab. They were restored.');

            if (removed) {
                dialogOpen.value = false;
                options.toast.success(`Removed ${itemLabel(loadedItems.length)} from this tab.`);
            }
        } finally {
            removingLoadedItems.value = false;
        }
    }

    function isRemovingItem(item: FeedItem): boolean {
        return removingItemIds.value.has(item.id);
    }

    return {
        state: {
            canRemoveLoadedItems,
            dialogOpen,
            loadedItemCount,
            removingLoadedItems,
        },
        actions: {
            cancelLoadedItemsRemoval,
            confirmLoadedItemsRemoval,
            isRemovingItem,
            openLoadedItemsDialog,
            removeItem,
        },
    };
}

export type TabContentVibeRemoval = ReturnType<typeof useTabContentVibeRemoval>;
