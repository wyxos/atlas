import { reactive, ref, type Ref } from 'vue';

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
    initialData?: Partial<BrowseFormData>;
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

    const data = reactive<BrowseFormData>({
        ...defaultData,
        ...options?.initialData,
    });

    const errors = reactive<Partial<Record<keyof BrowseFormData, string>>>({});
    const processing = ref(false);
    const wasSuccessful = ref(false);

    /**
     * Reset the form to initial values
     */
    function reset(): void {
        Object.assign(data, defaultData, options?.initialData);
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
        clearErrors,
        setError,
        clearError,
        hasErrors,
        getData,
    };
}

