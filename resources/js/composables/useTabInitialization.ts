import { nextTick, type Ref } from 'vue';
import type { MasonryItem, BrowseTabData } from './useBrowseTabs';

interface TabInitializationDependencies {
    // Component refs
    fileViewer: Ref<{ close: () => void } | null>;
    masonry: Ref<{
        isLoading: boolean;
        cancelLoad: () => void;
        destroy: () => void;
        init: (items: MasonryItem[], page: string | number | null, next: string | number | null) => void;
        refreshLayout: (items: MasonryItem[]) => void;
    } | null>;

    // Component state refs
    items: Ref<MasonryItem[]>;
    currentPage: Ref<string | number | null>;
    nextCursor: Ref<string | number | null>;
    loadAtPage: Ref<string | number | null>;
    isTabRestored: Ref<boolean>;
    pendingRestoreNextCursor: Ref<string | number | null>;
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

        // Restore selected service for UI
        const serviceFromQuery = tab.queryParams?.service as string | null;
        deps.selectedService.value = serviceFromQuery || '';

        // Restore both page and next from queryParams
        const pageFromQuery = tab.queryParams?.page;
        const nextFromQuery = tab.queryParams?.next;

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
        const tabHasRestorableItems = (tab.fileIds?.length ?? 0) > 0 || (tab.itemsData?.length ?? 0) > 0;
        deps.isTabRestored.value = tabHasRestorableItems;
        // Set pendingRestoreNextCursor if we have restorable items and a next cursor from query params
        deps.pendingRestoreNextCursor.value = tabHasRestorableItems && nextFromQuery !== undefined && nextFromQuery !== null ? nextFromQuery : null;

        // Restore currentPage from saved queryParams
        if (pageFromQuery !== undefined && pageFromQuery !== null) {
            deps.currentPage.value = pageFromQuery;
        } else {
            deps.currentPage.value = 1;
        }

        // Restore nextCursor from saved queryParams
        if (nextFromQuery !== undefined && nextFromQuery !== null) {
            deps.nextCursor.value = nextFromQuery;
        } else {
            deps.nextCursor.value = null;
        }

        // Set loadAtPage and prepare for masonry initialization
        const serviceValue = tab.queryParams?.service;
        const hasService = typeof serviceValue === 'string' && serviceValue.length > 0;

        if (tab.itemsData && tab.itemsData.length > 0) {
            deps.loadAtPage.value = null;
            deps.items.value = [];
        } else if (hasService) {
            if (pageFromQuery !== undefined && pageFromQuery !== null) {
                deps.loadAtPage.value = pageFromQuery;
            } else {
                deps.loadAtPage.value = 1;
            }
            deps.items.value = [];
        } else {
            deps.loadAtPage.value = null;
            deps.items.value = [];
        }

        await nextTick();

        // Wait for masonry component to be ready - it might not be available immediately
        // Only wait if we have items to initialize with
        if (tab.itemsData && tab.itemsData.length > 0) {
            let retries = 0;
            while (!deps.masonry.value && retries < 20) {
                await nextTick();
                await new Promise(resolve => setTimeout(resolve, 50));
                retries++;
            }

            // If we have pre-loaded items, use masonry.init() to properly initialize
            if (deps.masonry.value) {
                const pageValue = pageFromQuery !== undefined && pageFromQuery !== null ? pageFromQuery : 1;
                const nextValue = nextFromQuery !== undefined && nextFromQuery !== null ? nextFromQuery : null;

                if (pageValue !== undefined && pageValue !== null) {
                    deps.currentPage.value = pageValue;
                }
                if (nextValue !== undefined && nextValue !== null) {
                    deps.nextCursor.value = nextValue;
                }

                deps.masonry.value.init(tab.itemsData, pageValue, nextValue);

                await nextTick();

                // Ensure items.value is updated with the initialized items (masonry should sync via v-model)
                // If masonry doesn't sync immediately, manually set items to preserve previewed_count
                if (deps.items.value.length === 0 && tab.itemsData.length > 0) {
                    deps.items.value = [...tab.itemsData];
                }

                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        if (deps.masonry.value && deps.items.value.length > 0) {
                            deps.masonry.value.refreshLayout(deps.items.value);
                        }
                    });
                });
            }
        }

        // Reset isTabRestored after initialization is complete
        // This allows getNextPage to work normally after tab restoration
        deps.isTabRestored.value = false;
    }

    return {
        initializeTab,
    };
}

