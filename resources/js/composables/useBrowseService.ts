import { ref, type Ref, type ComputedRef } from 'vue';
import type { FeedItem, TabData } from './useTabs';
import { services as browseServices, sources as browseSources } from '@/actions/App/Http/Controllers/BrowseController';


export type ServiceOption = {
    key: string;
    label: string;
    source?: string;
    defaults?: Record<string, unknown>;
    schema?: ServiceFilterSchema;
};

export type ServiceFilterFieldType =
    | 'select'
    | 'radio'
    | 'checkbox'
    | 'checkbox-group'
    | 'boolean'
    | 'text'
    | 'number'
    | 'hidden';

export type ServiceFilterOption = {
    label: string;
    value: string | number | boolean | null;
};

export type ServiceFilterField = {
    uiKey: string;
    serviceKey: string;
    type: ServiceFilterFieldType;
    label: string;
    description?: string;
    required?: boolean;
    options?: ServiceFilterOption[];
    default?: unknown;
    min?: number;
    max?: number;
    step?: number;
    placeholder?: string;
};

export type ServiceFilterSchema = {
    fields: ServiceFilterField[];
};

export type UseBrowseServiceOptions = {
    hasServiceSelected: ComputedRef<boolean>;
    isInitializing: Ref<boolean>;
    items: Ref<FeedItem[]>;
    currentTabService: ComputedRef<string | null>;
    activeTabId: Ref<number | null>;
    getActiveTab: () => TabData | undefined;
    updateActiveTab: (items: FeedItem[]) => void;
};

export function useBrowseService(_options?: UseBrowseServiceOptions) {
    const availableServices = ref<ServiceOption[]>([]);
    const availableSources = ref<string[]>([]);
    const localService = ref<ServiceOption | null>(null);

    /**
     * Fetch available services from the services endpoint
     */
    async function fetchServices(): Promise<void> {
        try {
            const { data } = await window.axios.get(browseServices.url());
            availableServices.value = data.services || [];
            localService.value = data.local || null;
        } catch (error) {
            console.error('Failed to fetch services:', error);
            availableServices.value = [];
            localService.value = null;
        }
    }

    /**
     * Fetch available sources from the sources endpoint
     */
    async function fetchSources(): Promise<void> {
        try {
            const { data } = await window.axios.get(browseSources.url());
            availableSources.value = data.sources || ['all'];
        } catch (error) {
            console.error('Failed to fetch sources:', error);
            availableSources.value = ['all'];
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
        availableSources,
        localService,
        fetchServices,
        fetchSources,
        getCurrentService,
    };
}

