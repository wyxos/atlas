import { computed, ref, type Ref, type ShallowRef } from 'vue';
import type { FeedItem } from '@/composables/useTabs';
import type { BrowseFeedHandle } from '@/types/browse';

type UseLocalFileDeletionOptions = {
    items: ShallowRef<FeedItem[]>;
    masonry: Ref<BrowseFeedHandle | null>;
    isLocal: Ref<boolean>;
    totalAvailable: Ref<number | null>;
    clearHover: () => void;
};

type MasonryRemoveTarget = Parameters<BrowseFeedHandle['remove']>[0];

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

    return 'Failed to delete the local file. Please try again.';
}

export function useLocalFileDeletion(options: UseLocalFileDeletionOptions) {
    const dialogOpen = ref(false);
    const deleteError = ref<string | null>(null);
    const deleting = ref(false);
    const pendingItemId = ref<number | null>(null);

    const itemToDelete = computed(() => {
        if (pendingItemId.value === null) {
            return null;
        }

        return options.items.value.find((item) => item.id === pendingItemId.value) ?? null;
    });

    function canDelete(item: FeedItem): boolean {
        return options.isLocal.value && item.downloaded === true;
    }

    function open(item: FeedItem): void {
        if (!canDelete(item)) {
            return;
        }

        pendingItemId.value = item.id;
        deleteError.value = null;
        dialogOpen.value = true;
    }

    function close(): void {
        if (deleting.value) {
            return;
        }

        dialogOpen.value = false;
        deleteError.value = null;
        pendingItemId.value = null;
    }

    function decrementVisibleTotal(): void {
        if (options.totalAvailable.value === null) {
            return;
        }

        options.totalAvailable.value = Math.max(0, options.totalAvailable.value - 1);
    }

    async function removeFromCurrentView(item: FeedItem): Promise<void> {
        options.clearHover();

        if (options.masonry.value) {
            try {
                await options.masonry.value.remove(item as unknown as MasonryRemoveTarget);
                return;
            } catch (error) {
                console.error('Failed to remove deleted local item from masonry:', error);
            }
        }

        options.items.value = options.items.value.filter((candidate) => candidate.id !== item.id);
    }

    async function confirm(): Promise<void> {
        const item = itemToDelete.value;
        if (!item || deleting.value) {
            return;
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

            decrementVisibleTotal();
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
            close,
            confirm,
        },
    };
}

export type LocalFileDeletion = ReturnType<typeof useLocalFileDeletion>;
