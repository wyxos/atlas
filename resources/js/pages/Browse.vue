<script setup lang="ts">
import { ref, computed, onMounted, nextTick } from 'vue';
import { Masonry } from '@wyxos/vibe';
import { Loader2, Plus } from 'lucide-vue-next';
import Pill from '../components/ui/Pill.vue';
import TabPanel from '../components/ui/TabPanel.vue';
import BrowseTab from '../components/BrowseTab.vue';
import { Button } from '@/components/ui/button';
import { useBrowseTabs, type MasonryItem, type BrowseTabData } from '../composables/useBrowseTabs';

type GetPageResult = {
    items: MasonryItem[];
    nextPage: string | number | null; // Can be cursor string or number
};

const items = ref<MasonryItem[]>([]);
const masonry = ref<InstanceType<typeof Masonry> | null>(null);
const currentPage = ref<string | number | null>(1); // Starts as 1, becomes cursor string, can be null
const nextCursor = ref<string | number | null>(null); // The next page/cursor from API (service handles format)
const loadAtPage = ref<string | number | null>(null); // Initial page to load - set to 1 only when needed, null prevents auto-load
const isTabRestored = ref(false); // Track if we restored from a tab to prevent duplicate loading
const pendingRestoreNextCursor = ref<string | number | null>(null); // Holds the saved cursor we should load first after restoring a tab
const isPanelMinimized = ref(false);

// Computed property to display page value (defaults to 1 if null)
const displayPage = computed(() => currentPage.value ?? 1);

// Tab switching function - needs to stay here as it interacts with masonry
async function switchTab(tabId: number): Promise<void> {
    const tab = tabs.value.find(t => t.id === tabId);
    if (!tab) {
        return;
    }

    // Destroy and re-initialize masonry instance for clean state
    if (masonry.value) {
        if (masonry.value.isLoading) {
            masonry.value.cancelLoad();
        }
        // Destroy the instance to reset all internal state
        masonry.value.destroy();
    }

    activeTabId.value = tabId;
    const tabHasRestorableItems = (tab.fileIds?.length ?? 0) > 0 || (tab.itemsData?.length ?? 0) > 0;
    isTabRestored.value = tabHasRestorableItems;

    // Restore both page and next from queryParams (service handles format conversion)
    // IMPORTANT: Always restore from saved queryParams - don't default to 1 if the tab has been scrolled
    const pageFromQuery = tab.queryParams?.page;
    const nextFromQuery = tab.queryParams?.next;
    pendingRestoreNextCursor.value = tabHasRestorableItems ? (nextFromQuery ?? null) : null;

    // Check if tab has files - if so, load items lazily FIRST
    // This ensures we have the full tab state before setting pagination
    if (tab.fileIds && tab.fileIds.length > 0 && tab.itemsData.length === 0) {
        // Load items for this tab
        try {
            const loadedItems = await loadTabItems(tabId);
            tab.itemsData = loadedItems;
        } catch (error) {
            console.error('Failed to load tab items:', error);
            // Continue with empty items
        }
    }

    // Restore currentPage from saved queryParams AFTER loading items
    // Only default to 1 if page is truly missing (new tab that hasn't loaded anything yet)
    if (pageFromQuery !== undefined && pageFromQuery !== null) {
        currentPage.value = pageFromQuery;
    } else {
        // No page in queryParams - this is a new tab, start at page 1
        currentPage.value = 1;
    }

    // Restore nextCursor from saved queryParams
    if (nextFromQuery !== undefined && nextFromQuery !== null) {
        nextCursor.value = nextFromQuery;
    } else {
        nextCursor.value = null;
    }

    // Set loadAtPage and prepare for masonry initialization
    if (tab.itemsData && tab.itemsData.length > 0) {
        // We have pre-loaded items - set loadAtPage to null to prevent auto-load
        // We'll use masonry.init() to properly set up pagination state
        loadAtPage.value = null;
        // Clear items first - init() will add them back
        items.value = [];
    } else {
        // No items - check if we have query params to restore, otherwise start from beginning
        // Note: currentPage and nextCursor are already set from query params above, don't reset them
        if (pageFromQuery !== undefined && pageFromQuery !== null) {
            // We have a page from query params, use it for loading
            loadAtPage.value = pageFromQuery;
        } else {
            // No query params, start from beginning
            loadAtPage.value = 1;
            // currentPage is already set to 1 above if no query params, so no need to reset
        }
        items.value = [];
    }

    // Wait for next tick to ensure masonry component is ready
    await nextTick();

    // If we have pre-loaded items, use masonry.init() to properly initialize
    // This sets up pagination history and prevents auto-loading
    if (tab.itemsData && tab.itemsData.length > 0 && masonry.value) {
        const pageValue = pageFromQuery !== undefined && pageFromQuery !== null ? pageFromQuery : 1;
        const nextValue = nextFromQuery !== undefined && nextFromQuery !== null ? nextFromQuery : null;

        // Ensure currentPage and nextCursor are set before init (they should already be set above, but double-check)
        // This ensures displayPage computed shows the correct value
        if (pageValue !== undefined && pageValue !== null) {
            currentPage.value = pageValue;
        }
        if (nextValue !== undefined && nextValue !== null) {
            nextCursor.value = nextValue;
        }

        // Initialize masonry with pre-loaded items, current page, and next page
        // init() will add items to masonry and set up pagination history correctly
        masonry.value.init(tab.itemsData, pageValue, nextValue);

        // Wait for DOM to update
        await nextTick();

        // After init, the layout will be calculated, but container dimensions might not be final yet
        // The masonry component's ResizeObserver will handle dimension changes automatically
        // However, we need to ensure layout refreshes when container is ready
        // Use requestAnimationFrame (not a timeout) to wait for browser render cycle
        // This ensures container has final dimensions after tab panel animation completes
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                // Refresh layout after container dimensions have settled
                if (masonry.value && items.value.length > 0) {
                    masonry.value.refreshLayout(items.value);
                }
            });
        });
    }

    // Reset the flag - masonry is now properly initialized
    isTabRestored.value = false;
}

// Tab management using composable - pass switchTab callback for UI handling
const {
    tabs,
    activeTabId,
    isLoadingTabs,
    loadTabs: loadTabsFromComposable,
    createTab,
    closeTab,
    getActiveTab,
    updateActiveTab,
    loadTabItems,
} = useBrowseTabs(switchTab);

const layout = {
    gutterX: 12,
    gutterY: 12,
    sizes: { base: 1, sm: 2, md: 3, lg: 4, '2xl': 10 },
};

async function getNextPage(page: number | string): Promise<GetPageResult> {
    // If we're restoring a tab and already have items, and masonry is trying to load page 1,
    // If we're restoring a tab and already have items, prevent any loading
    // Masonry should only load when user scrolls to bottom, not during restoration
    if (isTabRestored.value) {
        // Return empty result to prevent loading during tab restoration
        return {
            items: [],
            nextPage: nextCursor.value,
        };
    }

    // Determine actual cursor/page to request. When restoring, Masonry may request page 1.
    let pageToRequest: string | number = page;
    if (pendingRestoreNextCursor.value !== null) {
        pageToRequest = pendingRestoreNextCursor.value;
        pendingRestoreNextCursor.value = null;
    }

    // Always pass as 'page' parameter - service will handle conversion
    const url = new URL('/api/browse', window.location.origin);
    url.searchParams.set('page', String(pageToRequest));

    const response = await window.axios.get(url.toString());
    const data = response.data;

    // Update currentPage to the page we just loaded
    // Only skip if we're restoring a tab and already have items (to prevent reset during restoration)
    if (!isTabRestored.value || items.value.length === 0) {
        // Update current page to the page/cursor we just used
        currentPage.value = pageToRequest;
    }

    // Update next cursor from API response (for local state, used for loading more)
    nextCursor.value = data.nextPage; // This is the cursor/page string from CivitAI

    // Update active tab with new items - this is the single source of truth for tab updates
    // Only update if we're not restoring a tab (to prevent overwriting restored state)
    if (activeTabId.value && !isTabRestored.value) {
        const activeTab = getActiveTab();
        if (activeTab) {
            // Append new items to existing items (masonry will update items.value separately)
            const updatedItemsData = [...activeTab.itemsData, ...data.items];
            // Extract database file IDs from all items in the tab
            const updatedFileIds = updatedItemsData.map(item => item.id);
            // Store both page and next in queryParams (service handles format conversion)
            const updatedQueryParams = {
                ...activeTab.queryParams,
                page: pageToRequest,
                next: data.nextPage,
            };
            updateActiveTab(updatedItemsData, updatedFileIds, updatedQueryParams);
        }
    }

    return {
        items: data.items,
        nextPage: data.nextPage, // Pass cursor to Masonry for next request
    };
}


// Tab management function
// Flow: Load tabs (without files) > Determine focus tab > If has files, load them > Restore query params
async function loadTabs(): Promise<void> {
    try {
        // Step 1: Load all tabs without files (items_data is not included)
        await loadTabsFromComposable();

        // Step 2: Determine which tab to focus (default to first tab if tabs exist)
        if (tabs.value.length > 0 && activeTabId.value === null) {
            const firstTab = tabs.value[0];
            activeTabId.value = firstTab.id;

            // Step 3: If tab has files, load items lazily
            // Step 4: Restore query params (handled in switchTab)
            await switchTab(firstTab.id);
        }
        // If no tabs exist, render nothing until a tab is created
    } catch (error) {
        // Error already logged in composable
        // Don't create a tab on error - let user create manually
    }
}




// Initialize on mount
onMounted(async () => {
    // Load tabs - loadTabs will set the first tab as active if tabs exist
    await loadTabs();
});
</script>

<template>
    <div class="h-full flex flex-col">
        <div class="flex-1 min-h-0 relative flex">
            <TabPanel :model-value="true" v-model:is-minimized="isPanelMinimized">
                <template #tabs="{ isMinimized }">
                    <BrowseTab v-for="tab in tabs" :key="tab.id" :id="tab.id" :label="tab.label"
                        :is-active="tab.id === activeTabId" :is-minimized="isMinimized" @click="switchTab(tab.id)"
                        @close="closeTab(tab.id)" :data-test="`browse-tab-${tab.id}`" />
                </template>
                <template #footer="{ isMinimized }">
                    <Button variant="dashed" size="sm" @click="createTab"
                        :class="['w-full rounded h-8', isMinimized ? 'justify-center' : 'justify-start']"
                        aria-label="New tab" data-test="create-tab-button">
                        <Plus :size="16" />
                        <span v-show="!isMinimized" class="ml-2 transition-opacity duration-200"
                            :class="!isMinimized ? 'opacity-100' : 'opacity-0'">New Tab</span>
                    </Button>
                </template>
            </TabPanel>
            <div class="flex-1 min-h-0 transition-all duration-300 flex flex-col">
                <!-- Masonry Content -->
                <div class="flex-1 min-h-0">
                    <Masonry v-if="activeTabId !== null" :key="activeTabId" ref="masonry" v-model:items="items"
                        :get-next-page="getNextPage" :load-at-page="loadAtPage" :layout="layout" layout-mode="auto"
                        :mobile-breakpoint="768" :skip-initial-load="items.length > 0" data-test="masonry-component" />
                    <div v-else class="flex items-center justify-center h-full" data-test="no-tabs-message">
                        <p class="text-twilight-indigo-300 text-lg">Create a tab to start browsing</p>
                    </div>
                </div>

                <!-- Status/Pagination Info at Bottom -->
                <div v-if="activeTabId !== null" class="my-2 flex flex-wrap items-center justify-center gap-3"
                    data-test="pagination-info">
                    <!-- Count Pill -->
                    <Pill label="Items" :value="items.length" variant="primary" reversed data-test="items-pill" />
                    <!-- Current Page Pill -->
                    <Pill label="Page" :value="displayPage" variant="neutral" reversed data-test="page-pill" />
                    <!-- Next Page Pill -->
                    <Pill label="Next" :value="nextCursor || 'N/A'" variant="secondary" reversed
                        data-test="next-pill" />
                    <!-- Status Pill -->
                    <Pill :label="'Status'" :value="masonry?.isLoading ? 'Loading...' : 'Ready'"
                        :variant="masonry?.isLoading ? 'primary' : 'success'" reversed data-test="status-pill">
                        <template #label>
                            <span class="flex items-center gap-1.5">
                                Status
                            </span>
                        </template>
                        <template #value>
                            <Loader2 v-if="masonry?.isLoading" :size="14" class="animate-spin" />
                            <span v-else>Ready</span>
                        </template>
                    </Pill>
                </div>
            </div>
        </div>
    </div>
</template>
