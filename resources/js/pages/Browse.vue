<script setup lang="ts">
import { ref, watch, onMounted } from 'vue';
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
    src: string;
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
    queryParams: Record<string, string>;
    fileIds: string[];
    itemsData: MasonryItem[];
    nextCursor: string | null;
    currentPage: string | number;
    position: number;
};

const route = useRoute();
const router = useRouter();

const items = ref<MasonryItem[]>([]);
const masonry = ref<InstanceType<typeof Masonry> | null>(null);
const currentPage = ref<string | number>(1); // Starts as 1, becomes cursor string
const nextCursor = ref<string | null>(null); // The cursor from API
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
    // Always pass as 'page' parameter - service will handle conversion
    const url = new URL('/api/browse', window.location.origin);
    url.searchParams.set('page', String(page));

    const response = await fetch(url.toString());
    const data = await response.json();

    // Update current page to the cursor we just used (or keep as 1 if it was the first page)
    if (page === 1) {
        currentPage.value = 1;
    } else {
        currentPage.value = page; // This is the cursor we just used
    }

    // Update next cursor from API response
    nextCursor.value = data.nextPage; // This is the cursor string from CivitAI

    // Update active tab with new items
    if (activeTabId.value) {
        const activeTab = tabs.value.find(t => t.id === activeTabId.value);
        if (activeTab) {
            // Append new items to existing items
            activeTab.itemsData = [...activeTab.itemsData, ...data.items];
            activeTab.fileIds = activeTab.itemsData.map(item => item.id);
            activeTab.nextCursor = data.nextPage;
            activeTab.currentPage = currentPage.value;
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
            query_params?: Record<string, string>;
            file_ids?: string[];
            items_data?: MasonryItem[];
            next_cursor?: string | null;
            current_page?: string | number;
            position?: number;
        }) => ({
            id: tab.id,
            label: tab.label,
            queryParams: tab.query_params || {},
            fileIds: tab.file_ids || [],
            itemsData: tab.items_data || [],
            nextCursor: tab.next_cursor,
            currentPage: tab.current_page || 1,
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
        queryParams: { ...route.query } as Record<string, string>,
        fileIds: [],
        itemsData: [],
        nextCursor: null,
        currentPage: 1,
        position: maxPosition + 1,
    };

    try {
        const response = await window.axios.post('/api/browse-tabs', {
            label: newTab.label,
            query_params: newTab.queryParams,
            file_ids: newTab.fileIds,
            next_cursor: newTab.nextCursor,
            current_page: String(newTab.currentPage),
            items_data: newTab.itemsData,
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

    // Restore items from tab
    if (tab.itemsData && tab.itemsData.length > 0) {
        items.value = [...tab.itemsData];
    } else {
        items.value = [];
    }

    // Restore cursor and page
    nextCursor.value = tab.nextCursor;
    currentPage.value = tab.currentPage;

    // Prevent masonry from auto-loading
    loadAtPage.value = null;

    // Reset the flag after a short delay to allow masonry to initialize
    setTimeout(() => {
        isTabRestored.value = false;
        // If tab has items, set loadAtPage to next cursor for infinite scroll
        if (tab.itemsData && tab.itemsData.length > 0 && tab.nextCursor) {
            loadAtPage.value = tab.nextCursor;
        } else if (tab.itemsData && tab.itemsData.length === 0) {
            // No items, start from beginning
            loadAtPage.value = 1;
        }
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
    const queryParams = { ...route.query } as Record<string, string>;
    delete queryParams.tab;
    activeTab.queryParams = queryParams;
    activeTab.fileIds = items.value.map(item => item.id);
    activeTab.itemsData = [...items.value];
    activeTab.nextCursor = nextCursor.value;
    activeTab.currentPage = currentPage.value;

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
            query_params: tab.queryParams,
            file_ids: tab.fileIds,
            next_cursor: tab.nextCursor,
            current_page: String(tab.currentPage),
            items_data: tab.itemsData,
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
