import { ref, watch, toRefs, type Ref } from 'vue';
import { incrementSeen, show as getFile } from '@/actions/App/Http/Controllers/FilesController';
import type { FeedItem } from '@/composables/useTabs';
import type { File } from '@/types/file';

export function useFileViewerData(params: {
    items: Ref<FeedItem[]>;
    navigation: {
        currentItemIndex: number | null;
    };
    overlay: {
        fillComplete: boolean;
    };
    sheet: {
        isOpen: boolean;
    };
}) {
    const { currentItemIndex } = toRefs(params.navigation);
    const { fillComplete } = toRefs(params.overlay);
    const { isOpen } = toRefs(params.sheet);
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

    watch(() => currentItemIndex.value, async (newIndex) => {
        if (newIndex !== null && isOpen.value && fillComplete.value) {
            const currentItem = params.items.value[newIndex];
            if (currentItem?.id) {
                await fetchFileData(currentItem.id);
            }
        }
    });

    watch(() => isOpen.value, async (open) => {
        if (open && currentItemIndex.value !== null && fillComplete.value) {
            const currentItem = params.items.value[currentItemIndex.value];
            if (currentItem?.id) {
                await fetchFileData(currentItem.id);
            }
        } else if (!open) {
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
