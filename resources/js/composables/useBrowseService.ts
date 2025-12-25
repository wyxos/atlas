import { ref, type Ref, type ComputedRef } from 'vue';
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
        // TODO: Implement getNextPage logic from scratch
        return {
            items: [],
            nextPage: null,
        };
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

