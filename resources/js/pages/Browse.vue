<script setup lang="ts">
import { ref, watch, onMounted, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Masonry } from '@wyxos/vibe';
import { Loader2, Plus } from 'lucide-vue-next';
import Pill from '../components/ui/Pill.vue';
import TabPanel from '../components/ui/TabPanel.vue';
import BrowseTab from '../components/BrowseTab.vue';
import { Button } from '@/components/ui/button';

type MasonryItem = {
    id: string;
    width: number;
    height: number;
    page: number;
    index: number;
    src: string; // Preview/thumbnail URL for masonry grid
    originalUrl?: string; // Original full-size URL
    thumbnail?: string; // Thumbnail URL (may be same as src)
    type?: 'image' | 'video';
    notFound?: boolean;
    [key: string]: unknown;
};

type GetPageResult = {
    items: MasonryItem[];
    nextPage: string | number | null; // Can be cursor string or number
};

type BrowseTabData = {
    id: number;
    label: string;
    queryParams: Record<string, string | number | null>; // Contains 'page' and 'next' keys (service handles format)
    fileIds: string[];
    itemsData: MasonryItem[]; // Loaded from API, not stored in DB
    position: number;
};

const route = useRoute();
const router = useRouter();

const items = ref<MasonryItem[]>([]);
const masonry = ref<InstanceType<typeof Masonry> | null>(null);
const currentPage = ref<string | number>(1); // Starts as 1, becomes cursor string
const nextCursor = ref<string | number | null>(null); // The next page/cursor from API (service handles format)
const previousLoadingState = ref(false);
const loadAtPage = ref<string | number | null>(1); // Initial page to load, can be from URL
const isTabRestored = ref(false); // Track if we restored from a tab to prevent duplicate loading

// Tab state management
const tabs = ref<BrowseTabData[]>([]);
const activeTabId = ref<number | null>(null);
const isLoadingTabs = ref(false);
const isPanelMinimized = ref(false);
const saveTabDebounceTimer = ref<number | null>(null);

const layout = {
    gutterX: 12,
    gutterY: 12,
    sizes: { base: 1, sm: 2, md: 3, lg: 4, '2xl': 10 },
};

async function getNextPage(page: number | string): Promise<GetPageResult> {
    // If we're restoring a tab and already have items, and masonry is trying to load page 1,
    // prevent this load as we already have the items
    if (isTabRestored.value && items.value.length > 0 && page === 1) {
        // Return empty result to prevent loading page 1 when we already have items
        return {
            items: [],
            nextPage: nextCursor.value,
        };
    }

    // Always pass as 'page' parameter - service will handle conversion
    const url = new URL('/api/browse', window.location.origin);
    url.searchParams.set('page', String(page));

    const response = await fetch(url.toString());
    const data = await response.json();

    // Don't update currentPage if we're restoring a tab and already have items
    // This prevents resetting currentPage to 1 when masonry initializes with existing items
    if (!isTabRestored.value || items.value.length === 0) {
        // Update current page to the cursor we just used (or keep as 1 if it was the first page)
        if (page === 1) {
            currentPage.value = 1;
        } else {
            currentPage.value = page; // This is the cursor we just used
        }
    }

    // Update next cursor from API response (for local state, used for loading more)
    nextCursor.value = data.nextPage; // This is the cursor/page string from CivitAI

    // Update active tab with new items
    if (activeTabId.value) {
        const activeTab = tabs.value.find(t => t.id === activeTabId.value);
        if (activeTab) {
            // Append new items to existing items
            activeTab.itemsData = [...activeTab.itemsData, ...data.items];
            // Convert item IDs to referrer URLs for storage (backend expects referrer URLs)
            activeTab.fileIds = activeTab.itemsData.map(item => `https://civitai.com/images/${item.id}`);
            // Store both page and next in queryParams (service handles format conversion)
            activeTab.queryParams = {
                ...activeTab.queryParams,
                page: currentPage.value,
                next: data.nextPage,
            };
            saveTabDebounced(activeTab);
        }
    }

    return {
        items: data.items,
        nextPage: data.nextPage, // Pass cursor to Masonry for next request
    };
}

// Watch for loading state changes to update URL when page loads successfully
watch(
    () => masonry.value?.isLoading,
    (isLoading) => {
        // When loading transitions from true to false, a page has successfully loaded
        if (previousLoadingState.value && !isLoading) {
            updateUrl();
            updateCurrentTab();
        }
        previousLoadingState.value = isLoading ?? false;
    },
    { immediate: true }
);

function updateUrl(): void {
    const query: Record<string, string> = {};

    // Add tab ID to query if we have an active tab
    if (activeTabId.value !== null) {
        query.tab = String(activeTabId.value);
    }

    // Update URL without triggering navigation
    router.replace({
        query,
    });
}

// Tab management functions
async function loadTabs(): Promise<void> {
    isLoadingTabs.value = true;
    try {
        const response = await window.axios.get('/api/browse-tabs');
        const data = response.data;
        tabs.value = data.map((tab: {
            id: number;
            label: string;
            query_params?: Record<string, string | number | null>;
            file_ids?: string[];
            items_data?: MasonryItem[];
            position?: number;
        }) => ({
            id: tab.id,
            label: tab.label,
            queryParams: tab.query_params || {},
            fileIds: tab.file_ids || [],
            itemsData: tab.items_data || [],
            position: tab.position || 0,
        }));

        // Sort by position
        tabs.value.sort((a, b) => a.position - b.position);

        // If tabs exist and no tab was restored from URL, set first tab as active
        if (tabs.value.length > 0 && activeTabId.value === null) {
            activeTabId.value = tabs.value[0].id;
            await switchTab(tabs.value[0].id);
        }
        // If no tabs exist, don't create one - user must create manually
    } catch (error) {
        console.error('Failed to load tabs:', error);
        // Don't create a tab on error - let user create manually
    } finally {
        isLoadingTabs.value = false;
    }
}

async function createTab(): Promise<void> {
    const maxPosition = tabs.value.length > 0
        ? Math.max(...tabs.value.map(t => t.position))
        : -1;

    const newTab: BrowseTabData = {
        id: 0, // Temporary ID, will be set from response
        label: `Browse ${tabs.value.length + 1}`,
        queryParams: { ...route.query } as Record<string, string | number | null>,
        fileIds: [],
        itemsData: [],
        position: maxPosition + 1,
    };

    // Ensure page is in queryParams (default to 1 if not present)
    if (newTab.queryParams.page === undefined || newTab.queryParams.page === null) {
        newTab.queryParams.page = 1;
    }

    try {
        const response = await window.axios.post('/api/browse-tabs', {
            label: newTab.label,
            query_params: newTab.queryParams,
            file_ids: newTab.fileIds,
            position: newTab.position,
        });

        const data = response.data;
        newTab.id = data.id;
        tabs.value.push(newTab);
        activeTabId.value = newTab.id;
        await switchTab(newTab.id);
    } catch (error) {
        console.error('Failed to create tab:', error);
    }
}

async function closeTab(tabId: number): Promise<void> {
    try {
        await window.axios.delete(`/api/browse-tabs/${tabId}`);

        const index = tabs.value.findIndex(t => t.id === tabId);
        if (index !== -1) {
            tabs.value.splice(index, 1);
        }

        // If we closed the active tab, switch to another one
        if (activeTabId.value === tabId) {
            if (tabs.value.length > 0) {
                activeTabId.value = tabs.value[0].id;
                await switchTab(tabs.value[0].id);
            } else {
                // No tabs left, create a new one
                await createTab();
            }
        }
    } catch (error) {
        console.error('Failed to close tab:', error);
    }
}

async function switchTab(tabId: number): Promise<void> {
    const tab = tabs.value.find(t => t.id === tabId);
    if (!tab) {
        return;
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

    // Set loadAtPage immediately to prevent masonry from starting at page 1
    // If tab has items, set loadAtPage to the current page so masonry can continue from there
    // If no items, start from page 1
    if (tab.itemsData && tab.itemsData.length > 0) {
        // Use the page from queryParams, or set to null to prevent auto-loading
        // Masonry will use the current page value when it needs to load more
        loadAtPage.value = pageFromQuery || null;
    } else {
        // No items, start from beginning
        loadAtPage.value = 1;
        currentPage.value = 1;
    }

    // Restore items from tab
    if (tab.itemsData && tab.itemsData.length > 0) {
        items.value = [...tab.itemsData];
    } else {
        items.value = [];
    }

    // Wait for next tick to ensure masonry sees the correct state
    await nextTick();

    // Reset the flag after masonry has initialized
    setTimeout(() => {
        isTabRestored.value = false;
    }, 100);

    // Update URL with tab ID
    updateUrl();
}

function updateCurrentTab(): void {
    if (!activeTabId.value) {
        return;
    }

    const activeTab = tabs.value.find(t => t.id === activeTabId.value);
    if (!activeTab) {
        return;
    }

    // Update tab with current state
    // Exclude 'tab' from queryParams since it's managed separately in URL
    const queryParams = { ...route.query } as Record<string, string | number | null>;
    delete queryParams.tab;
    // Store both page and next in queryParams (service handles format conversion)
    queryParams.page = currentPage.value;
    queryParams.next = nextCursor.value;
    activeTab.queryParams = queryParams;
    // Convert item IDs to referrer URLs for storage (backend expects referrer URLs)
    activeTab.fileIds = items.value.map(item => `https://civitai.com/images/${item.id}`);
    activeTab.itemsData = [...items.value];

    saveTabDebounced(activeTab);
}

function saveTabDebounced(tab: BrowseTabData): void {
    if (saveTabDebounceTimer.value) {
        clearTimeout(saveTabDebounceTimer.value);
    }

    saveTabDebounceTimer.value = window.setTimeout(() => {
        saveTab(tab);
    }, 500); // Debounce for 500ms
}

async function saveTab(tab: BrowseTabData): Promise<void> {
    try {
        await window.axios.put(`/api/browse-tabs/${tab.id}`, {
            label: tab.label,
            query_params: tab.queryParams, // Contains 'page' key
            file_ids: tab.fileIds, // Already in referrer URL format
            position: tab.position,
        });
    } catch (error) {
        console.error('Failed to save tab:', error);
    }
}

// Watch for items changes to update active tab
watch(
    () => items.value,
    () => {
        if (!isTabRestored.value && activeTabId.value) {
            updateCurrentTab();
        }
    },
    { deep: true }
);

// Watch for cursor changes to update active tab
watch(
    () => nextCursor.value,
    () => {
        if (!isTabRestored.value && activeTabId.value) {
            updateCurrentTab();
        }
    }
);

// Watch for page changes to update active tab
watch(
    () => currentPage.value,
    () => {
        if (!isTabRestored.value && activeTabId.value) {
            updateCurrentTab();
        }
    }
);

// Initialize from URL on mount
onMounted(async () => {
    // Load tabs first
    await loadTabs();

    // Check for tab parameter in URL
    const tabParam = route.query.tab;
    if (tabParam) {
        const tabId = typeof tabParam === 'string' ? parseInt(tabParam, 10) : Number(tabParam);
        if (!isNaN(tabId)) {
            const tab = tabs.value.find(t => t.id === tabId);
            if (tab) {
                // Switch to the tab specified in URL
                await switchTab(tabId);
                return;
            }
        }
    }

    // If no tab in URL or tab not found, use existing logic
    // (loadTabs already sets the first tab as active if tabs exist)
    if (activeTabId.value !== null && !isTabRestored.value) {
        // Update URL with the active tab ID
        updateUrl();
    }
});
</script>

<template>
    <div class="h-full flex flex-col">
        <div class="mb-4 flex items-center justify-center gap-3">
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
                </template>
            </Pill>
        </div>
        <div class="flex-1 min-h-0 relative flex">
            <TabPanel :model-value="true" v-model:is-minimized="isPanelMinimized">
                <template #tabs>
                    <BrowseTab v-for="tab in tabs" :key="tab.id" :id="tab.id" :label="tab.label"
                        :is-active="tab.id === activeTabId" @click="switchTab(tab.id)" @close="closeTab(tab.id)" />

                    <Button variant="dashed" size="sm" @click="createTab" class="w-full justify-start mt-2 rounded"
                        aria-label="New tab">
                        <Plus :size="16" />
                        <span>New Tab</span>
                    </Button>
                </template>
            </TabPanel>
            <div class="flex-1 min-h-0 transition-all duration-300">
                <Masonry v-if="activeTabId !== null" :key="activeTabId" ref="masonry" v-model:items="items"
                    :get-next-page="getNextPage" :load-at-page="loadAtPage" :layout="layout" layout-mode="auto"
                    :mobile-breakpoint="768" />
                <div v-else class="flex items-center justify-center h-full">
                    <p class="text-twilight-indigo-300 text-lg">Create a tab to start browsing</p>
                </div>
            </div>
        </div>
    </div>
</template>
