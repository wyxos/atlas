import { ref } from 'vue';
import { index as browseTabsIndex, store as browseTabsStore, update as browseTabsUpdate, destroy as browseTabsDestroy, items as browseTabsItems } from '@/actions/App/Http/Controllers/BrowseTabController';

export type MasonryItem = {
    id: number; // Database file ID
    width: number;
    height: number;
    page: number;
    key: string; // Combined key from backend: "page-id"
    index: number;
    src: string; // Preview/thumbnail URL for masonry grid
    originalUrl?: string; // Original full-size URL
    thumbnail?: string; // Thumbnail URL (may be same as src)
    type?: 'image' | 'video';
    notFound?: boolean;
    previewed_count?: number;
    seen_count?: number;
    auto_disliked?: boolean;
    [key: string]: unknown;
};

export type BrowseTabData = {
    id: number;
    label: string;
    queryParams: Record<string, string | number | null>; // Contains 'page' and 'next' keys (service handles format)
    fileIds: number[]; // Database file IDs
    itemsData: MasonryItem[]; // Loaded from API, not stored in DB
    position: number;
};

export type OnTabSwitchCallback = (tabId: number) => Promise<void> | void;

export function useBrowseTabs(onTabSwitch?: OnTabSwitchCallback) {
    const tabs = ref<BrowseTabData[]>([]);
    const activeTabId = ref<number | null>(null);
    const isLoadingTabs = ref(false);
    const saveTabDebounceTimer = ref<number | null>(null);

    async function loadTabs(): Promise<void> {
        isLoadingTabs.value = true;
        try {
            const response = await window.axios.get(browseTabsIndex.url());
            const data = response.data;
            tabs.value = data.map((tab: {
                id: number;
                label: string;
                query_params?: Record<string, string | number | null>;
                file_ids?: number[];
                items_data?: MasonryItem[]; // Not included in initial load, but kept for backward compatibility
                position?: number;
            }) => ({
                id: tab.id,
                label: tab.label,
                queryParams: tab.query_params || {},
                fileIds: tab.file_ids || [],
                itemsData: [], // Always empty on initial load - items are loaded lazily when restoring a tab
                position: tab.position || 0,
            }));

            // Sort by position
            tabs.value.sort((a, b) => a.position - b.position);
        } catch (error) {
            console.error('Failed to load tabs:', error);
            // Re-throw to allow caller to handle
            throw error;
        } finally {
            isLoadingTabs.value = false;
        }
    }

    async function createTab(): Promise<BrowseTabData> {
        const maxPosition = tabs.value.length > 0
            ? Math.max(...tabs.value.map(t => t.position))
            : -1;

        const newTab: BrowseTabData = {
            id: 0, // Temporary ID, will be set from response
            label: `Browse ${tabs.value.length + 1}`,
            queryParams: {
                // Don't set page or service - user must select service first
            },
            fileIds: [],
            itemsData: [],
            position: maxPosition + 1,
        };

        try {
            const response = await window.axios.post(browseTabsStore.url(), {
                label: newTab.label,
                query_params: newTab.queryParams,
                file_ids: newTab.fileIds,
                position: newTab.position,
            });

            const data = response.data;
            newTab.id = data.id;
            tabs.value.push(newTab);
            activeTabId.value = newTab.id;

            // Call callback if provided to handle UI switching
            if (onTabSwitch) {
                await onTabSwitch(newTab.id);
            }

            return newTab;
        } catch (error) {
            console.error('Failed to create tab:', error);
            throw error;
        }
    }

    async function closeTab(tabId: number): Promise<void> {
        try {
            await window.axios.delete(browseTabsDestroy.url(tabId));

            const index = tabs.value.findIndex(t => t.id === tabId);
            if (index !== -1) {
                tabs.value.splice(index, 1);
            }

            // Handle tab switching after close
            const wasActiveTab = activeTabId.value === tabId;
            if (wasActiveTab) {
                if (tabs.value.length > 0) {
                    // Switch to first remaining tab
                    activeTabId.value = tabs.value[0].id;
                    if (onTabSwitch) {
                        await onTabSwitch(tabs.value[0].id);
                    }
                } else {
                    // No tabs left, create a new one
                    await createTab();
                }
            }
        } catch (error) {
            console.error('Failed to close tab:', error);
            throw error;
        }
    }

    function getActiveTab(): BrowseTabData | undefined {
        if (!activeTabId.value) {
            return undefined;
        }
        return tabs.value.find(t => t.id === activeTabId.value);
    }

    function updateActiveTab(
        itemsData: MasonryItem[],
        fileIds: number[],
        queryParams: Record<string, string | number | null>
    ): void {
        const activeTab = getActiveTab();
        if (!activeTab) {
            return;
        }

        activeTab.itemsData = itemsData;
        activeTab.fileIds = fileIds;
        activeTab.queryParams = queryParams;
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
            await window.axios.put(browseTabsUpdate.url(tab.id), {
                label: tab.label,
                query_params: tab.queryParams,
                file_ids: tab.fileIds,
                position: tab.position,
            });
        } catch (error) {
            console.error('Failed to save tab:', error);
            throw error;
        }
    }

    /**
     * Load items for a specific tab.
     * This is called lazily when restoring a tab to avoid loading items for all tabs.
     */
    async function loadTabItems(tabId: number): Promise<MasonryItem[]> {
        try {
            const response = await window.axios.get(browseTabsItems.url(tabId));
            const data = response.data;

            // Update the tab with loaded items
            const tab = tabs.value.find(t => t.id === tabId);
            if (tab) {
                tab.itemsData = data.items_data || [];
                tab.fileIds = data.file_ids || [];
            }

            return data.items_data || [];
        } catch (error) {
            console.error('Failed to load tab items:', error);
            throw error;
        }
    }

    return {
        tabs,
        activeTabId,
        isLoadingTabs,
        loadTabs,
        createTab,
        closeTab,
        getActiveTab,
        updateActiveTab,
        loadTabItems,
    };
}

