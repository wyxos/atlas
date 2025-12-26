import { ref, type Ref, type ComputedRef } from 'vue';
import type { MasonryItem, TabData } from './useTabs';
import { services as browseServices } from '@/actions/App/Http/Controllers/BrowseController';

export type ServiceOption = {
    key: string;
    label: string;
    defaults?: Record<string, unknown>;
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
        getCurrentService,
    };
}
