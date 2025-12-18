import { ref, type Ref } from 'vue';
import { nextTick } from 'vue';
import type { MasonryItem, BrowseTabData } from './useBrowseTabs';

/**
 * Composable for managing the refresh tab dialog and refresh logic.
 */
export function useRefreshDialog(
    items: Ref<MasonryItem[]>,
    masonry: Ref<any>,
    currentPage: Ref<string | number | null>,
    nextCursor: Ref<string | number | null>,
    loadAtPage: Ref<string | number | null>,
    tab: Ref<BrowseTabData | undefined>,
    updateActiveTab: (itemsData: MasonryItem[], queryParams: Record<string, string | number | null>) => void,
    initializeTab: (tab: BrowseTabData | undefined) => Promise<void>
) {
    const refreshDialogOpen = ref(false);

    // Open refresh dialog
    function openRefreshDialog(): void {
        refreshDialogOpen.value = true;
    }

    // Close refresh dialog
    function closeRefreshDialog(): void {
        refreshDialogOpen.value = false;
    }

    // Confirm and refresh the tab
    async function confirmRefreshTab(): Promise<void> {
        const currentTab = tab.value;
        if (!currentTab) {
            return;
        }

        // Cancel any ongoing load
        if (masonry.value?.isLoading && typeof masonry.value.cancelLoad === 'function') {
            masonry.value.cancelLoad();
        }

        // Close dialog
        closeRefreshDialog();

        // Refresh the tab by re-initializing it (as if switching to it for the first time)
        // This will:
        // - Reload items from database if they exist
        // - Preserve all query params (service, filters, etc.)
        // - Restore the tab state properly
        await initializeTab(currentTab);
    }

    return {
        refreshDialogOpen,
        openRefreshDialog,
        closeRefreshDialog,
        confirmRefreshTab,
    };
}

