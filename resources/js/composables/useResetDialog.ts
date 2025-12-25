import { ref, computed, nextTick, type Ref } from 'vue';
import type { MasonryItem, TabData } from './useTabs';
import type { Masonry } from '@wyxos/vibe';

/**
 * Composable for managing the reset to first page dialog and reset logic.
 */
export function useResetDialog(
    items: Ref<MasonryItem[]>,
    masonry: Ref<InstanceType<typeof Masonry> | null>,
    tab: Ref<TabData | undefined>,
    updateActiveTab: (itemsData: MasonryItem[]) => void
) {
    const resetDialogOpen = ref(false);

    // Check if we're on the first page
    const isOnFirstPage = computed(() => {
        const currentPage = masonry.value?.currentPage ?? tab.value?.queryParams?.page;
        return currentPage === 1 || currentPage === null || currentPage === undefined;
    });

    // Open reset dialog
    function openResetDialog(): void {
        resetDialogOpen.value = true;
    }

    // Close reset dialog
    function closeResetDialog(): void {
        resetDialogOpen.value = false;
    }

    // Reset to first page
    async function resetToFirstPage(): Promise<void> {
        const currentTab = tab.value;
        if (!currentTab) {
            return;
        }

        // Update tab data - backend will update query_params when browse request is made
        updateActiveTab([]);

        // Close dialog
        closeResetDialog();

        // Use Masonry's built-in reset() method which properly handles animations
        if (masonry.value && typeof masonry.value.reset === 'function') {
            masonry.value.reset();

            // After reset, load page 1 to populate content
            await nextTick();

            if (masonry.value && typeof masonry.value.loadPage === 'function') {
                await masonry.value.loadPage(1);
            }
        } else {
            // Fallback: manually clear items if reset() is not available
            items.value = [];
            await nextTick();
        }
    }

    return {
        resetDialogOpen,
        isOnFirstPage,
        openResetDialog,
        closeResetDialog,
        resetToFirstPage,
    };
}

