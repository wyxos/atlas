import { ref, type Ref, type ComputedRef, nextTick } from 'vue';
import type { MasonryItem, TabData } from './useTabs';
import { index as browseIndex, services as browseServices } from '@/actions/App/Http/Controllers/BrowseController';

export type GetPageResult = {
    items: MasonryItem[];
    nextPage: string | number | null; // Can be cursor string or number
    immediateActions?: Array<{ id: number; action_type: string; thumbnail?: string }>;
};

export type ServiceOption = {
    key: string;
    label: string;
    defaults?: Record<string, any>;
};

export type UseBrowseServiceOptions = {
    hasServiceSelected: ComputedRef<boolean>;
    isInitializing: Ref<boolean>;
    items: Ref<MasonryItem[]>;
    nextCursor: Ref<string | number | null>;
    currentPage: Ref<string | number | null>;
    currentTabService: ComputedRef<string | null>;
    activeTabId: Ref<number | null>;
    getActiveTab: () => TabData | undefined;
    updateActiveTab: (itemsData: MasonryItem[]) => void;
};

export function useBrowseService(options?: UseBrowseServiceOptions) {
    const availableServices = ref<ServiceOption[]>([]);

    /**
     * Fetch available services from the services endpoint
     */
    async function fetchServices(): Promise<void> {
        try {
            const response = await window.axios.get(browseServices.url());
            availableServices.value = response.data.services || [];
        } catch (error) {
            console.error('Failed to fetch services:', error);
            availableServices.value = [];
        }
    }

    /**
     * Get next page of items from the browse API
     */
    async function getNextPage(page: number | string): Promise<GetPageResult> {
        if (!options) {
            throw new Error('useBrowseService options are required for getNextPage');
        }

        // IMPORTANT: Don't load if no service is selected (for online mode)
        // Or if no source is selected (for offline mode)
        const activeTab = options.getActiveTab();
        const isOfflineMode = activeTab?.sourceType === 'offline';

        if (!isOfflineMode && !options.hasServiceSelected.value) {
            return {
                items: [],
                nextPage: null,
            };
        }

        // For offline mode, check if source is selected
        // In offline mode, source should be set in queryParams (e.g., 'all', 'local', etc.)
        // If not set yet and we're not initializing, wait for reactive updates to complete
        // Note: We don't return early here - we'll use 'all' as default in the queryParams building below
        let finalActiveTab = activeTab;
        if (isOfflineMode && !activeTab?.queryParams?.source) {
            if (!options.isInitializing.value) {
                // Wait for Vue's reactive updates (queryParams sync) to complete
                // This handles the case where masonry loads before the watch has synced queryParams
                // Wait multiple ticks to ensure the watch has completed
                await nextTick();
                await nextTick();
                // Re-check after waiting
                const retryActiveTab = options.getActiveTab();
                if (retryActiveTab?.queryParams?.source) {
                    // Source is now available, use the updated activeTab
                    finalActiveTab = retryActiveTab;
                }
                // If still no source, we'll use 'all' as default in the queryParams building below
            } else {
                // Still initializing, return empty (unless we're allowing initial load)
                // This is handled by the isInitializing check below
            }
        }

        // If we're initializing a tab, prevent any loading
        // EXCEPT: In offline mode with skip-initial-load=false, we need to allow the initial load
        // to happen so masonry can automatically load when it mounts (items.length === 0 means no items restored)
        if (options.isInitializing.value) {
            // In offline mode, allow initial load if items are empty (masonry needs to load from API)
            // This allows masonry's automatic initial load when skip-initial-load=false
            const shouldAllowInitialLoad = isOfflineMode && options.items.value.length === 0;

            if (!shouldAllowInitialLoad) {
                // In online mode or when items exist, prevent loading during initialization
                return {
                    items: [],
                    nextPage: options.nextCursor.value,
                };
            }
            // If shouldAllowInitialLoad is true, continue to the API call below
        }

        // Use the page parameter directly - Masonry will handle pagination state correctly
        // via initialPage/initialNextPage props, so no need for pendingRestoreNextCursor
        const pageToRequest: string | number = page;

        // Always pass as 'page' parameter - service will handle conversion
        const queryParams: Record<string, string | number> = {
            page: String(pageToRequest),
        };

        // Include filter parameters from tab's queryParams
        // Use finalActiveTab which may have been updated after waiting for reactive updates
        if (finalActiveTab?.queryParams) {
            // In offline mode, source is set directly in queryParams
            // In online mode, service is converted to source parameter
            if (finalActiveTab.queryParams.source) {
                queryParams.source = finalActiveTab.queryParams.source;
            } else {
                // Include service parameter if available (online mode)
                const currentService = options.currentTabService.value;
                if (currentService) {
                    queryParams.source = currentService;
                } else if (isOfflineMode) {
                    // In offline mode, default to 'all' if source is not set
                    queryParams.source = 'all';
                }
            }

            if (finalActiveTab.queryParams.nsfw !== undefined && finalActiveTab.queryParams.nsfw !== null) {
                queryParams.nsfw = finalActiveTab.queryParams.nsfw;
            }
            if (finalActiveTab.queryParams.type && finalActiveTab.queryParams.type !== 'all') {
                queryParams.type = finalActiveTab.queryParams.type;
            }
            if (finalActiveTab.queryParams.sort && finalActiveTab.queryParams.sort !== 'Newest') {
                queryParams.sort = finalActiveTab.queryParams.sort;
            }
        }

        // Always include limit (default to 20 if not set in queryParams)
        queryParams.limit = finalActiveTab?.queryParams?.limit
            ? Number(finalActiveTab.queryParams.limit)
            : 20;

        // Include tab_id if available
        if (options.activeTabId.value) {
            queryParams.tab_id = options.activeTabId.value;
        }

        // Add minimal=true to request minimal items for virtualization
        const response = await window.axios.get(browseIndex.url({ query: { ...queryParams, minimal: true } }));
        const data = response.data;

        // Collect files that were moderated out (blacklist) from moderation response
        const immediateActions = Array.isArray(data.moderation) ? data.moderation : [];

        // Return immediate actions so they can be collected by the caller
        const result: GetPageResult & { immediateActions?: Array<{ id: number; action_type: string; thumbnail?: string }> } = {
            items: [],
            nextPage: null,
            immediateActions,
        };

        // Update currentPage to the page we just loaded
        // Only skip if we're initializing a tab and already have items (to prevent reset during initialization)
        if (!options.isInitializing.value || options.items.value.length === 0) {
            // Update current page to the page/cursor we just used
            options.currentPage.value = pageToRequest;
        }

        // Update next cursor from API response (for local state, used for loading more)
        options.nextCursor.value = data.nextPage; // This is the cursor/page string from CivitAI

        // Update active tab with new items - backend is responsible for updating query_params
        // Only update if we're not initializing a tab (to prevent overwriting restored state)
        if (options.activeTabId.value && !options.isInitializing.value) {
            const activeTab = options.getActiveTab();
            if (activeTab) {
                // Append new items to existing items (masonry will update items.value separately)
                const updatedItemsData = [...activeTab.itemsData, ...data.items];
                // Backend updates query_params automatically, so we only update itemsData
                options.updateActiveTab(updatedItemsData);
            }
        }

        // Merge new items from browse API with existing database items to preserve previewed_count and seen_count
        // Backend formatter already includes all properties, so no normalization needed
        const mergedItems = data.items.map((newItem: MasonryItem) => {
            // Check if this item already exists in the database (from tab.itemsData)
            const existingItem = options?.getActiveTab()?.itemsData?.find(
                (existing: MasonryItem) => existing.id === newItem.id
            );

            // If item exists in database, preserve its previewed_count and seen_count
            if (existingItem) {
                return {
                    ...newItem,
                    previewed_count: existingItem.previewed_count ?? newItem.previewed_count,
                    seen_count: existingItem.seen_count ?? newItem.seen_count,
                    will_auto_dislike: existingItem.will_auto_dislike ?? newItem.will_auto_dislike,
                };
            }

            return newItem;
        });

        return {
            items: mergedItems,
            nextPage: data.nextPage, // Pass cursor to Masonry for next request
            immediateActions: result.immediateActions ?? [],
        };
    }

    /**
     * Apply selected service to current tab
     */
    async function applyService(
        selectedService: Ref<string>,
        activeTabId: Ref<number | null>,
        items: Ref<MasonryItem[]>,
        currentPage: Ref<string | number | null>,
        nextCursor: Ref<string | number | null>,
        loadAtPage: Ref<string | number | null>,
        masonry: Ref<{
            isLoading: boolean;
            cancelLoad: () => void;
            destroy: () => void;
            reset?: () => void;
            loadPage?: (page: number | string) => Promise<void>;
        } | null>,
        getActiveTab: () => TabData | undefined,
        updateActiveTab: (itemsData: MasonryItem[]) => void
    ): Promise<void> {
        if (!activeTabId.value || !selectedService.value || masonry.value?.isLoading) {
            return;
        }

        try {
            const tab = getActiveTab();
            if (!tab) {
                return;
            }

            // Clear existing items and reset pagination
            items.value = [];
            currentPage.value = 1;
            nextCursor.value = null;
            loadAtPage.value = 1;

            // Update tab - backend will update query_params when browse request is made
            // Use the same pattern as getNextPage: check activeTabId and get active tab before updating
            if (activeTabId.value) {
                const activeTab = getActiveTab();
                if (activeTab) {
                    // Temporarily update local queryParams so hasServiceSelected becomes true
                    // Backend will update query_params in database when browse request is made
                    activeTab.queryParams = {
                        ...activeTab.queryParams,
                        service: selectedService.value,
                        page: 1,
                        next: null,
                    };
                    // Backend updates query_params automatically, so we only update itemsData
                    updateActiveTab([]);
                }
            }

            // Reset masonry and trigger load (same approach as filter sheet)
            if (masonry.value?.loadPage) {
                await masonry.value.loadPage(1);
            }
        } catch (error) {
            console.error('Failed to apply service:', error);
        } finally {
            selectedService.value = ''; // Clear selection after applying
        }
    }

    /**
     * Get the current service from a tab's query params
     */
    function getCurrentService(tabQueryParams?: Record<string, string | number | null>): string | null {
        if (tabQueryParams?.service) {
            return tabQueryParams.service as string;
        }
        return null;
    }

    return {
        availableServices,
        fetchServices,
        getNextPage,
        applyService,
        getCurrentService,
    };
}

