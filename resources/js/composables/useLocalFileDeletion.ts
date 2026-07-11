import { computed, ref, shallowRef, type Ref, type ShallowRef } from 'vue';
import type { FeedItem } from '@/composables/useTabs';
import type { BrowseFeedHandle } from '@/types/browse';

type UseLocalFileDeletionOptions = {
    items: ShallowRef<FeedItem[]>;
    masonry: Ref<BrowseFeedHandle | null>;
    isLocal: Ref<boolean>;
    clearHover: () => void;
};

function getDeleteErrorMessage(error: unknown): string {
    const axiosError = error as {
        response?: {
            status?: number;
            data?: {
                message?: string;
            };
        };
    };

    const message = axiosError.response?.data?.message;
    if (typeof message === 'string' && message.trim() !== '') {
        return message;
    }

    if (axiosError.response?.status === 403) {
        return 'You do not have permission to delete this file.';
    }

    return 'Failed to delete the file. Please try again.';
}

export function useLocalFileDeletion(options: UseLocalFileDeletionOptions) {
    const dialogOpen = ref(false);
    const deleteError = ref<string | null>(null);
    const deleting = ref(false);
    const pendingItemId = ref<number | null>(null);
    const pendingItem = shallowRef<FeedItem | null>(null);

    const itemToDelete = computed(() => {
        if (pendingItemId.value === null) {
            return null;
        }

        return options.items.value.find((item) => item.id === pendingItemId.value) ?? pendingItem.value;
    });

    function canDelete(item: FeedItem): boolean {
        return options.isLocal.value && item.downloaded === true;
    }

    function setPendingItem(item: FeedItem): void {
        pendingItemId.value = item.id;
        pendingItem.value = item;
        deleteError.value = null;
        dialogOpen.value = true;
    }

    function open(item: FeedItem): void {
        if (!canDelete(item)) {
            return;
        }

        setPendingItem(item);
    }

    function openFromFileSheet(item: FeedItem): void {
        setPendingItem(item);
    }

    function close(): boolean {
        if (deleting.value) {
            return false;
        }

        dialogOpen.value = false;
        deleteError.value = null;
        pendingItemId.value = null;
        pendingItem.value = null;

        return true;
    }

    async function removeFromCurrentView(item: FeedItem): Promise<void> {
        options.clearHover();

        if (options.masonry.value) {
            try {
                await options.masonry.value.remove(item);
            } catch (error) {
                console.error('Failed to remove deleted library item from masonry:', error);
            }
        }
    }

    async function confirm(): Promise<boolean> {
        const item = itemToDelete.value;
        if (!item || deleting.value) {
            return false;
        }

        deleting.value = true;
        deleteError.value = null;
        let shouldClose = false;

        try {
            await window.axios.delete(`/api/files/${item.id}`, {
                data: {
                    also_from_disk: true,
                    also_delete_record: true,
                },
            });

            await removeFromCurrentView(item);
            shouldClose = true;
        } catch (error) {
            deleteError.value = getDeleteErrorMessage(error);
        } finally {
            deleting.value = false;

            if (shouldClose) {
                close();
            }
        }

        return shouldClose;
    }

    return {
        state: {
            dialogOpen,
            deleteError,
            deleting,
            itemToDelete,
        },
        actions: {
            canDelete,
            open,
            openFromFileSheet,
            close,
            confirm,
        },
    };
}

export type LocalFileDeletion = ReturnType<typeof useLocalFileDeletion>;
