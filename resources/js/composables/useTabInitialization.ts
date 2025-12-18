import { nextTick, type Ref } from 'vue';
import type { MasonryItem, BrowseTabData } from './useBrowseTabs';

interface TabInitializationDependencies {
    // Component refs
    fileViewer: Ref<{ close: () => void } | null>;
    masonry: Ref<{
        isLoading: boolean;
        cancelLoad: () => void;
        destroy: () => void;
        refreshLayout: (items: MasonryItem[]) => void;
    } | null>;

    // Component state refs
    items: Ref<MasonryItem[]>;
    currentPage: Ref<string | number | null>;
    nextCursor: Ref<string | number | null>;
    loadAtPage: Ref<string | number | null>;
    isTabRestored: Ref<boolean>;
    selectedService: Ref<string>;

    // Composable methods
    clearPreviewedItems: () => void;

    // Props callbacks
    onTabDataLoadingChange?: (isLoading: boolean) => void;
    loadTabItems: (tabId: number) => Promise<MasonryItem[]>;
}

/**
 * Composable for handling tab initialization logic.
 * This manages the complex initialization sequence when switching tabs or mounting the component.
 */
export function useTabInitialization(deps: TabInitializationDependencies) {
    /**
     * Initialize a tab with its saved state and data.
     */
    async function initializeTab(tab: BrowseTabData | undefined): Promise<void> {
        if (!tab) return;

        // Close fileviewer
        if (deps.fileViewer.value) {
            deps.fileViewer.value.close();
        }

        // Destroy existing masonry instance
        if (deps.masonry.value) {
            if (deps.masonry.value.isLoading) {
                deps.masonry.value.cancelLoad();
            }
            deps.masonry.value.destroy();
        }

        // Reset previewed items tracking when switching tabs
        deps.clearPreviewedItems();

        // Always reload items from database when initializing a tab
        // This ensures we always have fresh data when switching tabs
        // If the tab has no files, the endpoint will return empty arrays
        try {
            // Notify parent that we're loading tab data
            if (deps.onTabDataLoadingChange) {
                deps.onTabDataLoadingChange(true);
            }
            const loadedItems = await deps.loadTabItems(tab.id);
            tab.itemsData = loadedItems;
            // fileIds will be updated by loadTabItems
        } catch (error) {
            console.error('Failed to load tab items:', error);
            tab.itemsData = [];
            tab.fileIds = [];
        } finally {
            // Notify parent that tab data loading is complete
            if (deps.onTabDataLoadingChange) {
                deps.onTabDataLoadingChange(false);
            }
        }

        // Determine if tab has restorable items after loading
        // Use fileIds as the source of truth (stored in DB) - itemsData is just a derived representation
        const tabHasRestorableItems = (tab.fileIds?.length ?? 0) > 0;
        deps.isTabRestored.value = tabHasRestorableItems;

        // Always restore page and next from tab.queryParams
        const pageValue = (tab.queryParams?.page as string | number | undefined) ?? 1;
        const nextValue = (tab.queryParams?.next as string | number | null | undefined) ?? null;

        // Set currentPage and nextCursor from tab data
        // These will be passed to Masonry via initialPage and initialNextPage props
        deps.currentPage.value = pageValue;
        deps.nextCursor.value = nextValue;

        // Simplified: Just set items and loadAtPage
        // Masonry will handle pagination state via initialPage/initialNextPage props
        if (tab.itemsData && tab.itemsData.length > 0) {
            // When we have items to restore, set items and let Masonry auto-initialize
            // Masonry will use initialPage and initialNextPage props to restore pagination state
            deps.items.value = tab.itemsData;
            deps.loadAtPage.value = null; // Skip initial load since items are provided
        } else {
            // No items to restore, set loadAtPage for initial load
            deps.loadAtPage.value = pageValue;
            deps.items.value = [];
        }

        // Reset isTabRestored after initialization is complete
        // This allows getNextPage to work normally after tab restoration
        deps.isTabRestored.value = false;
    }

    return {
        initializeTab,
    };
}

