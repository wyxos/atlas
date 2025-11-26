import { describe, it, expect, vi, beforeEach } from 'vitest';
import { Listing } from './Listing';

interface TestItem extends Record<string, unknown> {
    id: number;
    name: string;
}

// Mock window.axios
const mockAxios = {
    get: vi.fn(),
    delete: vi.fn(),
};

beforeEach(() => {
    vi.clearAllMocks();
    // Suppress console.error output during tests to reduce noise
    vi.spyOn(console, 'error').mockImplementation(() => {});
    Object.assign(global.window, {
        axios: mockAxios,
    });
});

function createHarmonieResponse(items: TestItem[], currentPage = 1, total?: number, perPage = 15) {
    const itemTotal = total ?? items.length;
    const lastPage = Math.ceil(itemTotal / perPage);
    const from = items.length > 0 ? (currentPage - 1) * perPage + 1 : null;
    const to = items.length > 0 ? from! + items.length - 1 : null;

    return {
        data: {
            listing: {
                items,
                total: itemTotal,
                perPage,
                current_page: currentPage,
                last_page: lastPage,
                from,
                to,
                showing: to ?? 0,
                nextPage: currentPage < lastPage ? currentPage + 1 : null,
            },
            links: {
                first: '/api/test?page=1',
                last: `/api/test?page=${lastPage}`,
                prev: currentPage > 1 ? `/api/test?page=${currentPage - 1}` : null,
                next: currentPage < lastPage ? `/api/test?page=${currentPage + 1}` : null,
            },
            filters: [],
        },
    };
}

describe('Listing', () => {
    describe('loading() and loaded()', () => {
        it('sets loading to true when loading() is called', () => {
            const listing = new Listing<TestItem>();
            expect(listing.isLoading).toBe(false);

            listing.loading();
            expect(listing.isLoading).toBe(true);
        });

        it('sets loading to false when loaded() is called', () => {
            const listing = new Listing<TestItem>();
            listing.loading();
            expect(listing.isLoading).toBe(true);

            listing.loaded();
            expect(listing.isLoading).toBe(false);
        });

        it('sets loading to true during load() and false after completion', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            const mockItems: TestItem[] = [
                { id: 1, name: 'Item 1' },
            ];

            mockAxios.get.mockResolvedValue(createHarmonieResponse(mockItems, 1, 1));

            expect(listing.isLoading).toBe(false);

            const loadPromise = listing.load();

            // Loading should be true while request is in progress
            expect(listing.isLoading).toBe(true);

            await loadPromise;

            // Loading should be false after completion
            expect(listing.isLoading).toBe(false);
        });

        it('sets loading to false even when load() fails', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            mockAxios.get.mockRejectedValue(new Error('Network error'));

            expect(listing.isLoading).toBe(false);

            await listing.load();

            // Loading should be false even after error
            expect(listing.isLoading).toBe(false);
        });
    });

    describe('onLoadError()', () => {
        it('uses default error message when onLoadError is not configured', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            mockAxios.get.mockRejectedValue(new Error('Network error'));

            await listing.load();

            expect(listing.error).toBe('Failed to load data. Please try again later.');
        });

        it('uses custom error handler when onLoadError is configured', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');
            listing.onLoadError((error, statusCode) => {
                return `Custom error: ${error} (${statusCode ?? 'no-status'})`;
            });

            const axiosError = new Error('Network error');
            (axiosError as { response?: { status?: number; data?: { message?: string } } }).response = {
                status: 500,
                data: { message: 'Server error' }
            };
            mockAxios.get.mockRejectedValue(axiosError);

            await listing.load();

            expect(listing.error).toBe('Custom error: Server error (500)');
        });

        it('handles 403 status code with custom message', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');
            listing.onLoadError((error, statusCode) => {
                if (statusCode === 403) {
                    return 'You do not have permission to access this resource.';
                }
                return error;
            });

            const axiosError = new Error('Forbidden');
            (axiosError as { response?: { status?: number } }).response = { status: 403 };
            mockAxios.get.mockRejectedValue(axiosError);

            await listing.load();

            // The default error handler in Listing already handles 403, but our custom handler should override it
            expect(listing.error).toBe('You do not have permission to access this resource.');
        });

        it('returns null from error handler to clear error', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');
            listing.onLoadError(() => {
                return null; // Clear the error
            });

            mockAxios.get.mockRejectedValue(new Error('Network error'));

            await listing.load();

            expect(listing.error).toBeNull();
        });

        it('can chain onLoadError with other methods', () => {
            const listing = new Listing<TestItem>();
            const result = listing
                .path('/api/test')
                .onLoadError(() => 'Custom error');

            expect(result).toBe(listing);
            expect(listing.error).toBeNull(); // Error handler is set but not called yet
        });
    });

    describe('integration with load()', () => {
        it('calls loading() at start and loaded() at end of load()', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            const mockItems: TestItem[] = [
                { id: 1, name: 'Item 1' },
            ];

            mockAxios.get.mockResolvedValue(createHarmonieResponse(mockItems, 1, 1));

            // Before load, should be false
            expect(listing.isLoading).toBe(false);

            // Start loading (this happens inside load())
            const loadPromise = listing.load();

            // During load, should be true (loading() was called)
            expect(listing.isLoading).toBe(true);

            // Wait for load to complete
            await loadPromise;

            // After load, should be false (loaded() was called)
            expect(listing.isLoading).toBe(false);
        });

        it('calls onLoadError handler when load fails', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            const errorHandler = vi.fn((error) => `Handled: ${error}`);
            listing.onLoadError(errorHandler);

            mockAxios.get.mockRejectedValue(new Error('Network error'));

            await listing.load();

            expect(errorHandler).toHaveBeenCalledWith(
                'Failed to load data. Please try again later.',
                undefined,
            );
            expect(listing.error).toBe('Handled: Failed to load data. Please try again later.');
        });
    });

    describe('get() method with axios.get style signature', () => {
        it('works with no config (uses configured path)', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            const mockItems: TestItem[] = [
                { id: 1, name: 'Item 1' },
            ];

            mockAxios.get.mockResolvedValue(createHarmonieResponse(mockItems, 1, 1));

            await listing.get();

            expect(mockAxios.get).toHaveBeenCalledWith('/api/test', {
                params: expect.objectContaining({
                    page: 1,
                    per_page: 15,
                }),
            });
            expect(listing.data).toEqual(mockItems);
        });

        it('works with path override', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            const mockItems: TestItem[] = [
                { id: 1, name: 'Item 1' },
            ];

            mockAxios.get.mockResolvedValue(createHarmonieResponse(mockItems, 1, 1));

            await listing.get('/api/other');

            expect(mockAxios.get).toHaveBeenCalledWith('/api/other', {
                params: expect.objectContaining({
                    page: 1,
                    per_page: 15,
                }),
            });
        });

        it('handles params as object', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            const mockItems: TestItem[] = [
                { id: 1, name: 'Item 1' },
            ];

            mockAxios.get.mockResolvedValue(createHarmonieResponse(mockItems, 1, 1));

            await listing.get('/api/test', {
                params: { search: 'test', limit: 10 },
            });

            expect(mockAxios.get).toHaveBeenCalledWith('/api/test', {
                params: expect.objectContaining({
                    page: 1,
                    per_page: 15,
                    search: 'test',
                    limit: 10,
                }),
            });
        });

        it('handles params as query string', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            const mockItems: TestItem[] = [
                { id: 1, name: 'Item 1' },
            ];

            mockAxios.get.mockResolvedValue(createHarmonieResponse(mockItems, 1, 1));

            await listing.get('/api/test', {
                params: '?search=test&limit=10',
            });

            expect(mockAxios.get).toHaveBeenCalledWith('/api/test', {
                params: expect.objectContaining({
                    page: 1,
                    per_page: 15,
                    search: 'test',
                    limit: 10,
                }),
            });
        });

        it('handles params as callback function', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            const mockItems: TestItem[] = [
                { id: 1, name: 'Item 1' },
            ];

            mockAxios.get.mockResolvedValue(createHarmonieResponse(mockItems, 1, 1));

            await listing.get('/api/test', {
                params: () => ({ search: 'test', limit: 10 }),
            });

            expect(mockAxios.get).toHaveBeenCalledWith('/api/test', {
                params: expect.objectContaining({
                    page: 1,
                    per_page: 15,
                    search: 'test',
                    limit: 10,
                }),
            });
        });

        it('handles query parameter for route sync', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            const mockItems: TestItem[] = [
                { id: 1, name: 'Item 1' },
            ];

            mockAxios.get.mockResolvedValue(createHarmonieResponse(mockItems, 2, 20));

            await listing.get('/api/test', {
                query: { page: '2', search: 'test' },
            });

            expect(listing.currentPage).toBe(2);
            expect(mockAxios.get).toHaveBeenCalledWith('/api/test', {
                params: expect.objectContaining({
                    page: 2,
                    per_page: 15,
                }),
            });
        });

        it('load() still works as deprecated wrapper', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            const mockItems: TestItem[] = [
                { id: 1, name: 'Item 1' },
            ];

            mockAxios.get.mockResolvedValue(createHarmonieResponse(mockItems, 1, 1));

            await listing.load();

            expect(mockAxios.get).toHaveBeenCalledWith('/api/test', {
                params: expect.objectContaining({
                    page: 1,
                    per_page: 15,
                }),
            });
            expect(listing.data).toEqual(mockItems);
        });

        it('allows config as first parameter when path is configured', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            const mockItems: TestItem[] = [
                { id: 1, name: 'Item 1' },
            ];

            mockAxios.get.mockResolvedValue(createHarmonieResponse(mockItems, 1, 1));

            // Pass config as first parameter (no undefined needed!)
            await listing.get({ params: { search: 'test' } });

            expect(mockAxios.get).toHaveBeenCalledWith('/api/test', {
                params: expect.objectContaining({
                    page: 1,
                    per_page: 15,
                    search: 'test',
                }),
            });
        });

        it('allows config with query as first parameter when path is configured', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            const mockItems: TestItem[] = [
                { id: 1, name: 'Item 1' },
            ];

            mockAxios.get.mockResolvedValue(createHarmonieResponse(mockItems, 2, 20));

            // Pass config with query as first parameter
            await listing.get({ query: { page: '2', search: 'test' } });

            expect(listing.currentPage).toBe(2);
            expect(mockAxios.get).toHaveBeenCalledWith('/api/test', {
                params: expect.objectContaining({
                    page: 2,
                    per_page: 15,
                }),
            });
        });
    });

    describe('resetFilters() and removeFilter()', () => {
        it('resets all filters to their default values', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            const searchRef = { value: 'test search' };
            const statusRef = { value: 'verified' };

            listing.filterRefs({
                search: searchRef,
                status: statusRef,
            }).defaults({
                search: '',
                status: 'all',
            });

            // Change values
            searchRef.value = 'changed';
            statusRef.value = 'verified';

            const mockItems: TestItem[] = [
                { id: 1, name: 'Item 1' },
            ];
            mockAxios.get.mockResolvedValue(createHarmonieResponse(mockItems, 1, 1));

            await listing.resetFilters();

            expect(searchRef.value).toBe('');
            expect(statusRef.value).toBe('all');
            expect(mockAxios.get).toHaveBeenCalled();
        });

        it('removes a specific filter by key', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            const searchRef = { value: 'test search' };
            const statusRef = { value: 'verified' };

            listing.filterRefs({
                search: searchRef,
                status: statusRef,
            });

            const mockItems: TestItem[] = [
                { id: 1, name: 'Item 1' },
            ];
            mockAxios.get.mockResolvedValue(createHarmonieResponse(mockItems, 1, 1));

            await listing.removeFilter('search');

            expect(searchRef.value).toBeNull();
            expect(statusRef.value).toBe('verified'); // Should remain unchanged
            expect(mockAxios.get).toHaveBeenCalled();
        });

        it('uses provided default values when configuring filters', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            const searchRef = { value: 'test search' };
            const statusRef = { value: 'verified' };

            listing.filterRefs({
                search: searchRef,
                status: statusRef,
            }).defaults({
                search: '',
                status: 'none', // Custom default
            });

            const mockItems: TestItem[] = [
                { id: 1, name: 'Item 1' },
            ];
            mockAxios.get.mockResolvedValue(createHarmonieResponse(mockItems, 1, 1));

            await listing.resetFilters();

            expect(searchRef.value).toBe('');
            expect(statusRef.value).toBe('none'); // Uses provided default
        });

        it('does nothing when removing a non-existent filter', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            const searchRef = { value: 'test search' };

            listing.filterRefs({
                search: searchRef,
            });

            // Should not throw or cause issues
            await listing.removeFilter('nonexistent');

            expect(searchRef.value).toBe('test search'); // Unchanged
            expect(mockAxios.get).not.toHaveBeenCalled();
        });
    });

    describe('delete()', () => {
        it('deletes an item using path and id, removes it locally, and refreshes the page', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            listing.data = [
                { id: 1, name: 'Item 1' },
                { id: 2, name: 'Item 2' },
            ];
            listing.total = 2;
            listing.currentPage = 1;

            mockAxios.delete.mockResolvedValue({});
            mockAxios.get.mockResolvedValue(createHarmonieResponse([{ id: 2, name: 'Item 2' }], 1, 1));

            await listing.delete('/api/test/1', 1);

            expect(mockAxios.delete).toHaveBeenCalledWith('/api/test/1');
            expect(listing.data).toEqual([{ id: 2, name: 'Item 2' }]);
            expect(listing.total).toBe(1);
            expect(mockAxios.get).toHaveBeenCalled();
        });

        it('deletes an item using configured path and id from config object', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            listing.data = [
                { id: 1, name: 'Item 1' },
                { id: 2, name: 'Item 2' },
            ];
            listing.total = 2;
            listing.currentPage = 1;

            mockAxios.delete.mockResolvedValue({});
            mockAxios.get.mockResolvedValue(createHarmonieResponse([{ id: 2, name: 'Item 2' }], 1, 1));

            await listing.delete({ id: 1 });

            expect(mockAxios.delete).toHaveBeenCalledWith('/api/test/1');
            expect(listing.data).toEqual([{ id: 2, name: 'Item 2' }]);
            expect(listing.total).toBe(1);
        });

        it('goes to previous page when current page becomes empty after delete', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            listing.data = [{ id: 3, name: 'Item 3' }];
            listing.total = 3;
            listing.currentPage = 2;

            mockAxios.delete.mockResolvedValue({});
            mockAxios.get
                .mockResolvedValueOnce(createHarmonieResponse([{ id: 1, name: 'Item 1' }, { id: 2, name: 'Item 2' }], 1, 2));

            await listing.delete('/api/test/3', 3);

            expect(listing.currentPage).toBe(1);
            expect(mockAxios.get).toHaveBeenCalled();
        });

        it('calls onSuccess callback after successful delete', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            listing.data = [
                { id: 1, name: 'Item 1' },
                { id: 2, name: 'Item 2' },
            ];
            listing.total = 2;
            listing.currentPage = 1;

            mockAxios.delete.mockResolvedValue({});
            mockAxios.get.mockResolvedValue(createHarmonieResponse([{ id: 2, name: 'Item 2' }], 1, 1));

            const onSuccess = vi.fn();

            await listing.delete('/api/test/1', 1, { onSuccess });

            expect(onSuccess).toHaveBeenCalledWith(1, listing);
        });

        it('calls onError callback when delete fails and does not throw', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            listing.data = [
                { id: 1, name: 'Item 1' },
            ];
            listing.total = 1;
            listing.currentPage = 1;

            const error = { response: { status: 500 } };
            mockAxios.delete.mockRejectedValue(error);

            const onError = vi.fn();

            await expect(
                listing.delete('/api/test/1', 1, { onError })
            ).resolves.toBeUndefined();

            expect(onError).toHaveBeenCalledWith(error, 500, listing);
            expect(listing.data).toEqual([{ id: 1, name: 'Item 1' }]);
            expect(listing.total).toBe(1);
            expect(mockAxios.get).not.toHaveBeenCalled();
        });

        it('rethrows error when delete fails and no onError callback is provided', async () => {
            const listing = new Listing<TestItem>();
            listing.path('/api/test');

            const error = new Error('Delete failed');
            mockAxios.delete.mockRejectedValue(error);

            await expect(
                listing.delete('/api/test/1', 1)
            ).rejects.toBe(error);
        });
    });

    describe('filter visibility', () => {
        it('defaults all filters to visible after filters() is called', () => {
            const listing = new Listing<TestItem>();

            const searchRef = { value: 'test' };
            const statusRef = { value: 'verified' };

            listing.filterRefs({
                search: searchRef,
                status: statusRef,
            });

            expect(listing.isFilterVisible('search')).toBe(true);
            expect(listing.isFilterVisible('status')).toBe(true);
            expect(listing.visibleFilters).toEqual(expect.arrayContaining(['search', 'status']));
        });

        it('can hide and show individual filters', () => {
            const listing = new Listing<TestItem>();

            listing.filterRefs({
                search: { value: 'test' },
                status: { value: 'verified' },
            });

            listing.hideFilter('search');
            expect(listing.isFilterVisible('search')).toBe(false);
            expect(listing.isFilterVisible('status')).toBe(true);
            expect(listing.visibleFilters).toEqual(['status']);

            listing.showFilter('search');
            expect(listing.isFilterVisible('search')).toBe(true);
            expect(listing.visibleFilters).toEqual(expect.arrayContaining(['search', 'status']));
        });

        it('toggles filter visibility', () => {
            const listing = new Listing<TestItem>();

            listing.filterRefs({
                search: { value: 'test' },
            });

            // Initially visible
            expect(listing.isFilterVisible('search')).toBe(true);

            listing.toggleFilter('search');
            expect(listing.isFilterVisible('search')).toBe(false);

            listing.toggleFilter('search');
            expect(listing.isFilterVisible('search')).toBe(true);
        });

        it('treats unknown filters as visible and does not track them', () => {
            const listing = new Listing<TestItem>();

            listing.filterRefs({
                search: { value: 'test' },
            });

            expect(listing.isFilterVisible('unknown')).toBe(true);

            // Toggling an unknown filter should be a no-op
            listing.toggleFilter('unknown');
            expect(listing.isFilterVisible('unknown')).toBe(true);
        });
    });
});
