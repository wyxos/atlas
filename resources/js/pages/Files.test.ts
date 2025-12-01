import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createRouter, createMemoryHistory } from 'vue-router';
import Files from './Files.vue';
import Oruga from '@oruga-ui/oruga-next';

interface File {
    id: number;
    source: string;
    filename: string;
    ext: string | null;
    size: number | null;
    mime_type: string | null;
    title: string | null;
    url: string | null;
    path: string | null;
    thumbnail_url: string | null;
    downloaded: boolean;
    not_found: boolean;
    created_at: string;
    updated_at: string;
}

interface FilesComponentInstance {
    deletionHandler: {
        dialogOpen: boolean;
        itemToDelete: File | null;
        openDialog: (file: File) => void;
        closeDialog: () => void;
        delete: () => Promise<void>;
        deleteError: string | null;
        canRetryDelete: boolean;
        isDeleting: boolean;
    };
}

// Mock axios
vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
    },
}));

// Mock vue-sonner
vi.mock('vue-sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
        info: vi.fn(),
        warning: vi.fn(),
    },
    Toaster: {
        name: 'Toaster',
        template: '<div></div>',
    },
}));

// Mock toast from local sonner implementation
vi.mock('../components/ui/sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
        info: vi.fn(),
        warning: vi.fn(),
    },
    Toaster: {
        name: 'Toaster',
        template: '<div></div>',
    },
}));

// Mock window.axios
const mockAxios = {
    get: vi.fn(),
    delete: vi.fn(),
};

beforeEach(() => {
    vi.clearAllMocks();
    // Suppress console.error output during tests to reduce noise
    vi.spyOn(console, 'error').mockImplementation(() => { });
    Object.assign(global.window, {
        axios: mockAxios,
    });
});

async function createTestRouter(initialPath = '/files') {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/files', component: Files },
            { path: '/dashboard', component: { template: '<div>Dashboard</div>' } },
        ],
    });
    await router.push(initialPath);
    await router.isReady();
    return router;
}

/**
 * Wait for the listing to finish loading by flushing promises and waiting for Vue updates.
 * Since axios is mocked, promises resolve immediately, so we just need to ensure
 * all promise chains complete and Vue has updated the DOM.
 */
async function waitForListingToLoad(wrapper: ReturnType<typeof mount>): Promise<void> {
    // Flush all pending promises multiple times to ensure promise chains complete
    await flushPromises();
    await wrapper.vm.$nextTick();
    await flushPromises();
    await wrapper.vm.$nextTick();
    // One more flush to catch any nested async operations
    await flushPromises();
    await wrapper.vm.$nextTick();
}

function createHarmonieResponse(items: File[], currentPage = 1, total?: number, perPage = 15) {
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
                first: '/api/files?page=1',
                last: `/api/files?page=${lastPage}`,
                prev: currentPage > 1 ? `/api/files?page=${currentPage - 1}` : null,
                next: currentPage < lastPage ? `/api/files?page=${currentPage + 1}` : null,
            },
            filters: [],
        },
    };
}

describe('Files', () => {
    it('renders the files title', async () => {
        mockAxios.get.mockResolvedValue(createHarmonieResponse([], 1, 0));

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        expect(wrapper.text()).toContain('Files');
    });

    it('fetches and displays files', async () => {
        const mockFiles: File[] = [
            {
                id: 1,
                source: 'local',
                filename: 'test-image.jpg',
                ext: 'jpg',
                size: 1024000,
                mime_type: 'image/jpeg',
                title: 'Test Image',
                url: null,
                path: '/path/to/test-image.jpg',
                thumbnail_url: null,
                downloaded: true,
                not_found: false,
                created_at: '2024-01-01T00:00:00Z',
                updated_at: '2024-01-01T00:00:00Z',
            },
            {
                id: 2,
                source: 'YouTube',
                filename: 'video.mp4',
                ext: 'mp4',
                size: 10485760,
                mime_type: 'video/mp4',
                title: 'Test Video',
                url: 'https://youtube.com/watch?v=123',
                path: null,
                thumbnail_url: 'https://example.com/thumb.jpg',
                downloaded: false,
                not_found: false,
                created_at: '2024-01-02T00:00:00Z',
                updated_at: '2024-01-02T00:00:00Z',
            },
        ];

        mockAxios.get.mockResolvedValue(createHarmonieResponse(mockFiles, 1, 2));

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        expect(mockAxios.get).toHaveBeenCalledWith('/api/files', {
            params: {
                page: 1,
                per_page: 15,
            },
        });

        const text = wrapper.text();
        expect(text).toContain('test-image.jpg');
        expect(text).toContain('video.mp4');
    });

    it('displays loading state', async () => {
        mockAxios.get.mockImplementation(() => new Promise(() => { })); // Never resolves

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Loading...');
    });

    it('displays error message on fetch failure', async () => {
        mockAxios.get.mockRejectedValue(new Error('Network error'));

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // Verify onLoadError handler is working - should show customized message
        expect(wrapper.text()).toContain('Failed to load files');

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.error).toBe('Failed to load files. Please try again later.');
    });

    it('uses onLoadError handler for 403 permission errors', async () => {
        const forbiddenError = new Error('Forbidden');
        (forbiddenError as { response?: { status?: number } }).response = { status: 403 };
        mockAxios.get.mockRejectedValue(forbiddenError);

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // Verify onLoadError handler customizes 403 error message
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.error).toBe('You do not have permission to view files.');
        expect(wrapper.text()).toContain('You do not have permission to view files.');
    });

    it('formats dates with time', async () => {
        const mockFiles = [
            {
                id: 1,
                source: 'local',
                filename: 'test.jpg',
                ext: 'jpg',
                size: 1024,
                mime_type: 'image/jpeg',
                title: null,
                url: null,
                path: '/path/to/test.jpg',
                thumbnail_url: null,
                downloaded: false,
                not_found: false,
                created_at: '2024-01-01T14:30:00Z',
                updated_at: '2024-01-01T14:30:00Z',
            },
        ];

        mockAxios.get.mockResolvedValue(createHarmonieResponse(mockFiles, 1, 1));

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        const text = wrapper.text();

        // Check that formatted dates include time in 24-hour format
        const dateRegex = /\d{2}:\d{2}/;
        expect(text).toMatch(dateRegex);
    });

    it('formats file sizes correctly', async () => {
        const mockFiles = [
            {
                id: 1,
                source: 'local',
                filename: 'small.jpg',
                ext: 'jpg',
                size: 1024, // 1 KB
                mime_type: 'image/jpeg',
                title: null,
                url: null,
                path: '/path/to/small.jpg',
                thumbnail_url: null,
                downloaded: false,
                not_found: false,
                created_at: '2024-01-01T00:00:00Z',
                updated_at: '2024-01-01T00:00:00Z',
            },
            {
                id: 2,
                source: 'local',
                filename: 'medium.jpg',
                ext: 'jpg',
                size: 1048576, // 1 MB
                mime_type: 'image/jpeg',
                title: null,
                url: null,
                path: '/path/to/medium.jpg',
                thumbnail_url: null,
                downloaded: false,
                not_found: false,
                created_at: '2024-01-01T00:00:00Z',
                updated_at: '2024-01-01T00:00:00Z',
            },
            {
                id: 3,
                source: 'local',
                filename: 'large.jpg',
                ext: 'jpg',
                size: 1073741824, // 1 GB
                mime_type: 'image/jpeg',
                title: null,
                url: null,
                path: '/path/to/large.jpg',
                thumbnail_url: null,
                downloaded: false,
                not_found: false,
                created_at: '2024-01-01T00:00:00Z',
                updated_at: '2024-01-01T00:00:00Z',
            },
        ];

        mockAxios.get.mockResolvedValue(createHarmonieResponse(mockFiles, 1, 3));

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        const text = wrapper.text();
        expect(text).toContain('KB');
        expect(text).toContain('MB');
        expect(text).toContain('GB');
    });

    it('opens delete dialog when delete button is clicked', async () => {
        const mockFiles = [
            {
                id: 1,
                source: 'local',
                filename: 'test.jpg',
                ext: 'jpg',
                size: 1024,
                mime_type: 'image/jpeg',
                title: null,
                url: null,
                path: '/path/to/test.jpg',
                thumbnail_url: null,
                downloaded: false,
                not_found: false,
                created_at: '2024-01-01T00:00:00Z',
                updated_at: '2024-01-01T00:00:00Z',
            },
        ];

        mockAxios.get.mockResolvedValue(createHarmonieResponse(mockFiles, 1, 1));

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // Verify dialog is initially closed
        const vm = wrapper.vm as unknown as FilesComponentInstance;
        expect(vm.deletionHandler.dialogOpen).toBe(false);
        expect(vm.deletionHandler.itemToDelete).toBe(null);

        // Open delete dialog using component method
        vm.deletionHandler.openDialog(mockFiles[0]);
        await wrapper.vm.$nextTick();

        // Check that dialog state is updated
        expect(vm.deletionHandler.dialogOpen).toBe(true);
        expect(vm.deletionHandler.itemToDelete).toEqual(mockFiles[0]);
    });

    it('cancels deletion when cancel button is clicked', async () => {
        const mockFiles = [
            {
                id: 1,
                source: 'local',
                filename: 'test.jpg',
                ext: 'jpg',
                size: 1024,
                mime_type: 'image/jpeg',
                title: null,
                url: null,
                path: '/path/to/test.jpg',
                thumbnail_url: null,
                downloaded: false,
                not_found: false,
                created_at: '2024-01-01T00:00:00Z',
                updated_at: '2024-01-01T00:00:00Z',
            },
        ];

        mockAxios.get.mockResolvedValue(createHarmonieResponse(mockFiles, 1, 1));

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // Open delete dialog using component method
        const vm = wrapper.vm as unknown as FilesComponentInstance;
        vm.deletionHandler.openDialog(mockFiles[0]);
        await wrapper.vm.$nextTick();

        // Cancel deletion using component method
        vm.deletionHandler.closeDialog();
        await wrapper.vm.$nextTick();

        // Verify delete was not called
        expect(mockAxios.delete).not.toHaveBeenCalled();
    });

    it('successfully deletes a file', async () => {
        const mockFiles = [
            {
                id: 1,
                source: 'local',
                filename: 'test1.jpg',
                ext: 'jpg',
                size: 1024,
                mime_type: 'image/jpeg',
                title: null,
                url: null,
                path: '/path/to/test1.jpg',
                thumbnail_url: null,
                downloaded: false,
                not_found: false,
                created_at: '2024-01-01T00:00:00Z',
                updated_at: '2024-01-01T00:00:00Z',
            },
            {
                id: 2,
                source: 'local',
                filename: 'test2.jpg',
                ext: 'jpg',
                size: 2048,
                mime_type: 'image/jpeg',
                title: null,
                url: null,
                path: '/path/to/test2.jpg',
                thumbnail_url: null,
                downloaded: false,
                not_found: false,
                created_at: '2024-01-02T00:00:00Z',
                updated_at: '2024-01-02T00:00:00Z',
            },
        ];

        const remainingFiles = [mockFiles[1]];

        mockAxios.get
            .mockResolvedValueOnce(createHarmonieResponse(mockFiles, 1, 2))
            .mockResolvedValueOnce(createHarmonieResponse(remainingFiles, 1, 1));

        mockAxios.delete.mockResolvedValue({});

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // Open delete dialog and confirm deletion using component methods
        const vm = wrapper.vm as unknown as FilesComponentInstance;
        vm.deletionHandler.openDialog(mockFiles[0]);
        await wrapper.vm.$nextTick();

        await vm.deletionHandler.delete();
        await waitForListingToLoad(wrapper);

        // Verify delete was called
        expect(mockAxios.delete).toHaveBeenCalledWith('/api/files/1');

        // Verify files list was refreshed
        expect(mockAxios.get).toHaveBeenCalledTimes(2);
    });

    it('displays error message when deletion fails', async () => {
        const mockFiles = [
            {
                id: 1,
                source: 'local',
                filename: 'test.jpg',
                ext: 'jpg',
                size: 1024,
                mime_type: 'image/jpeg',
                title: null,
                url: null,
                path: '/path/to/test.jpg',
                thumbnail_url: null,
                downloaded: false,
                not_found: false,
                created_at: '2024-01-01T00:00:00Z',
                updated_at: '2024-01-01T00:00:00Z',
            },
        ];

        mockAxios.get.mockResolvedValue(createHarmonieResponse(mockFiles, 1, 1));

        mockAxios.delete.mockRejectedValue(new Error('Network error'));

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // Open delete dialog and confirm deletion using component methods
        const vm = wrapper.vm as unknown as FilesComponentInstance;
        vm.deletionHandler.openDialog(mockFiles[0]);
        await wrapper.vm.$nextTick();

        await vm.deletionHandler.delete();
        await flushPromises();
        await wrapper.vm.$nextTick();

        // Verify error state is set on the dialog
        expect(vm.deletionHandler.dialogOpen).toBe(true);
        expect(vm.deletionHandler.deleteError).toBe('Failed to delete file. Please try again later.');
        expect(vm.deletionHandler.canRetryDelete).toBe(false);
    });

    it('displays permission error when deletion is forbidden', async () => {
        const mockFiles = [
            {
                id: 1,
                source: 'local',
                filename: 'test.jpg',
                ext: 'jpg',
                size: 1024,
                mime_type: 'image/jpeg',
                title: null,
                url: null,
                path: '/path/to/test.jpg',
                thumbnail_url: null,
                downloaded: false,
                not_found: false,
                created_at: '2024-01-01T00:00:00Z',
                updated_at: '2024-01-01T00:00:00Z',
            },
        ];

        mockAxios.get.mockResolvedValue(createHarmonieResponse(mockFiles, 1, 1));

        const forbiddenError = new Error('Forbidden');
        (forbiddenError as { response?: { status?: number } }).response = { status: 403 };
        mockAxios.delete.mockRejectedValue(forbiddenError);

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // Open delete dialog and confirm deletion using component methods
        const vm = wrapper.vm as unknown as FilesComponentInstance;
        vm.deletionHandler.openDialog(mockFiles[0]);
        await wrapper.vm.$nextTick();

        await vm.deletionHandler.delete();
        await flushPromises();
        await wrapper.vm.$nextTick();

        // Verify permission error state is set on the dialog
        expect(vm.deletionHandler.dialogOpen).toBe(true);
        expect(vm.deletionHandler.deleteError).toBe('You do not have permission to delete files.');
        expect(vm.deletionHandler.canRetryDelete).toBe(false);
    });

    it('loads filters from URL query parameters', async () => {
        mockAxios.get.mockResolvedValue(createHarmonieResponse([], 2, 0));

        const router = await createTestRouter('/files?page=2&search=test&source=local&mime_type=image&downloaded=yes&date_from=2024-01-01&date_to=2024-12-31');
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.currentPage).toBe(2);
        expect(vm.listing.filters.search).toBe('test');
        expect(vm.listing.filters.source).toBe('local');
        expect(vm.listing.filters.mime_type).toBe('image');
        expect(vm.listing.filters.downloaded).toBe('yes');
        expect(vm.listing.filters.date_from).toBe('2024-01-01');
        expect(vm.listing.filters.date_to).toBe('2024-12-31');

        expect(mockAxios.get).toHaveBeenCalledWith('/api/files', {
            params: expect.objectContaining({
                page: 2,
                search: 'test',
                source: 'local',
                mime_type: 'image',
                downloaded: 'yes',
                date_from: '2024-01-01',
                date_to: '2024-12-31',
            }),
        });
    });

    it('updates URL when applying filters', async () => {
        mockAxios.get.mockResolvedValue(createHarmonieResponse([], 1, 0));

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        vm.listing.filters.search = 'test';
        vm.listing.filters.source = 'YouTube';
        vm.listing.filters.mime_type = 'video';
        vm.listing.filters.downloaded = 'yes';
        vm.listing.filters.date_from = '2024-01-01';
        vm.listing.filters.date_to = '2024-12-31';

        expect(vm.listing.isFiltering).toBe(false);
        const applyPromise = vm.listing.applyFilters();
        expect(vm.listing.isFiltering).toBe(true);
        await applyPromise;
        await flushPromises();
        await wrapper.vm.$nextTick();

        expect(vm.listing.isFiltering).toBe(false);
        expect(router.currentRoute.value.query).toEqual({
            search: 'test',
            source: 'YouTube',
            mime_type: 'video',
            downloaded: 'yes',
            date_from: '2024-01-01',
            date_to: '2024-12-31',
        });
    });

    it('updates URL when changing page', async () => {
        mockAxios.get.mockResolvedValue(createHarmonieResponse([], 2, 30));

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        await vm.listing.goToPage(2);
        await flushPromises();
        await wrapper.vm.$nextTick();

        expect(router.currentRoute.value.query.page).toBe('2');
    });

    it('clears URL query parameters when resetting filters', async () => {
        mockAxios.get.mockResolvedValue(createHarmonieResponse([], 1, 0));

        const router = await createTestRouter('/files?page=2&search=test&source=local');
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.listing.isResetting).toBe(false);
        const resetPromise = vm.listing.resetFilters();
        expect(vm.listing.isResetting).toBe(true);
        await resetPromise;
        await flushPromises();
        await wrapper.vm.$nextTick();

        expect(vm.listing.isResetting).toBe(false);
        expect(router.currentRoute.value.query).toEqual({});
    });

    it('resets all filter values to defaults when resetFilters is called', async () => {
        mockAxios.get.mockResolvedValue(createHarmonieResponse([], 1, 0));

        const router = await createTestRouter('/files?search=test&source=local&mime_type=image&downloaded=yes&date_from=2024-01-01&date_to=2024-12-31');
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // Verify filters are initially set from URL
        expect(vm.listing.filters.search).toBe('test');
        expect(vm.listing.filters.source).toBe('local');
        expect(vm.listing.filters.mime_type).toBe('image');
        expect(vm.listing.filters.downloaded).toBe('yes');
        expect(vm.listing.filters.date_from).toBe('2024-01-01');
        expect(vm.listing.filters.date_to).toBe('2024-12-31');

        // Reset filters
        expect(vm.listing.isResetting).toBe(false);
        const resetPromise = vm.listing.resetFilters();
        expect(vm.listing.isResetting).toBe(true);
        await resetPromise;
        await flushPromises();
        await wrapper.vm.$nextTick();

        expect(vm.listing.isResetting).toBe(false);
        // Verify all filter values are reset to defaults
        expect(vm.listing.filters.search).toBe('');
        expect(vm.listing.filters.source).toBe('all');
        expect(vm.listing.filters.mime_type).toBe('all');
        expect(vm.listing.filters.downloaded).toBe('all');
        expect(vm.listing.filters.date_from).toBe('');
        expect(vm.listing.filters.date_to).toBe('');
    });

    it('removes individual search filter when removeFilter is called', async () => {
        mockAxios.get.mockResolvedValue(createHarmonieResponse([], 1, 0));

        const router = await createTestRouter('/files?search=test&source=local');
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.listing.filters.search).toBe('test');
        expect(vm.listing.filters.source).toBe('local');

        // Remove search filter
        await vm.listing.removeFilter('search');
        await flushPromises();
        await wrapper.vm.$nextTick();

        // Verify search is cleared but source remains
        expect(vm.listing.filters.search).toBe('');
        expect(vm.listing.filters.source).toBe('local');

        // Verify URL is updated - search should be removed, source should remain
        expect(router.currentRoute.value.query.search).toBeUndefined();
        expect(router.currentRoute.value.query.source).toBe('local');
    });

    it('resets pagination to page 1 when resetFilters is called', async () => {
        mockAxios.get
            .mockResolvedValueOnce(createHarmonieResponse([], 2, 30))
            .mockResolvedValueOnce(createHarmonieResponse([], 1, 30));

        const router = await createTestRouter('/files?page=2&search=test');
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.currentPage).toBe(2);

        // Reset filters
        expect(vm.listing.isResetting).toBe(false);
        const resetPromise = vm.listing.resetFilters();
        expect(vm.listing.isResetting).toBe(true);
        await resetPromise;
        await waitForListingToLoad(wrapper);

        expect(vm.listing.isResetting).toBe(false);
        // Verify pagination was reset to page 1
        expect(vm.currentPage).toBe(1);
    });
});

