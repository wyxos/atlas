import { computed, nextTick, ref, triggerRef, type Ref, type ShallowRef } from 'vue';
import type { MasonryInstance } from '@wyxos/vibe';
import type { FeedItem } from '@/composables/useTabs';

type UseLocalFileDeletionOptions = {
    items: ShallowRef<FeedItem[]>;
    masonry: Ref<MasonryInstance | null>;
    isLocal: Ref<boolean>;
    totalAvailable: Ref<number | null>;
    matchesActiveLocalFilters: (item: FeedItem) => boolean;
    clearHover: () => void;
};

type MasonryRemoveTarget = Parameters<MasonryInstance['remove']>[0];

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
    const deleteRecord = ref(false);
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
        deleteRecord.value = false;
        deleteError.value = null;
        dialogOpen.value = true;
    }

    function close(): void {
        if (deleting.value) {
            return;
        }

        dialogOpen.value = false;
        deleteError.value = null;
        deleteRecord.value = false;
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

    function canStayVisible(item: FeedItem): boolean {
        if (deleteRecord.value) {
            return false;
        }

        if (item.type === 'image' || item.type === 'video') {
            return typeof item.url === 'string' && item.url.length > 0;
        }

        return true;
    }

    async function updateKeptRecordState(item: FeedItem): Promise<void> {
        item.downloaded = false;
        item.will_auto_dislike = false;

        if (typeof item.url === 'string' && item.url.length > 0) {
            item.original = item.url;
            item.originalUrl = item.url;

            if (item.type === 'image' || item.type === 'video') {
                item.src = item.url;
                item.preview = item.url;
                item.thumbnail = item.url;
            }
        }

        triggerRef(options.items);
        await nextTick();
    }

    async function confirm(): Promise<void> {
        const item = itemToDelete.value;
        if (!item || deleting.value) {
            return;
        }

        deleting.value = true;
        deleteError.value = null;

        try {
            await window.axios.delete(`/api/files/${item.id}`, {
                data: {
                    also_from_disk: true,
                    also_delete_record: deleteRecord.value,
                },
            });

            const postDeleteItem = {
                ...item,
                downloaded: false,
            } as FeedItem;
            const shouldRemainVisible = canStayVisible(postDeleteItem)
                && options.matchesActiveLocalFilters(postDeleteItem);

            if (deleteRecord.value || !shouldRemainVisible) {
                decrementVisibleTotal();
                await removeFromCurrentView(item);
            } else {
                await updateKeptRecordState(item);
            }

            close();
        } catch (error) {
            deleteError.value = getDeleteErrorMessage(error);
        } finally {
            deleting.value = false;
        }
    }

    return {
        state: {
            dialogOpen,
            deleteRecord,
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
