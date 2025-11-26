export interface ActiveFilter {
    key: string;
    label: string;
    rawValue: string;
    value: string;
}
// Note: We import markRaw to avoid Vue's ref-unwrapping when this class is made reactive.
// Without markRaw, refs stored on a reactive instance are unwrapped to their .value on access,
// which prevents us from updating the original refs from route query parameters.
import { markRaw, computed, reactive, type ComputedRef } from 'vue';

export interface HarmonieListingResponse<T> {
    listing: {
        items: T[];
        current_page?: number;
        total?: number;
        perPage?: number;
    };
    filters?: ActiveFilter[];
}

type ErrorHandler = (error: string | null, statusCode?: number) => string | null;
type FilterValue = string | number | { value: string | number | null | undefined } | null | undefined;

type DeleteConfig<TItem extends Record<string, unknown>> = {
    key?: string;
    refresh?: boolean;
    onSuccess?: (id: string | number, listing: Listing<TItem>) => void;
    onError?: (error: unknown, statusCode?: number, listing?: Listing<TItem>) => void;
};

export class Listing<T extends Record<string, unknown>> {
    public data: T[] = [];
    public isLoading = false;
    public error: string | null = null;
    public currentPage = 1;
    public perPage = 15;
    public total = 0;
    public filterValues: Record<string, string | number> = {};
    public activeFilters: ActiveFilter[] = [];
    private apiPath: string | null = null;
    private routerInstance: {
        push: (options: { query: Record<string, string> }) => Promise<unknown> | void;
        currentRoute: { value: { query: Record<string, unknown> } };
    } | null = null;
    private filterAttributes: Record<string, FilterValue> = {};
    private filterDefaults: Record<string, string | number | null> = {};
    private filterVisibility: Record<string, boolean> = {};
    private panelOpen = false;
    private buildQueryParameters: (() => Record<string, string>) | null = null;
    private errorHandler: ErrorHandler | null = null;

    /**
     * Vue bindings for filters, suitable for v-model usage in templates.
     */
    public bindings: Record<string, ComputedRef<string>> = {};

    /**
     * Factory helper to create a reactive Listing with optional initial filters.
     * Returns a Proxy that allows direct property access for filter keys (e.g., listing.date_from).
     */
    static create<T extends Record<string, unknown>>(options?: {
        filters?: Record<string, string | number | null>;
    }): Listing<T> {
        const instance = new Listing<T>();

        if (options?.filters) {
            instance.parameters(options.filters);
        }

        // Make the instance reactive
        const reactiveInstance = reactive(instance);

        // Create a Proxy that intercepts property access for filter keys
        return new Proxy(reactiveInstance, {
            get(target, prop) {
                // If accessing a filter key that exists in bindings, return the computed ref
                if (typeof prop === 'string' && prop in target.bindings) {
                    return target.bindings[prop];
                }
                // Otherwise, return the property normally
                return Reflect.get(target, prop);
            },
            set(target, prop, value) {
                // If setting a filter key that exists in bindings, update filters directly
                // The computed refs will reactively read from filters
                if (typeof prop === 'string' && prop in target.bindings) {
                    target.filters[prop] = String(value) as string | number | null;
                    return true;
                }
                // Otherwise, set the property normally
                return Reflect.set(target, prop, value);
            },
        }) as unknown as Listing<T>;
    }

    /**
     * Set the loading state to true
     */
    loading(): void {
        this.isLoading = true;
    }

    /**
     * Set the loading state to false
     */
    loaded(): void {
        this.isLoading = false;
    }

    /**
     * Set the API path for the listing
     * @param path - The API endpoint path (e.g., '/api/users')
     */
    path(path: string): this {
        this.apiPath = path;
        return this;
    }

    /**
     * Set the router instance for URL management
     * @param router - Router instance with push and currentRoute
     */
    router(router: {
        push: (options: { query: Record<string, string> }) => Promise<unknown> | void;
        currentRoute: { value: { query: Record<string, unknown> } };
    }): this {
        this.routerInstance = router;
        return this;
    }

    /**
     * Define filter attributes that will be automatically converted to filter parameters,
     * using external refs / values (legacy configuration style).
     *
     * Prefer using parameters() when you want Listing to own filter state.
     *
     * @param filters - Object mapping filter keys to refs or values
     */
    filterRefs(filters: Record<string, FilterValue>): this {
        // Avoid Vue ref-unwrapping by storing the filters object as a raw object.
        // This ensures we can detect and assign to ref.value within load().
        this.filterAttributes = markRaw(filters) as Record<string, FilterValue>;

        // Initialize defaults to null for all filters (can be overridden with defaults() method)
        this.filterDefaults = {};
        this.filterVisibility = {};

        for (const key of Object.keys(filters)) {
            this.filterDefaults[key] = null as unknown as string | number; // null is the default
            this.filterVisibility[key] = true; // visible by default
        }

        return this;
    }

    /**
     * Initialize filter state owned by Listing.
     *
     * This sets up:
     * - listing.filters[key] as the live reactive value
     * - defaults for resetFilters()/removeFilter()
     * - internal filterAttributes proxies used by URL sync
     */
    public filters: Record<string, string | number | null> = {};

    parameters(initials: Record<string, string | number | null>): this {
        // Live reactive state (once Listing instance is wrapped in reactive())
        this.filters = { ...initials };

        // Proxies / bindings that bridge existing internals to filters[key]
        const attrs: Record<string, FilterValue> = {};
        this.filterDefaults = {};
        this.filterVisibility = {};
        this.bindings = {};

        for (const [key, defaultValue] of Object.entries(initials)) {
            const binding = computed<string>({
                get: () => {
                    const current = this.filters[key];
                    return current === null || current === undefined ? '' : String(current);
                },
                set: (value: string) => {
                    // Store back as string; consumers can coerce to number if needed.
                    this.filters[key] = value as unknown as string | number | null;
                },
            });

            this.bindings[key] = binding;
            attrs[key] = binding as unknown as FilterValue;
            this.filterDefaults[key] = (defaultValue === null
                ? null
                : defaultValue) as unknown as string | number;
            this.filterVisibility[key] = true;
        }

        this.filterAttributes = markRaw(attrs) as Record<string, FilterValue>;

        return this;
    }

    /**
     * Set default values for filters (all filters default to null unless specified here)
     * @param defaults - Object mapping filter keys to their default values
     */
    defaults(defaults: Record<string, string | number | null>): this {
        for (const [key, value] of Object.entries(defaults)) {
            if (key in this.filterAttributes) {
                this.filterDefaults[key] = value === null ? null as unknown as string | number : value;
            }
        }
        return this;
    }

    /**
     * Panel visibility helpers (for filter UI panels or similar)
     */
    isPanelOpen(): boolean {
        return this.panelOpen;
    }

    openPanel(): void {
        this.panelOpen = true;
    }

    closePanel(): void {
        this.panelOpen = false;
    }

    togglePanel(): void {
        this.panelOpen = !this.panelOpen;
    }

    /**
     * Check if a given filter is currently visible.
     * If the filter has no explicit visibility state, it is treated as visible.
     */
    isFilterVisible(key: string): boolean {
        if (Object.prototype.hasOwnProperty.call(this.filterVisibility, key)) {
            return this.filterVisibility[key];
        }

        return true;
    }

    /**
     * Mark a given filter as visible.
     */
    showFilter(key: string): void {
        if (key in this.filterAttributes) {
            this.filterVisibility[key] = true;
        }
    }

    /**
     * Mark a given filter as hidden.
     */
    hideFilter(key: string): void {
        if (key in this.filterAttributes) {
            this.filterVisibility[key] = false;
        }
    }

    /**
     * Toggle visibility state for a given filter.
     */
    toggleFilter(key: string): void {
        if (key in this.filterAttributes) {
            this.filterVisibility[key] = !this.isFilterVisible(key);
        }
    }

    /**
     * Get a list of filter keys that are currently visible.
     */
    get visibleFilters(): string[] {
        return Object.entries(this.filterVisibility)
            .filter(([, visible]) => visible)
            .map(([key]) => key);
    }

    /**
     * Build filter parameters from configured filter attributes
     */
    private buildFilterParameters(): Record<string, string | number> {
        const parameters: Record<string, string | number> = {};

        // Read directly from filters if available (new parameters() API)
        // Otherwise fall back to filterAttributes (legacy filterRefs() API)
        const source = Object.keys(this.filters).length > 0 ? this.filters : this.filterAttributes;

        for (const [key, value] of Object.entries(source)) {
            // Extract value - if it's from filters, it's already the actual value
            // If it's from filterAttributes, it might be a ref-like object
            let actualValue: string | number | null | undefined;
            if (source === this.filters) {
                // Direct value from filters
                actualValue = value as string | number | null | undefined;
            } else if (value && typeof value === 'object' && 'value' in value) {
                // Extract from ref-like object (legacy API)
                actualValue = (value as { value: string | number | null | undefined }).value;
            } else {
                actualValue = value as string | number | null | undefined;
            }

            // Skip empty values
            if (actualValue === null || actualValue === undefined || actualValue === '') {
                continue;
            }

            // Skip 'all' values (common default for select filters)
            if (actualValue === 'all') {
                continue;
            }

            // Trim string values
            if (typeof actualValue === 'string') {
                const trimmed = actualValue.trim();
                if (trimmed === '') {
                    continue;
                }
                parameters[key] = trimmed;
            } else {
                parameters[key] = actualValue;
            }
        }

        return parameters;
    }

    /**
     * Set the query parameters builder function for URL updates
     * @param builder - Function that returns query parameters for the URL
     */
    queryParameters(builder: () => Record<string, string>): this {
        this.buildQueryParameters = builder;
        return this;
    }

    /**
     * Set the error handler function to customize load error messages
     * @param handler - Function that receives error message and status code, returns customized error message
     */
    onLoadError(handler: ErrorHandler): this {
        this.errorHandler = handler;
        return this;
    }

    /**
     * Normalize params from various input types
     */
    private normalizeParams(
        params: string | Record<string, string | number> | (() => Record<string, string | number>) | undefined
    ): Record<string, string | number> {
        if (!params) {
            return {};
        }

        // Handle string - could be query string or method name
        if (typeof params === 'string') {
            // If it starts with ? or &, parse as query string
            if (params.startsWith('?') || params.startsWith('&')) {
                const urlParams = new URLSearchParams(params.startsWith('?') ? params.slice(1) : params);
                const result: Record<string, string | number> = {};
                for (const [key, value] of urlParams.entries()) {
                    const numValue = Number(value);
                    result[key] = isNaN(numValue) ? value : numValue;
                }
                return result;
            }
            // Otherwise, treat as method name and call it if it exists
            const method = (this as unknown as Record<string, () => Record<string, string | number>>)[params];
            if (typeof method === 'function') {
                return method();
            }
            // If not a method, return empty (or could throw error)
            return {};
        }

        // Handle function/callback
        if (typeof params === 'function') {
            return params();
        }

        // Handle object
        return params;
    }

    /**
     * Get data from the configured API path (axios.get style signature)
     * Automatically syncs filters and pagination from URL query parameters if router is configured
     *
     * Usage:
     * - get() - uses configured path from .path()
     * - get('/api/users') - uses provided path
     * - get('/api/users', { query }) - uses provided path with config
     * - get({ query }) - uses configured path with config (when first param is object)
     *
     * @param pathOrConfig - API endpoint path (string) or config object (when path is configured)
     * @param config - Configuration object with params and optional query (only when path is provided)
     * @param config.params - Query parameters (string, object, callback, or method name)
     * @param config.query - Optional query object to sync from (if not provided, reads from router)
     */
    async get(
        pathOrConfig?: string | {
            params?: string | Record<string, string | number> | (() => Record<string, string | number>);
            query?: Record<string, unknown>;
        },
        config?: {
            params?: string | Record<string, string | number> | (() => Record<string, string | number>);
            query?: Record<string, unknown>;
        }
    ): Promise<void> {
        // Determine if first parameter is path (string) or config (object)
        let apiPath: string | null;
        let actualConfig: {
            params?: string | Record<string, string | number> | (() => Record<string, string | number>);
            query?: Record<string, unknown>;
        } | undefined;

        if (typeof pathOrConfig === 'string') {
            // First param is a path string
            apiPath = pathOrConfig;
            actualConfig = config;
        } else if (pathOrConfig && typeof pathOrConfig === 'object') {
            // First param is a config object (using configured path)
            apiPath = this.apiPath;
            actualConfig = pathOrConfig;
        } else {
            // No params provided, use configured path
            apiPath = this.apiPath;
            actualConfig = undefined;
        }

        if (!apiPath) {
            throw new Error('API path must be provided either as parameter or via path() method');
        }

        // Sync from URL query parameters if router is configured or query is provided
        let routeQuery: Record<string, unknown> = {};
        const hasExplicitQuery = actualConfig?.query !== undefined;

        if (hasExplicitQuery && actualConfig?.query) {
            // Use provided query (from component's useRoute() or explicit query)
            routeQuery = actualConfig.query as Record<string, unknown>;
        } else if (this.routerInstance) {
            // Read from router instance only if no explicit query was provided
            const currentRoute = this.routerInstance.currentRoute;
            if (currentRoute && currentRoute.value) {
                routeQuery = currentRoute.value.query || {};
            }
        }

        // Normalize params from config
        const parameters = actualConfig?.params ? this.normalizeParams(actualConfig.params) : {};

        // Update filters and pagination from query if available
        // Only sync if we have query values AND either:
        // 1. No explicit query was provided (read from router), OR
        // 2. Explicit query was provided and has values (sync from provided query)
        if (Object.keys(routeQuery).length > 0) {
            // Update filter values from URL query parameters
            // Prefer updating this.filters directly (new parameters() API)
            // Otherwise fall back to filterAttributes (legacy filterRefs() API)
            const filterKeys = Object.keys(this.filters).length > 0
                ? Object.keys(this.filters)
                : Object.keys(this.filterAttributes);

            for (const key of filterKeys) {
                const queryValue = routeQuery[key];

                if (queryValue !== undefined && queryValue !== null) {
                    // Handle array values (Vue Router can return arrays for query params)
                    const stringValue = Array.isArray(queryValue)
                        ? String(queryValue[0])
                        : String(queryValue);

                    // Special handling for status filter - validate against allowed values
                    if (key === 'status' && !['verified', 'unverified'].includes(stringValue)) {
                        continue; // Skip invalid status values
                    }

                    // Update the filter value
                    if (key in this.filters) {
                        // New API: update filters directly
                        this.filters[key] = stringValue as string | number | null;
                    } else if (key in this.filterAttributes) {
                        // Legacy API: update via filterAttributes ref
                        const filterValue = this.filterAttributes[key];
                        if (filterValue && typeof filterValue === 'object' && 'value' in filterValue) {
                            (filterValue as { value: string | number }).value = stringValue;
                        }
                    }
                }
            }

            // Update pagination from URL
            if (routeQuery.page) {
                const page = parseInt(String(routeQuery.page), 10);
                if (!isNaN(page) && page > 0) {
                    this.currentPage = page;
                }
            }
        }

        try {
            this.loading();
            this.error = null;

            // Build filter parameters from configured filter attributes
            const filterParameters = this.buildFilterParameters();

            const requestParameters: Record<string, string | number> = {
                page: this.currentPage,
                per_page: this.perPage,
                ...filterParameters,
                ...parameters,
            };

            const response = await window.axios.get<HarmonieListingResponse<T>>(apiPath, {
                params: requestParameters,
            });

            const listing = response.data.listing || {};
            this.data = listing.items || [];
            this.currentPage = listing.current_page ?? 1;
            this.total = listing.total ?? 0;
            this.perPage = listing.perPage ?? 15;
            this.activeFilters = response.data.filters || [];
        } catch (err: unknown) {
            const axiosError = err as { response?: { status?: number; data?: { message?: string } } };
            const statusCode = axiosError.response?.status;

            // Determine default error message
            let defaultError: string;
            if (statusCode === 403) {
                defaultError = 'You do not have permission to access this resource.';
            } else {
                defaultError = axiosError.response?.data?.message || 'Failed to load data. Please try again later.';
            }

            // Apply error handler if configured
            if (this.errorHandler) {
                this.error = this.errorHandler(defaultError, statusCode);
            } else {
                this.error = defaultError;
            }

            console.error('Error loading listing:', err);
        } finally {
            this.loaded();
        }
    }

    /**
     * Remove an item from the data array by its ID or specified key
     * @param id - The ID value to match
     * @param key - The property key to match against (defaults to 'id')
     */
    remove(id: unknown, key: string = 'id'): void {
        this.data = this.data.filter((item) => item[key] !== id);
        this.total = Math.max(0, this.total - 1);
    }

    /**
     * Delete an item on the server and update the listing.
     *
     * Flexible signature, similar in spirit to get():
     * - delete(path, id)
     * - delete(path, { id, key, refresh })
     * - delete({ id, key, refresh })  // uses configured path from path()
     */
    async delete(
        path: string,
        id: string | number,
        config?: DeleteConfig<T>
    ): Promise<void>;

    async delete(
        path: string,
        config: { id: string | number } & DeleteConfig<T>
    ): Promise<void>;

    async delete(config: { id: string | number } & DeleteConfig<T>): Promise<void>;

    async delete(
        pathOrConfig: string | ({ id: string | number } & DeleteConfig<T>),
        idOrConfig?: string | number | DeleteConfig<T>,
        maybeConfig?: DeleteConfig<T>
    ): Promise<void> {
        let path: string | null = null;
        let id: string | number;
        let key = 'id';
        let refresh = true;
        let onSuccess: ((id: string | number, listing: Listing<T>) => void) | undefined;
        let onError: ((error: unknown, statusCode?: number, listing?: Listing<T>) => void) | undefined;

        if (typeof pathOrConfig === 'string') {
            path = pathOrConfig;

            if (typeof idOrConfig === 'object' && idOrConfig !== null) {
                const configObject = idOrConfig as { id: string | number } & DeleteConfig<T>;
                id = configObject.id;
                key = configObject.key ?? 'id';
                refresh = configObject.refresh ?? true;
                onSuccess = configObject.onSuccess;
                onError = configObject.onError;
            } else {
                if (idOrConfig === undefined || idOrConfig === null) {
                    throw new Error('ID must be provided when calling delete(path, id)');
                }
                id = idOrConfig as string | number;
                key = maybeConfig?.key ?? 'id';
                refresh = maybeConfig?.refresh ?? true;
                onSuccess = maybeConfig?.onSuccess;
                onError = maybeConfig?.onError;
            }
        } else {
            if (!this.apiPath) {
                throw new Error('API path must be provided either as parameter or via path() method');
            }

            id = pathOrConfig.id;
            key = pathOrConfig.key ?? 'id';
            refresh = pathOrConfig.refresh ?? true;
            onSuccess = pathOrConfig.onSuccess;
            onError = pathOrConfig.onError;
            path = `${this.apiPath}/${id}`;
        }

        try {
            await window.axios.delete(path as string);

            this.remove(id, key);

            if (this.data.length === 0 && this.currentPage > 1) {
                await this.goToPage(this.currentPage - 1);
            } else if (refresh) {
                await this.get();
            }

            if (onSuccess) {
                onSuccess(id, this);
            }
        } catch (error: unknown) {
            // Attempt to extract HTTP status code if this looks like an Axios error
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const maybeAxiosError = error as any;
            const statusCode: number | undefined = maybeAxiosError?.response?.status;

            if (onError) {
                onError(error, statusCode, this);
                return;
            }

            // If no onError callback is provided, rethrow so callers can handle it.
            throw error;
        }
    }

    /**
     * Set filters for the listing (legacy method - use defineFilters instead)
     * @param filters - Object containing filter key-value pairs
     */
    setFilters(filters: Record<string, string | number>): void {
        this.filterValues = { ...filters };
    }

    /**
     * Clear all filters (legacy method)
     */
    clearFilters(): void {
        this.filterValues = {};
    }

    /**
     * Go to a specific page and optionally update URL and reload data
     * @param page - Page number to navigate to
     * @param perPage - Items per page
     * @param autoLoad - Whether to automatically update URL and reload data (default: true if configured)
     */
    async goToPage(page: number, perPage?: number, autoLoad?: boolean): Promise<void> {
        this.currentPage = page;
        if (perPage !== undefined) {
            this.perPage = perPage;
        }

        // Auto-load if configured and autoLoad is not explicitly false
        const shouldAutoLoad = autoLoad !== false && (this.apiPath !== null || this.routerInstance !== null);
        if (shouldAutoLoad) {
            await this.updateUrl();
            await this.get(); // Uses configured path from .path()
        }
    }

    /**
     * Load data from the configured API path (legacy method - use get() instead)
     * @deprecated Use get() instead for axios.get style signature
     */
    async load(path?: string, parameters?: Record<string, string | number>, query?: Record<string, unknown>): Promise<void> {
        await this.get(path, {
            params: parameters,
            query,
        });
    }

    /**
     * Update the URL query parameters based on current state
     */
    private async updateUrl(): Promise<void> {
        if (!this.routerInstance) {
            return;
        }

        const query: Record<string, string> = {};

        // Build query parameters from configured builder or use filter attributes
        if (this.buildQueryParameters) {
            Object.assign(query, this.buildQueryParameters());
        } else {
            // Default: include page if > 1, and all filters
            if (this.currentPage > 1) {
                query.page = String(this.currentPage);
            }

            const filterParameters = this.buildFilterParameters();
            for (const [key, value] of Object.entries(filterParameters)) {
                query[key] = String(value);
            }
        }

        try {
            await this.routerInstance.push({ query });
        } catch {
            // Ignore navigation errors (e.g., navigating to same route)
        }
    }

    /**
     * Reset all filters to their default values and reload data
     */
    async resetFilters(): Promise<void> {
        if (!this.filterDefaults || Object.keys(this.filterDefaults).length === 0) {
            // No filters configured, just reset pagination
            await this.goToPage(1);
            return;
        }

        // Reset all filter values to their defaults
        for (const [key, defaultValue] of Object.entries(this.filterDefaults)) {
            if (key in this.filters) {
                // New API: update filters directly
                this.filters[key] = defaultValue;
            } else if (key in this.filterAttributes) {
                // Legacy API: update via filterAttributes ref
                const filterValue = this.filterAttributes[key];
                if (filterValue && typeof filterValue === 'object' && 'value' in filterValue) {
                    const ref = filterValue as { value: string | number | null | undefined };
                    ref.value = defaultValue;
                }
            }
        }

        // Build the query from current filter state (after reset) - this will exclude default values
        // Then update URL and reload with explicit query to prevent reading stale router query
        this.currentPage = 1;
        const query: Record<string, string> = {};
        const filterParameters = this.buildFilterParameters();
        for (const [key, value] of Object.entries(filterParameters)) {
            query[key] = String(value);
        }

        await this.updateUrl();

        // Pass the query explicitly to prevent get() from reading stale router query
        // This ensures we use the current filter state, not the old URL params
        await this.get(undefined, { query });
    }

    /**
     * Remove/reset a specific filter to its default value and reload data
     * @param filterKey - The key of the filter to remove
     */
    async removeFilter(filterKey: string): Promise<void> {
        if (!this.filterDefaults || !(filterKey in this.filterDefaults)) {
            // Filter key doesn't exist or no defaults configured - do nothing
            return;
        }

        const defaultValue = this.filterDefaults[filterKey];
        if (filterKey in this.filters) {
            // New API: update filters directly
            this.filters[filterKey] = defaultValue;
        } else if (filterKey in this.filterAttributes) {
            // Legacy API: update via filterAttributes ref
            const filterValue = this.filterAttributes[filterKey];
            if (filterValue && typeof filterValue === 'object' && 'value' in filterValue) {
                const ref = filterValue as { value: string | number | null | undefined };
                ref.value = defaultValue;
            }
        }

        // Build query from current filter state (which now has the reset value)
        // and pass it explicitly to prevent get() from reading stale router query
        this.currentPage = 1;
        const query: Record<string, string> = {};
        const filterParameters = this.buildFilterParameters();
        for (const [key, value] of Object.entries(filterParameters)) {
            query[key] = String(value);
        }

        await this.updateUrl();
        await this.get(undefined, { query });
    }

    /**
     * Apply current filters - go to first page and close filter panel
     * Common pattern for filter application in UI components
     */
    async applyFilters(): Promise<void> {
        await this.goToPage(1); // Reset to first page when applying filters
        this.closePanel();
    }

    /**
     * Check if there are any active filters applied
     */
    get hasActiveFilters(): boolean {
        return this.activeFilters.length > 0;
    }

    /**
     * Reset the listing to initial state
     */
    reset(): void {
        this.data = [];
        this.loaded();
        this.error = null;
        this.currentPage = 1;
        this.perPage = 15;
        this.total = 0;
        this.filterValues = {};
        this.activeFilters = [];
    }
}
