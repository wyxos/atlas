import { ref, type Ref, type ComputedRef } from 'vue';
import type { MasonryItem, BrowseTabData } from './useBrowseTabs';

export type GetPageResult = {
    items: MasonryItem[];
    nextPage: string | number | null; // Can be cursor string or number
};

export type ServiceOption = {
    key: string;
    label: string;
    defaults?: Record<string, any>;
};

export type UseBrowseServiceOptions = {
    hasServiceSelected: ComputedRef<boolean>;
    isTabRestored: Ref<boolean>;
    items: Ref<MasonryItem[]>;
    nextCursor: Ref<string | number | null>;
    currentPage: Ref<string | number | null>;
    pendingRestoreNextCursor: Ref<string | number | null>;
    currentTabService: ComputedRef<string | null>;
    activeTabId: Ref<number | null>;
    getActiveTab: () => BrowseTabData | undefined;
    updateActiveTab: (itemsData: MasonryItem[], fileIds: number[], queryParams: Record<string, string | number | null>) => void;
};

export function useBrowseService(options?: UseBrowseServiceOptions) {
    const availableServices = ref<ServiceOption[]>([]);
    const isApplyingService = ref(false);

    /**
     * Fetch available services from the browse API
     */
    async function fetchServices(): Promise<void> {
        try {
            // Fetch services from browse endpoint (will return services metadata)
            const response = await window.axios.get('/api/browse?page=1&limit=1');
            availableServices.value = response.data.services || [];

            // Fallback to default services if none returned
            if (availableServices.value.length === 0) {
                availableServices.value = [
                    { key: 'civit-ai-images', label: 'CivitAI Images' },
                    { key: 'wallhaven', label: 'Wallhaven' },
                ];
            }
        } catch (error) {
            console.error('Failed to fetch services:', error);
            // Fallback to default services
            availableServices.value = [
                { key: 'civit-ai-images', label: 'CivitAI Images' },
                { key: 'wallhaven', label: 'Wallhaven' },
            ];
        }
    }

    /**
     * Get next page of items from the browse API
     */
    async function getNextPage(page: number | string): Promise<GetPageResult> {
        if (!options) {
            throw new Error('useBrowseService options are required for getNextPage');
        }

        // IMPORTANT: Don't load if no service is selected
        if (!options.hasServiceSelected.value) {
            return {
                items: [],
                nextPage: null,
            };
        }

        // If we're restoring a tab and already have items, prevent any loading
        // Masonry should only load when user scrolls to bottom, not during restoration
        if (options.isTabRestored.value) {
            // Return empty result to prevent loading during tab restoration
            return {
                items: [],
                nextPage: options.nextCursor.value,
            };
        }

        // Determine actual cursor/page to request. When restoring, Masonry may request page 1.
        let pageToRequest: string | number = page;
        if (options.pendingRestoreNextCursor.value !== null) {
            pageToRequest = options.pendingRestoreNextCursor.value;
            options.pendingRestoreNextCursor.value = null;
        }

        // Always pass as 'page' parameter - service will handle conversion
        const url = new URL('/api/browse', window.location.origin);
        url.searchParams.set('page', String(pageToRequest));

        // Include service parameter if available
        const currentService = options.currentTabService.value;
        if (currentService) {
            url.searchParams.set('source', currentService);
        }

        const response = await window.axios.get(url.toString());
        const data = response.data;

        // Update currentPage to the page we just loaded
        // Only skip if we're restoring a tab and already have items (to prevent reset during restoration)
        if (!options.isTabRestored.value || options.items.value.length === 0) {
            // Update current page to the page/cursor we just used
            options.currentPage.value = pageToRequest;
        }

        // Update next cursor from API response (for local state, used for loading more)
        options.nextCursor.value = data.nextPage; // This is the cursor/page string from CivitAI

        // Update active tab with new items - this is the single source of truth for tab updates
        // Only update if we're not restoring a tab (to prevent overwriting restored state)
        if (options.activeTabId.value && !options.isTabRestored.value) {
            const activeTab = options.getActiveTab();
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
                options.updateActiveTab(updatedItemsData, updatedFileIds, updatedQueryParams);
            }
        }

        return {
            items: data.items,
            nextPage: data.nextPage, // Pass cursor to Masonry for next request
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
        masonry: Ref<{ isLoading: boolean; cancelLoad: () => void; destroy: () => void } | null>,
        getActiveTab: () => BrowseTabData | undefined,
        updateActiveTab: (itemsData: MasonryItem[], fileIds: number[], queryParams: Record<string, string | number | null>) => void,
        nextTick: () => Promise<void>
    ): Promise<void> {
        if (!activeTabId.value || !selectedService.value || isApplyingService.value) {
            return;
        }

        isApplyingService.value = true;
        try {
            const tab = getActiveTab();
            if (!tab) {
                return;
            }

            // Update tab's queryParams with service
            const updatedQueryParams = {
                ...tab.queryParams,
                service: selectedService.value,
                page: 1, // Reset to page 1 when changing service
                next: null,
            };

            // Clear existing items and reset pagination
            items.value = [];
            currentPage.value = 1;
            nextCursor.value = null;
            loadAtPage.value = 1;

            // Update tab
            updateActiveTab([], [], updatedQueryParams);

            // Reset masonry and trigger load
            if (masonry.value) {
                if (masonry.value.isLoading) {
                    masonry.value.cancelLoad();
                }
                masonry.value.destroy();
            }

            await nextTick();

            // Trigger initial load
            if (masonry.value && loadAtPage.value !== null) {
                // Masonry will auto-load when loadAtPage is set
            }
        } catch (error) {
            console.error('Failed to apply service:', error);
        } finally {
            isApplyingService.value = false;
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
        isApplyingService,
        fetchServices,
        getNextPage,
        applyService,
        getCurrentService,
    };
}

