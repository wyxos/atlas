import { nextTick, type Ref } from 'vue';
import type { MasonryItem, TabData } from './useTabs';
import type { Masonry } from '@wyxos/vibe';

interface TabInitializationDependencies {
    // Component refs
    fileViewer: Ref<{ close: () => void } | null>;
    masonry: Ref<InstanceType<typeof Masonry> | null>;

    // Component state refs
    items: Ref<MasonryItem[]>;
    selectedService: Ref<string>;

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
    async function initializeTab(tab: TabData | undefined): Promise<void> {
        if (!tab) return;

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
        } catch (error) {
            console.error('Failed to load tab items:', error);
            tab.itemsData = [];
        } finally {
            // Notify parent that tab data loading is complete
            if (deps.onTabDataLoadingChange) {
                deps.onTabDataLoadingChange(false);
            }
        }

        // Set items - Masonry will automatically call restoreItems() when init is 'auto'
        // and items are provided via v-model
        // Pagination state is managed by Masonry itself via loadAtPage prop and queryParams
        deps.items.value = tab.itemsData ?? [];
    }

    return {
        initializeTab,
    };
}

