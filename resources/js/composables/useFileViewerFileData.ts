import { ref, watch } from 'vue';
import { show as getFile } from '@/actions/App/Http/Controllers/FilesController';

export function useFileViewerFileData(
    currentItemIndex: () => number | null,
    items: () => Array<{ id: number }>,
    isSheetOpen: () => boolean,
    overlayFillComplete: () => boolean
) {
    const fileData = ref<any>(null);
    const isLoadingFileData = ref(false);

    async function fetchFileData(fileId: number): Promise<void> {
        if (!fileId) return;

        isLoadingFileData.value = true;
        try {
            const response = await window.axios.get(getFile.url(fileId));
            fileData.value = response.data.file;
        } catch (error) {
            console.error('Failed to fetch file data:', error);
            fileData.value = null;
        } finally {
            isLoadingFileData.value = false;
        }
    }

    // Watch current item index to fetch file data when it changes
    watch(() => currentItemIndex(), async (newIndex) => {
        if (newIndex !== null && isSheetOpen() && overlayFillComplete()) {
            const currentItem = items()[newIndex];
            if (currentItem?.id) {
                await fetchFileData(currentItem.id);
            }
        }
    });

    // Watch sheet open to fetch file data
    watch(() => isSheetOpen(), async (isOpen) => {
        if (isOpen && currentItemIndex() !== null && overlayFillComplete()) {
            const currentItem = items()[currentItemIndex()!];
            if (currentItem?.id) {
                await fetchFileData(currentItem.id);
            }
        } else if (!isOpen) {
            fileData.value = null; // Clear data when sheet closes
        }
    });

    return {
        fileData,
        isLoadingFileData,
        fetchFileData,
    };
}





