import axios from 'axios';
import { ref, type Ref } from 'vue';
import * as FileController from '@/actions/App/Http/Controllers/FileController';

export type MimeTypeGroup = 'audio' | 'video' | 'image' | 'other';

export type MimeTypesResponse = {
    grouped: Record<MimeTypeGroup, string[]>;
    all: string[];
};

let cachedMimeTypes: MimeTypesResponse | null = null;
let loadingPromise: Promise<MimeTypesResponse> | null = null;

/**
 * Composable to fetch and use mime types for dropdowns.
 * Automatically updates when new files with new mime types are added to the database.
 * Uses caching to avoid repeated API calls.
 */
export function useMimeTypes(): {
    mimeTypes: Ref<MimeTypesResponse | null>;
    loading: Ref<boolean>;
    error: Ref<Error | null>;
    fetch: () => Promise<void>;
    getGrouped: () => Record<MimeTypeGroup, string[]>;
    getAll: () => string[];
} {
    const mimeTypes = ref<MimeTypesResponse | null>(cachedMimeTypes);
    const loading = ref<boolean>(false);
    const error = ref<Error | null>(null);

    async function fetch(): Promise<void> {
        // If we already have cached data, use it immediately
        if (cachedMimeTypes) {
            mimeTypes.value = cachedMimeTypes;
            return;
        }

        // If there's already a request in progress, wait for it
        if (loadingPromise) {
            try {
                const result = await loadingPromise;
                mimeTypes.value = result;
                cachedMimeTypes = result;
                return;
            } catch (e) {
                error.value = e instanceof Error ? e : new Error('Failed to fetch mime types');
                throw e;
            }
        }

        loading.value = true;
        error.value = null;

        try {
            loadingPromise = axios.get<MimeTypesResponse>(FileController.mimeTypes().url).then((response) => {
                const data = response.data;
                cachedMimeTypes = data;
                mimeTypes.value = data;
                loading.value = false;
                loadingPromise = null;
                return data;
            });

            await loadingPromise;
        } catch (e) {
            loading.value = false;
            loadingPromise = null;
            error.value = e instanceof Error ? e : new Error('Failed to fetch mime types');
            throw e;
        }
    }

    function getGrouped(): Record<MimeTypeGroup, string[]> {
        return mimeTypes.value?.grouped ?? {
            audio: [],
            video: [],
            image: [],
            other: [],
        };
    }

    function getAll(): string[] {
        return mimeTypes.value?.all ?? [];
    }

    return {
        mimeTypes,
        loading,
        error,
        fetch,
        getGrouped,
        getAll,
    };
}

