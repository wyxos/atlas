export interface ActiveFilter {
    key: string;
    label: string;
    rawValue: string;
    value: string;
}
// Note: We import markRaw to avoid Vue's ref-unwrapping when this class is made reactive.
// Without markRaw, refs stored on a reactive instance are unwrapped to their .value on access,
// which prevents us from updating the original refs from route query parameters.
import { markRaw } from 'vue';

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
    private buildQueryParameters: (() => Record<string, string>) | null = null;
    private errorHandler: ErrorHandler | null = null;

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
     * Define filter attributes that will be automatically converted to filter parameters
     * @param filters - Object mapping filter keys to refs or values
     */
    filters(filters: Record<string, FilterValue>): this {
        // Avoid Vue ref-unwrapping by storing the filters object as a raw object.
        // This ensures we can detect and assign to ref.value within load().
        this.filterAttributes = markRaw(filters) as Record<string, FilterValue>;
        return this;
    }

    /**
     * Build filter parameters from configured filter attributes
     */
    private buildFilterParameters(): Record<string, string | number> {
        const parameters: Record<string, string | number> = {};

        for (const [key, value] of Object.entries(this.filterAttributes)) {
            // Extract value from ref if it's an object with .value property
            let actualValue: string | number | null | undefined;
            if (value && typeof value === 'object' && 'value' in value) {
                actualValue = value.value;
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

        if (actualConfig?.query) {
            // Use provided query (from component's useRoute())
            routeQuery = actualConfig.query;
        } else if (this.routerInstance) {
            // Read from router instance
            const currentRoute = this.routerInstance.currentRoute;
            if (currentRoute && currentRoute.value) {
                routeQuery = currentRoute.value.query || {};
            }
        }

        // Normalize params from config
        const parameters = actualConfig?.params ? this.normalizeParams(actualConfig.params) : {};

        // Update filters and pagination from query if available
        if (Object.keys(routeQuery).length > 0) {
            // Update filter values from URL query parameters
            for (const [key, filterValue] of Object.entries(this.filterAttributes)) {
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

                    // Update the filter value (handle both refs and direct values)
                    if (filterValue && typeof filterValue === 'object' && 'value' in filterValue) {
                        // It's a ref-like object with .value property
                        (filterValue as { value: string | number }).value = stringValue;
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

