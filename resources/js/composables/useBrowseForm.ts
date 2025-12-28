import { computed, reactive, ref } from 'vue';
import type { TabData } from './useTabs';

export interface BrowseFormData {
    service: string;
    nsfw: boolean;
    type: string;
    limit: string;
    sort: string;
    page: string | number;
    next: string | number | null;
    feed: 'online' | 'local';
    tab_id: number | null;
}

// Singleton instance
let formInstance: ReturnType<typeof createFormInstance> | null = null;

function createFormInstance() {
    const defaultData: BrowseFormData = {
        service: '',
        nsfw: false,
        type: 'all',
        limit: '20',
        sort: 'Newest',
        page: 1,
        next: null,
        feed: 'online',
        tab_id: null,
    };

    const data = reactive<BrowseFormData>({ ...defaultData });

    const errors = reactive<Partial<Record<keyof BrowseFormData, string>>>({});
    const processing = ref(false);
    const wasSuccessful = ref(false);

    /**
     * Sync form data from tab
     */
    function syncFromTab(tab?: TabData): void {
        if (tab?.params) {
            Object.assign(data, tab.params, { tab_id: tab.id });
        } else {
            reset();
        }
    }

    /**
     * Reset the form to initial values
     */
    function reset(): void {
        Object.assign(data, defaultData);
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
        get: () => data.feed === 'local',
        set: (value: boolean) => {
            data.feed = value ? 'local' : 'online';
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

export function useBrowseForm() {
    // Return singleton instance
    if (!formInstance) {
        formInstance = createFormInstance();
    }

    return formInstance;
}

