import { reactive, ref } from 'vue';
import type { TabData } from './useTabs';

export interface BrowseFormData {
    service: string;
    nsfw: boolean;
    type: string;
    limit: string;
    sort: string;
    page: number;
    next: string | number | null;
}

export interface UseBrowseFormOptions {
    tab?: TabData;
}

export function useBrowseForm(options?: UseBrowseFormOptions) {
    const defaultData: BrowseFormData = {
        service: '',
        nsfw: false,
        type: 'all',
        limit: '20',
        sort: 'Newest',
        page: 1,
        next: null,
    };

    /**
     * Get form data from tab queryParams
     */
    function getFormDataFromTab(tab?: TabData): Partial<BrowseFormData> | undefined {
        const queryParams = tab?.queryParams;
        if (!queryParams) {
            return undefined;
        }
        return {
            service: (queryParams.service as string) || '',
            nsfw: Boolean(queryParams.nsfw && (queryParams.nsfw === 1 || queryParams.nsfw === '1' || queryParams.nsfw === 'true')),
            type: (queryParams.type as string) || 'all',
            limit: String(queryParams.limit || '20'),
            sort: (queryParams.sort as string) || 'Newest',
            page: Number(queryParams.page || 1),
            next: queryParams.next ?? null,
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
    };
}

export type UseBrowseFormReturn = ReturnType<typeof useBrowseForm>;

