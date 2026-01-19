import { ref, type Ref, watch } from 'vue';
import { incrementSeen, show as getFile } from '@/actions/App/Http/Controllers/FilesController';
import type { FeedItem } from '@/composables/useTabs';
import type { File } from '@/types/file';

export function useFileViewerData(params: {
    items: Ref<FeedItem[]>;
    currentItemIndex: Ref<number | null>;
    overlayFillComplete: Ref<boolean>;
    isSheetOpen: Ref<boolean>;
}) {
    const fileData = ref<File | null>(null);
    const isLoadingFileData = ref(false);

    async function handleItemSeen(fileId: number): Promise<void> {
        try {
            const { data } = await window.axios.post<{ seen_count: number }>(incrementSeen.url(fileId));

            const item = params.items.value.find((i) => i.id === fileId);
            if (item) {
                item.seen_count = data.seen_count;
            }
        } catch (error) {
            console.error('Failed to increment seen count:', error);
        }
    }

    async function fetchFileData(fileId: number): Promise<void> {
        if (!fileId) return;

        isLoadingFileData.value = true;
        try {
            const { data } = await window.axios.get(getFile.url(fileId));
            fileData.value = data.file;
        } catch (error) {
            console.error('Failed to fetch file data:', error);
            fileData.value = null;
        } finally {
            isLoadingFileData.value = false;
        }
    }

    watch(() => params.currentItemIndex.value, async (newIndex) => {
        if (newIndex !== null && params.isSheetOpen.value && params.overlayFillComplete.value) {
            const currentItem = params.items.value[newIndex];
            if (currentItem?.id) {
                await fetchFileData(currentItem.id);
            }
        }
    });

    watch(() => params.isSheetOpen.value, async (isOpen) => {
        if (isOpen && params.currentItemIndex.value !== null && params.overlayFillComplete.value) {
            const currentItem = params.items.value[params.currentItemIndex.value];
            if (currentItem?.id) {
                await fetchFileData(currentItem.id);
            }
        } else if (!isOpen) {
            fileData.value = null;
        }
    });

    return {
        fileData,
        isLoadingFileData,
        fetchFileData,
        handleItemSeen,
    };
}
