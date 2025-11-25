export interface ActiveFilter {
    key: string;
    label: string;
    rawValue: string;
    value: string;
}

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
    public loading = false;
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
     * Set the loading state
     * @param loading - Whether the listing is loading
     */
    setLoading(loading: boolean): void {
        this.loading = loading;
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
        this.filterAttributes = filters;
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
     * Set the error handler function to customize error messages
     * @param handler - Function that receives error message and status code, returns customized error message
     */
    onError(handler: ErrorHandler): this {
        this.errorHandler = handler;
        return this;
    }

    /**
     * Load data from the configured API path
     * @param path - Optional API endpoint path override
     * @param parameters - Optional query parameters to include in the request
     */
    async load(path?: string, parameters?: Record<string, string | number>): Promise<void> {
        const apiPath = path || this.apiPath;
        if (!apiPath) {
            throw new Error('API path must be provided either as parameter or via path() method');
        }

        try {
            this.setLoading(true);
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
            this.setLoading(false);
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
     * Set pagination parameters and optionally update URL and reload data
     * @param page - Page number
     * @param perPage - Items per page
     * @param autoLoad - Whether to automatically update URL and reload data (default: true if configured)
     */
    async setPagination(page: number, perPage?: number, autoLoad?: boolean): Promise<void> {
        this.currentPage = page;
        if (perPage !== undefined) {
            this.perPage = perPage;
        }

        // Auto-load if configured and autoLoad is not explicitly false
        const shouldAutoLoad = autoLoad !== false && (this.apiPath !== null || this.routerInstance !== null);
        if (shouldAutoLoad) {
            await this.updateUrl();
            await this.load();
        }
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
        this.setLoading(false);
        this.error = null;
        this.currentPage = 1;
        this.perPage = 15;
        this.total = 0;
        this.filterValues = {};
        this.activeFilters = [];
    }
}

