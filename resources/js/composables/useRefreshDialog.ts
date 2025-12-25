import { ref, type Ref } from 'vue';
import type { MasonryItem, TabData } from './useTabs';
import type { Masonry } from '@wyxos/vibe';
import { items as tabsItems } from '@/actions/App/Http/Controllers/TabController';

/**
 * Composable for managing the refresh tab dialog and refresh logic.
 */
export function useRefreshDialog(
    items: Ref<MasonryItem[]>,
    masonry: Ref<InstanceType<typeof Masonry> | null>,
    tab: Ref<TabData | undefined>,
    loadTabItems: (tabId: number) => Promise<{ items: MasonryItem[]; tab: TabData }>
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

        // Refresh the tab by reloading items and metadata from database and restoring to masonry
        const { items: loadedItems, tab: tabData } = await loadTabItems(currentTab.id);

        // Update local tab data
        tab.value = tabData;

        // Restore items to masonry
        if (masonry.value?.restoreItems) {
            await masonry.value.restoreItems(loadedItems, tabData.queryParams?.page ?? 1, tabData.queryParams?.next ?? null);
        } else {
            items.value = loadedItems;
        }
    }

    return {
        refreshDialogOpen,
        openRefreshDialog,
        closeRefreshDialog,
        confirmRefreshTab,
    };
}

