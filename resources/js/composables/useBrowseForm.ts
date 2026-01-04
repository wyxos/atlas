import { computed, reactive, ref } from 'vue';
import type { TabData } from './useTabs';

export interface BrowseFormData {
    service: string;
    limit: string;
    page: string | number;
    feed: 'online' | 'local';
    source: string;
    tab_id: number | null;
    // Service-specific UI filters (excluding global keys like service/feed/source/tab_id).
    // These are persisted/restored per service key.
    serviceFilters: Record<string, unknown>;
}

// Singleton instance
let formInstance: ReturnType<typeof createFormInstance> | null = null;

function createFormInstance() {
    const defaultData: BrowseFormData = {
        service: '',
        limit: '20',
        page: 1,
        feed: 'online',
        source: 'all',
        tab_id: null,
        serviceFilters: {},
    };

    const data = reactive<BrowseFormData>({ ...defaultData });

    // In-memory cache to preserve per-service filter values when switching services.
    // This avoids the "switch away and back loses values" glitch.
    const filtersByServiceKey = reactive<Record<string, Record<string, unknown>>>({});

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
        data.feed = params.feed === 'local' ? 'local' : 'online';
        data.source = parseString(params.source, defaultData.source);

        const limitValue = params.limit;
        if (typeof limitValue === 'number' && Number.isFinite(limitValue)) {
            data.limit = String(limitValue);
        } else {
            data.limit = parseString(limitValue, defaultData.limit);
        }

        data.page = parseStringOrNumber(params.page, defaultData.page) ?? defaultData.page;

        // Restore per-service filters. Prefer an explicit per-service map, if present.
        // Otherwise, fall back to storing unknown keys as serviceFilters for the current service.
        const serviceKey = data.service;
        const serviceFiltersByKey = params.serviceFiltersByKey;
        if (serviceKey && typeof serviceFiltersByKey === 'object' && serviceFiltersByKey) {
            const raw = (serviceFiltersByKey as Record<string, unknown>)[serviceKey];
            if (raw && typeof raw === 'object') {
                filtersByServiceKey[serviceKey] = { ...(raw as Record<string, unknown>) };
            }
        } else if (serviceKey) {
            const reserved = new Set(['service', 'feed', 'source', 'tab_id', 'page', 'limit']);
            const inferred: Record<string, unknown> = {};
            for (const [k, v] of Object.entries(params)) {
                if (reserved.has(k)) {
                    continue;
                }
                inferred[k] = v;
            }
            if (Object.keys(inferred).length > 0) {
                filtersByServiceKey[serviceKey] = { ...inferred };
            }
        }

        if (serviceKey) {
            // Seed current service filters from cache (if any)
            const cached = filtersByServiceKey[serviceKey];
            if (cached) {
                // Extract global-ish values if present.
                if ('page' in cached) {
                    data.page = (cached.page as string | number) ?? data.page;
                }
                if ('limit' in cached) {
                    const lv = cached.limit;
                    if (typeof lv === 'number' && Number.isFinite(lv)) {
                        data.limit = String(lv);
                    } else if (typeof lv === 'string') {
                        data.limit = lv;
                    }
                }

                const { page: _p, limit: _l, ...rest } = cached;
                data.serviceFilters = { ...rest };
            }
        }
    }

    /**
     * Reset the form to initial values
     */
    function reset(): void {
        Object.assign(data, defaultData);
        data.serviceFilters = {};
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

    function setService(nextService: string, nextDefaults?: Record<string, unknown>): void {
        const prevService = data.service;

        if (prevService) {
            filtersByServiceKey[prevService] = {
                ...data.serviceFilters,
                page: data.page,
                limit: data.limit,
            };
        }

        data.service = nextService;

        if (!nextService) {
            data.serviceFilters = {};
            data.page = 1;
            return;
        }

        const cached = filtersByServiceKey[nextService];
        if (cached) {
            if ('page' in cached) {
                data.page = (cached.page as string | number) ?? 1;
            } else {
                data.page = 1;
            }

            if ('limit' in cached) {
                const lv = cached.limit;
                if (typeof lv === 'number' && Number.isFinite(lv)) {
                    data.limit = String(lv);
                } else if (typeof lv === 'string' && lv.length) {
                    data.limit = lv;
                }
            }

            const { page: _p, limit: _l, ...rest } = cached;

            // Merge defaults into cached values when cached is missing/null/empty.
            // This fixes cases where a restored tab seeded nulls (e.g. `type: null`)
            // but the UI expects a default selection (e.g. `type: 'all'`).
            if (nextDefaults && typeof nextDefaults === 'object') {
                const reserved = new Set(['service', 'feed', 'source', 'tab_id', 'limit', 'page']);
                for (const [k, v] of Object.entries(nextDefaults)) {
                    if (reserved.has(k)) {
                        continue;
                    }

                    const current = (rest as Record<string, unknown>)[k];
                    if (current === undefined || current === null || current === '') {
                        (rest as Record<string, unknown>)[k] = v;
                    }
                }
            }

            data.serviceFilters = { ...rest };
            return;
        }

        // No cache: start from defaults for this service (if provided)
        data.page = 1;
        data.serviceFilters = {};

        if (nextDefaults && typeof nextDefaults === 'object') {
            const reserved = new Set(['service', 'feed', 'source', 'tab_id']);
            for (const [k, v] of Object.entries(nextDefaults)) {
                if (reserved.has(k)) {
                    continue;
                }
                if (k === 'limit') {
                    if (typeof v === 'number' && Number.isFinite(v)) {
                        data.limit = String(v);
                    } else if (typeof v === 'string' && v.length) {
                        data.limit = v;
                    }
                    continue;
                }
                if (k === 'page') {
                    if (typeof v === 'number' || typeof v === 'string') {
                        data.page = v as string | number;
                    }
                    continue;
                }
                data.serviceFilters[k] = v;
            }
        }
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
        setService,
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

