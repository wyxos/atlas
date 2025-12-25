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
    function buildQueryParams(page: number | string, formData: BrowseFormData, tabId: number): Record<string, any> {
        const queryParams: Record<string, any> = {
            source: formData.service || 'civit-ai-images', // Backend expects 'source', not 'service'
            tab_id: tabId,
            nsfw: formData.nsfw ? 1 : 0,
            type: formData.type,
            limit: formData.limit,
            sort: formData.sort,
        };

        if (typeof page === 'number') {
            queryParams.page = page;
        } else {
            queryParams.next = page;
        }

        return queryParams;
    }

    async function fetchNextPage(page: number | string, formData: BrowseFormData, tabId: number): Promise<GetPageResult> {
        const queryParams = buildQueryParams(page, formData, tabId);

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
     * Get next page of items.
     *
     * Supports two call styles:
     * - TabContent usage: getNextPage(page, formData, tabId)
     * - Browse page tests / higher-level usage: getNextPage(page) when `options` are provided
     */
    async function getNextPage(
        page: number | string,
        formData?: BrowseFormData,
        tabId?: number
    ): Promise<GetPageResult> {
        // Direct mode (used by TabContent)
        if (formData && typeof tabId === 'number') {
            return await fetchNextPage(page, formData, tabId);
        }

        // Options-driven mode (used by useBrowseService unit tests)
        if (!options) {
            return { items: [], nextPage: null, immediateActions: [] };
        }

        if (options.isInitializing.value) {
            return { items: [], nextPage: null, immediateActions: [] };
        }

        const activeTab = options.getActiveTab();
        const activeTabId = options.activeTabId.value;

        if (!activeTab || !activeTabId) {
            return { items: [], nextPage: null, immediateActions: [] };
        }

        const isOffline = activeTab.sourceType === 'local';
        const offlineSource = (activeTab.queryParams?.source as string | undefined) || '';

        // When online, require a selected service. When offline, allow if source is present in query params.
        if (!options.hasServiceSelected.value && !(isOffline && offlineSource)) {
            return { items: [], nextPage: null, immediateActions: [] };
        }

        const effectiveService = isOffline
            ? offlineSource
            : (options.currentTabService.value || (activeTab.queryParams?.service as string | undefined) || 'civit-ai-images');

        const limit = (activeTab.queryParams?.limit as string | number | undefined) ?? 20;

        const derivedFormData: BrowseFormData = {
            service: String(effectiveService || 'civit-ai-images'),
            nsfw: Boolean(activeTab.queryParams?.nsfw && (activeTab.queryParams.nsfw === 1 || activeTab.queryParams.nsfw === '1' || activeTab.queryParams.nsfw === 'true')),
            type: String(activeTab.queryParams?.type || 'all'),
            limit: String(limit),
            sort: String(activeTab.queryParams?.sort || 'Newest'),
            page: Number(activeTab.queryParams?.page || 1),
            next: (activeTab.queryParams?.next ?? null) as any,
            sourceType: (activeTab.sourceType === 'local' ? 'local' : 'online') as 'online' | 'local',
        };

        const result = await fetchNextPage(page, derivedFormData, activeTabId);
        options.updateActiveTab(result.items);
        return result;
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

