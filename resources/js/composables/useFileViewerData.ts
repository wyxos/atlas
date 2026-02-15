import { ref, watch, toRefs, computed, type Ref } from 'vue';
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
    const lastFetchedFileId = ref<number | null>(null);
    const fetchSequence = ref(0);

    const currentItemId = computed(() => {
        const index = currentItemIndex.value;
        if (index === null || index < 0 || index >= params.items.value.length) {
            return null;
        }
        return params.items.value[index]?.id ?? null;
    });

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

        if (lastFetchedFileId.value === fileId && fileData.value) {
            return;
        }

        const sequence = ++fetchSequence.value;

        isLoadingFileData.value = true;
        fileData.value = null;
        try {
            const { data } = await window.axios.get(getFile.url(fileId));

            // Prevent out-of-order updates when navigating quickly.
            if (sequence !== fetchSequence.value) {
                return;
            }

            fileData.value = data.file;
            lastFetchedFileId.value = fileId;
        } catch (error) {
            console.error('Failed to fetch file data:', error);
            if (sequence !== fetchSequence.value) {
                return;
            }

            fileData.value = null;
            lastFetchedFileId.value = null;
        } finally {
            if (sequence === fetchSequence.value) {
                isLoadingFileData.value = false;
            }
        }
    }

    // Keep the sheet data in sync with navigation. The overlay sets fillComplete=false during transitions,
    // so we must also react to fillComplete toggling back to true for the newly-selected item.
    watch(
        [() => currentItemId.value, () => isOpen.value, () => fillComplete.value],
        async ([fileId, open, filled]) => {
            if (!open) {
                fileData.value = null;
                isLoadingFileData.value = false;
                lastFetchedFileId.value = null;
                return;
            }

            if (!fileId) {
                fileData.value = null;
                isLoadingFileData.value = false;
                lastFetchedFileId.value = null;
                return;
            }

            // Avoid showing stale details for the new file id.
            if (lastFetchedFileId.value !== fileId) {
                fileData.value = null;
                isLoadingFileData.value = true;
            }

            if (!filled) {
                return;
            }

            await fetchFileData(fileId);
        },
        { immediate: true }
    );

    return {
        fileData,
        isLoadingFileData,
        fetchFileData,
        handleItemSeen,
    };
}
