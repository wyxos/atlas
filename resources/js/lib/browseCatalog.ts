import { ref } from 'vue';
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

type BrowseServicesResponse = {
    services?: ServiceOption[];
    local?: ServiceOption | null;
};

type BrowseSourcesResponse = {
    sources?: string[];
};

const NO_CACHE_REQUEST_CONFIG = {
    headers: {
        'Cache-Control': 'no-cache',
        Pragma: 'no-cache',
    },
};

export async function fetchBrowseServices(): Promise<{ services: ServiceOption[]; localService: ServiceOption | null }> {
    try {
        const { data } = await window.axios.get<BrowseServicesResponse>(browseServices.url(), NO_CACHE_REQUEST_CONFIG);

        return {
            services: data.services ?? [],
            localService: data.local ?? null,
        };
    } catch (error) {
        console.error('Failed to fetch services:', error);

        return {
            services: [],
            localService: null,
        };
    }
}

export async function fetchBrowseSources(): Promise<string[]> {
    try {
        const { data } = await window.axios.get<BrowseSourcesResponse>(browseSources.url());

        return data.sources ?? ['all'];
    } catch (error) {
        console.error('Failed to fetch sources:', error);

        return ['all'];
    }
}

export function createBrowseCatalog() {
    const availableServices = ref<ServiceOption[]>([]);
    const availableSources = ref<string[]>([]);
    const localService = ref<ServiceOption | null>(null);

    async function loadServices(): Promise<void> {
        const catalog = await fetchBrowseServices();

        availableServices.value = catalog.services;
        localService.value = catalog.localService;
    }

    async function loadSources(): Promise<void> {
        availableSources.value = await fetchBrowseSources();
    }

    return {
        state: {
            availableServices,
            availableSources,
            localService,
        },
        actions: {
            loadServices,
            loadSources,
        },
    };
}
