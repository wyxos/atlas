import { ref } from 'vue';
import { index as tabsIndex, store as tabsStore, update as tabsUpdate, destroy as tabsDestroy, show as tabsShow, setActive as tabsSetActive } from '@/actions/App/Http/Controllers/TabController';

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
    [key: string]: unknown;
};

export type TabData = {
    id: number;
    label: string;
    params: Record<string, string | number | null>; // Contains 'page' and 'next' keys (service handles format)
    position: number;
    isActive: boolean;
    feed?: 'online' | 'local'; // Defaults to 'online' if not set
};

export type OnTabSwitchCallback = (tabId: number) => Promise<void> | void;

export function useTabs(onTabSwitch?: OnTabSwitchCallback) {
    const tabs = ref<TabData[]>([]);
    const activeTabId = ref<number | null>(null);
    const isLoadingTabs = ref(false);
    const saveTabDebounceTimer = ref<number | null>(null);

    async function loadTabs(): Promise<void> {
        isLoadingTabs.value = true;
        try {
            const { data } = await window.axios.get(tabsIndex.url());
            tabs.value = data.map((tab: {
                id: number;
                label: string;
                params?: Record<string, string | number | null>;
                items?: MasonryItem[]; // Not included in initial load, loaded lazily when restoring a tab
                position?: number;
                is_active?: boolean;
            }) => {
                const params = tab.params || {};
                return {
                    id: tab.id,
                    label: tab.label,
                    params: params,
                    position: tab.position || 0,
                    isActive: tab.is_active ?? false,
                    feed: (params.feed === 'local' ? 'local' : 'online') as 'online' | 'local',
                };
            });

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

    async function createTab(): Promise<TabData> {
        const maxPosition = tabs.value.length > 0
            ? Math.max(...tabs.value.map(t => t.position))
            : -1;

        const newTab: TabData = {
            id: 0, // Temporary ID, will be set from response
            label: `Browse ${tabs.value.length + 1}`,
            params: {
                // Don't set page or service - user must select service first
            },
            position: maxPosition + 1,
            isActive: false,
        };

        try {
            const { data } = await window.axios.post(tabsStore.url(), {
                label: newTab.label,
                params: newTab.params,
                position: newTab.position,
            });

            newTab.id = data.id;
            newTab.isActive = data.is_active ?? false;
            const params = data.params || {};
            newTab.params = params;
            newTab.feed = (params.feed === 'local' ? 'local' : 'online') as 'online' | 'local';
            tabs.value.push(newTab);
            activeTabId.value = newTab.id;

            // Set this tab as active
            await setActiveTab(newTab.id);

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
            await window.axios.delete(tabsDestroy.url(tabId));

            const index = tabs.value.findIndex(t => t.id === tabId);
            if (index !== -1) {
                tabs.value.splice(index, 1);
            }

            // Handle tab switching after close
            const wasActiveTab = activeTabId.value === tabId;
            if (wasActiveTab) {
                if (tabs.value.length > 0) {
                    // Switch to first remaining tab and set it as active
                    const nextTab = tabs.value[0];
                    activeTabId.value = nextTab.id;
                    await setActiveTab(nextTab.id);
                    if (onTabSwitch) {
                        await onTabSwitch(nextTab.id);
                    }
                } else {
                    // No tabs left, create a new one (which will be set as active automatically)
                    await createTab();
                }
            }
        } catch (error) {
            console.error('Failed to close tab:', error);
            throw error;
        }
    }

    function getActiveTab(): TabData | undefined {
        if (!activeTabId.value) {
            return undefined;
        }
        return tabs.value.find(t => t.id === activeTabId.value);
    }

    function updateActiveTab(
        items: MasonryItem[]
    ): void {
        const activeTab = getActiveTab();
        if (!activeTab) {
            return;
        }

        // Note: items are managed in component state, not in tab
        // Tab persistence is handled by backend based on params
        // If we just loaded the first page of items, persist immediately so a quick refresh
        // still restores the tab content (debounced persistence can be canceled by reload).
        if (items.length > 0) {
            void saveTab(activeTab);
            return;
        }
        // Note: params are updated by the backend (Browser.php) when browse requests are made.
        saveTabDebounced(activeTab);
    }

    function saveTabDebounced(tab: TabData): void {
        if (saveTabDebounceTimer.value) {
            clearTimeout(saveTabDebounceTimer.value);
        }

        saveTabDebounceTimer.value = window.setTimeout(() => {
            saveTab(tab);
        }, 500); // Debounce for 500ms
    }

    async function saveTab(tab: TabData): Promise<void> {
        try {
            // Save UI-managed fields (label, position).
            // params are managed by the backend (Browser.php) and should not be updated from frontend.
            // The backend updates params when browse requests are made with tab_id.
            // Files are managed via the tab_file relationship, not through file_ids.
            await window.axios.put(tabsUpdate.url(tab.id), {
                label: tab.label,
                position: tab.position,
                // Do not send params - backend manages them.
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
            const { data } = await window.axios.get(tabsShow.url(tabId));

            // Return items from API response (backend returns items under tab)
            return data.tab?.items || [];
        } catch (error) {
            console.error('Failed to load tab items:', error);
            throw error;
        }
    }

    /**
     * Set a tab as active.
     * This will update the backend and sync the frontend state.
     */
    async function setActiveTab(tabId: number): Promise<void> {
        try {
            await window.axios.patch(tabsSetActive.url(tabId));

            // Update local state: deactivate all tabs, then activate the specified one
            tabs.value.forEach(tab => {
                tab.isActive = tab.id === tabId;
            });
        } catch (error) {
            console.error('Failed to set active tab:', error);
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
        setActiveTab,
    };
}

