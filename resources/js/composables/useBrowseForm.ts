import { computed, reactive, ref } from 'vue';
import type { TabData } from './useTabs';

export interface BrowseFormData {
    service: string;
    nsfw: boolean;
    type: string;
    limit: string;
    sort: string;
    page: number;
    next: string | number | null;
    sourceType: 'online' | 'local';
}

export interface UseBrowseFormOptions {
    tab?: TabData;
}

// Singleton instances per tab (keyed by tab ID)
const formInstances = new Map<number, ReturnType<typeof createFormInstance>>();

function createFormInstance(options?: UseBrowseFormOptions) {
    const defaultData: BrowseFormData = {
        service: '',
        nsfw: false,
        type: 'all',
        limit: '20',
        sort: 'Newest',
        page: 1,
        next: null,
        sourceType: options?.tab?.sourceType ?? 'online',
    };

    /**
     * Get form data from tab queryParams and sourceType
     */
    function getFormDataFromTab(tab?: TabData): Partial<BrowseFormData> | undefined {
        const queryParams = tab?.queryParams;
        if (!queryParams && !tab) {
            return undefined;
        }
        return {
            service: (queryParams?.service as string) || '',
            nsfw: Boolean(queryParams?.nsfw && (queryParams.nsfw === 1 || queryParams.nsfw === '1' || queryParams.nsfw === 'true')),
            type: (queryParams?.type as string) || 'all',
            limit: String(queryParams?.limit || '20'),
            sort: (queryParams?.sort as string) || 'Newest',
            page: Number(queryParams?.page || 1),
            next: queryParams?.next ?? null,
            sourceType: tab?.sourceType ?? 'online',
        };
    }

    const data = reactive<BrowseFormData>({
        ...defaultData,
        ...getFormDataFromTab(options?.tab),
    });

    const errors = reactive<Partial<Record<keyof BrowseFormData, string>>>({});
    const processing = ref(false);
    const wasSuccessful = ref(false);

    /**
     * Sync form data from tab
     */
    function syncFromTab(tab?: TabData): void {
        const formData = getFormDataFromTab(tab);
        if (formData) {
            Object.assign(data, formData);
        } else {
            reset();
        }
    }

    /**
     * Reset the form to initial values
     */
    function reset(): void {
        Object.assign(data, defaultData, getFormDataFromTab(options?.tab));
        clearErrors();
        wasSuccessful.value = false;
    }

    /**
     * Clear all errors
     */
    function clearErrors(): void {
        Object.keys(errors).forEach((key) => {
            delete errors[key as keyof BrowseFormData];
        });
    }

    /**
     * Set an error for a specific field
     */
    function setError(field: keyof BrowseFormData, message: string): void {
        errors[field] = message;
    }

    /**
     * Clear error for a specific field
     */
    function clearError(field: keyof BrowseFormData): void {
        delete errors[field];
    }

    /**
     * Check if form has any errors
     */
    function hasErrors(): boolean {
        return Object.keys(errors).length > 0;
    }

    /**
     * Get all form data as a plain object
     */
    function getData(): BrowseFormData {
        return { ...data };
    }

    // Computed for switch (true = local, false = online)
    const isLocalMode = computed({
        get: () => data.sourceType === 'local',
        set: (value: boolean) => {
            data.sourceType = value ? 'local' : 'online';
        }
    });

    return {
        data,
        errors,
        processing,
        wasSuccessful,
        reset,
        syncFromTab,
        clearErrors,
        setError,
        clearError,
        hasErrors,
        getData,
        isLocalMode,
    };
}

export function useBrowseForm(options?: UseBrowseFormOptions) {
    // If no tab provided, create a new instance (for cases without tab context)
    if (!options?.tab?.id) {
        return createFormInstance(options);
    }

    const tabId = options.tab.id;

    // Return existing instance if it exists for this tab
    if (formInstances.has(tabId)) {
        const instance = formInstances.get(tabId)!;
        // Sync with latest tab data
        instance.syncFromTab(options.tab);
        return instance;
    }

    // Create new instance for this tab
    const instance = createFormInstance(options);
    formInstances.set(tabId, instance);
    return instance;
}

