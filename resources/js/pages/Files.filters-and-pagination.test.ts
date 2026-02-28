import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createRouter, createMemoryHistory } from 'vue-router';
import Files from './Files.vue';
import Oruga from '@oruga-ui/oruga-next';
import { index as filesIndex, destroy as filesDestroy } from '@/actions/App/Http/Controllers/FilesController';

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
    preview_url: string | null;
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

const filesIndexUrl = filesIndex.definition?.url ?? filesIndex.url();

// Mock axios
vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
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
                first: `${filesIndexUrl}?page=1`,
                last: `${filesIndexUrl}?page=${lastPage}`,
                prev: currentPage > 1 ? `${filesIndexUrl}?page=${currentPage - 1}` : null,
                next: currentPage < lastPage ? `${filesIndexUrl}?page=${currentPage + 1}` : null,
            },
            filters: [],
        },
    };
}

describe('Files', () => {
    it('loads filters from URL query parameters', async () => {
        mockAxios.get.mockResolvedValue(createHarmonieResponse([], 2, 0));

        const router = await createTestRouter('/files?page=2&search=test&source=local&mime_type=image&downloaded=yes&date_from=2024-01-01&date_to=2024-12-31');
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

         
        const vm = wrapper.vm as any;
        expect(vm.currentPage).toBe(2);
        expect(vm.listing.filters.search).toBe('test');
        expect(vm.listing.filters.source).toBe('local');
        expect(vm.listing.filters.mime_type).toBe('image');
        expect(vm.listing.filters.downloaded).toBe('yes');
        expect(vm.listing.filters.date_from).toBe('2024-01-01');
        expect(vm.listing.filters.date_to).toBe('2024-12-31');

        expect(mockAxios.get).toHaveBeenCalledWith(filesIndexUrl, {
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
