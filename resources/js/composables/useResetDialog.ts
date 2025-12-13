import { ref, computed, nextTick, type Ref } from 'vue';
import type { MasonryItem, BrowseTabData } from './useBrowseTabs';

/**
 * Composable for managing the reset to first page dialog and reset logic.
 */
export function useResetDialog(
    items: Ref<MasonryItem[]>,
    masonry: Ref<any>,
    currentPage: Ref<string | number | null>,
    nextCursor: Ref<string | number | null>,
    loadAtPage: Ref<string | number | null>,
    tab: Ref<BrowseTabData | undefined>,
    updateActiveTab: (itemsData: MasonryItem[], fileIds: number[], queryParams: Record<string, string | number | null>) => void
) {
    const resetDialogOpen = ref(false);

    // Check if we're on the first page
    const isOnFirstPage = computed(() => {
        return currentPage.value === 1 || currentPage.value === null;
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

        // Reset state first
        currentPage.value = 1;
        nextCursor.value = null;
        loadAtPage.value = 1;

        // Update tab data
        updateActiveTab([], [], {
            ...currentTab.queryParams,
            page: 1,
            next: null,
        });

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

