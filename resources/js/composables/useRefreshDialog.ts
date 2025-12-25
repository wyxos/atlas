import { ref, type Ref } from 'vue';
import type { MasonryItem, TabData } from './useTabs';
import type { Masonry } from '@wyxos/vibe';

/**
 * Composable for managing the refresh tab dialog and refresh logic.
 */
export function useRefreshDialog(
    items: Ref<MasonryItem[]>,
    masonry: Ref<InstanceType<typeof Masonry> | null>,
    tab: Ref<TabData | undefined>,
    updateActiveTab: (itemsData: MasonryItem[]) => void,
    loadTabItems: (tabId: number) => Promise<MasonryItem[]>
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

        // Refresh the tab by reloading items (as if switching to it for the first time)
        // This will:
        // - Reload items from database if they exist
        // - Preserve all query params (service, filters, etc.)
        // - Restore the tab state properly
        await loadTabItems(currentTab.id);
    }

    return {
        refreshDialogOpen,
        openRefreshDialog,
        closeRefreshDialog,
        confirmRefreshTab,
    };
}

