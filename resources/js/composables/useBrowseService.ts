import { ref, type Ref, type ComputedRef } from 'vue';
import type { MasonryItem, TabData } from './useTabs';
import { index as browseIndex, services as browseServices } from '@/actions/App/Http/Controllers/BrowseController';
import type { BrowseFormData } from './useBrowseForm';

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
     * @param page - The page number or cursor to fetch (passed from Masonry component)
     * @param formData - The browse form data containing filters and service selection
     * @param tabId - Tab ID to include in the request
     */
    async function getNextPage(
        page: number | string,
        formData: BrowseFormData,
        tabId: number
    ): Promise<GetPageResult> {
        // Build query parameters - backend handles all parameters via request()->all()
        const queryParams: Record<string, any> = {
            source: formData.service || 'civit-ai-images', // Backend expects 'source', not 'service'
            tab_id: tabId,
            nsfw: formData.nsfw ? 1 : 0,
            type: formData.type,
            limit: formData.limit,
            sort: formData.sort,
        };

        // Use the page parameter from Masonry as the primary pagination value
        // Masonry passes either a page number or cursor from the previous response
        if (typeof page === 'number') {
            queryParams.page = page;
        } else {
            // If page is a string, it's likely a cursor - use 'next' parameter
            queryParams.next = page;
        }

        try {
            const response = await window.axios.get(browseIndex.url({ query: queryParams }));

            return {
                items: response.data.items || [],
                nextPage: response.data.nextPage ?? null,
                immediateActions: response.data.moderation?.toDislike || [],
            };
        } catch (error) {
            console.error('Failed to fetch next page:', error);
            throw error;
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
        getCurrentService,
    };
}

