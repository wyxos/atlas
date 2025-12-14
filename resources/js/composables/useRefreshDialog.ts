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
    updateActiveTab: (itemsData: MasonryItem[], fileIds: number[], queryParams: Record<string, string | number | null>) => void,
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

        // Reset to page 1 and reload
        currentPage.value = 1;
        nextCursor.value = null;
        loadAtPage.value = 1;
        items.value = [];

        // Update tab query params
        const updatedQueryParams: Record<string, string | number | null> = {
            ...currentTab.queryParams,
            page: 1,
            next: null,
        };

        updateActiveTab([], [], updatedQueryParams);

        // Close dialog
        closeRefreshDialog();

        // Reset masonry and reload
        if (masonry.value) {
            if (typeof masonry.value.reset === 'function') {
                masonry.value.reset();
                await nextTick();
                if (typeof masonry.value.loadPage === 'function') {
                    await masonry.value.loadPage(1);
                }
            } else {
                masonry.value.destroy();
                await nextTick();
                await initializeTab(currentTab);
            }
        } else {
            await initializeTab(currentTab);
        }
    }

    return {
        refreshDialogOpen,
        openRefreshDialog,
        closeRefreshDialog,
        confirmRefreshTab,
    };
}

