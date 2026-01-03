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
    source: string;
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
        source: 'all',
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
        reset();

        if (!tab?.params) {
            return;
        }

        const params = tab.params as Record<string, unknown>;

        const parseBool = (value: unknown, fallback: boolean): boolean => {
            if (typeof value === 'boolean') return value;
            if (typeof value === 'number') return value === 1;
            if (typeof value === 'string') {
                const v = value.trim().toLowerCase();
                if (v === 'true' || v === '1') return true;
                if (v === 'false' || v === '0' || v === '') return false;
            }
            return fallback;
        };

        const parseString = (value: unknown, fallback: string): string => {
            return typeof value === 'string' && value.length ? value : fallback;
        };

        const parseStringOrNumber = (
            value: unknown,
            fallback: string | number | null
        ): string | number | null => {
            if (typeof value === 'string') return value;
            if (typeof value === 'number') return value;
            return fallback;
        };

        data.tab_id = tab.id;
        data.service = parseString(params.service, defaultData.service);
        data.nsfw = parseBool(params.nsfw, defaultData.nsfw);
        data.type = parseString(params.type, defaultData.type);
        data.sort = parseString(params.sort, defaultData.sort);
        data.feed = params.feed === 'local' ? 'local' : 'online';
        data.source = parseString(params.source, defaultData.source);

        const limitValue = params.limit;
        if (typeof limitValue === 'number' && Number.isFinite(limitValue)) {
            data.limit = String(limitValue);
        } else {
            data.limit = parseString(limitValue, defaultData.limit);
        }

        data.page = parseStringOrNumber(params.page, defaultData.page) ?? defaultData.page;
        data.next = parseStringOrNumber(params.next, defaultData.next);
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

    // Computed for checking if in local mode (read-only, for use in composables)
    const isLocal = computed(() => data.feed === 'local');

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
        isLocal,
    };
}

export function useBrowseForm() {
    // Return singleton instance
    if (!formInstance) {
        formInstance = createFormInstance();
    }

    return formInstance;
}

