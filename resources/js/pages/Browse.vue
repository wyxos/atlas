<script setup lang="ts">
import { ref, onMounted, nextTick } from 'vue';
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
const currentPage = ref<string | number>(1); // Starts as 1, becomes cursor string
const nextCursor = ref<string | number | null>(null); // The next page/cursor from API (service handles format)
const loadAtPage = ref<string | number | null>(null); // Initial page to load - set to 1 only when needed, null prevents auto-load
const isTabRestored = ref(false); // Track if we restored from a tab to prevent duplicate loading
const isPanelMinimized = ref(false);

// Tab switching function - needs to stay here as it interacts with masonry
async function switchTab(tabId: number): Promise<void> {
    const tab = tabs.value.find(t => t.id === tabId);
    if (!tab) {
        return;
    }

    // Cancel any ongoing load in masonry before switching tabs
    if (masonry.value && masonry.value.isLoading) {
        masonry.value.cancelLoad();
    }

    activeTabId.value = tabId;
    isTabRestored.value = true;

    // Restore both page and next from queryParams (service handles format conversion)
    const pageFromQuery = tab.queryParams.page;
    const nextFromQuery = tab.queryParams.next;

    if (pageFromQuery !== undefined && pageFromQuery !== null) {
        currentPage.value = pageFromQuery;
    } else {
        currentPage.value = 1;
    }

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
        // No items, start from beginning
        loadAtPage.value = 1;
        currentPage.value = 1;
        items.value = [];
    }

    // Wait for next tick to ensure masonry component is ready
    await nextTick();

    // If we have pre-loaded items, use masonry.init() to properly initialize
    // This sets up pagination history and prevents auto-loading
    if (tab.itemsData && tab.itemsData.length > 0 && masonry.value) {
        const pageValue = pageFromQuery !== undefined && pageFromQuery !== null ? pageFromQuery : 1;
        const nextValue = nextFromQuery !== undefined && nextFromQuery !== null ? nextFromQuery : null;

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
    if (isTabRestored.value && items.value.length > 0) {
        // Return empty result to prevent loading during tab restoration
        return {
            items: [],
            nextPage: nextCursor.value,
        };
    }

    // Always pass as 'page' parameter - service will handle conversion
    const url = new URL('/api/browse', window.location.origin);
    url.searchParams.set('page', String(page));

    const response = await window.axios.get(url.toString());
    const data = response.data;

    // Update currentPage to the page we just loaded
    // Only skip if we're restoring a tab and already have items (to prevent reset during restoration)
    if (!isTabRestored.value || items.value.length === 0) {
        // Update current page to the page/cursor we just used
        currentPage.value = page;
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
                page: currentPage.value,
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
async function loadTabs(): Promise<void> {
    try {
        await loadTabsFromComposable();
        // If tabs exist and no tab is active, set first tab as active
        if (tabs.value.length > 0 && activeTabId.value === null) {
            activeTabId.value = tabs.value[0].id;
            await switchTab(tabs.value[0].id);
        }
        // If no tabs exist, don't create one - user must create manually
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
                        @close="closeTab(tab.id)" />
                </template>
                <template #footer="{ isMinimized }">
                    <Button variant="dashed" size="sm" @click="createTab"
                        :class="['w-full rounded h-8', isMinimized ? 'justify-center' : 'justify-start']"
                        aria-label="New tab">
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
                        :mobile-breakpoint="768" :skip-initial-load="items.length > 0" />
                    <div v-else class="flex items-center justify-center h-full">
                        <p class="text-twilight-indigo-300 text-lg">Create a tab to start browsing</p>
                    </div>
                </div>

                <!-- Status/Pagination Info at Bottom -->
                <div v-if="activeTabId !== null" class="my-2 flex flex-wrap items-center justify-center gap-3">
                    <!-- Count Pill -->
                    <Pill label="Items" :value="items.length" variant="primary" reversed />
                    <!-- Current Page Pill -->
                    <Pill label="Page" :value="currentPage" variant="neutral" reversed />
                    <!-- Next Page Pill -->
                    <Pill label="Next" :value="nextCursor || 'N/A'" variant="secondary" reversed />
                    <!-- Status Pill -->
                    <Pill :label="'Status'" :value="masonry?.isLoading ? 'Loading...' : 'Ready'"
                        :variant="masonry?.isLoading ? 'primary' : 'success'" reversed>
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
