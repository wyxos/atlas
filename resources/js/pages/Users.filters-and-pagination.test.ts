/* eslint-disable @typescript-eslint/no-unused-vars */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createRouter, createMemoryHistory } from 'vue-router';
import Users from './Users.vue';
import Oruga from '@oruga-ui/oruga-next';
import { index as usersIndex, destroy as usersDestroy } from '@/actions/App/Http/Controllers/UsersController';

interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    last_login_at: string | null;
    created_at: string;
}

interface UsersComponentInstance {
    deletionHandler: {
        dialogOpen: boolean;
        itemToDelete: User | null;
        openDialog: (user: User) => void;
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

    // Mock the user-id meta tag (default to user ID 999 so it doesn't match test users)
    const metaTag = document.querySelector('meta[name="user-id"]');
    if (metaTag) {
        metaTag.setAttribute('content', '999');
    } else {
        const meta = document.createElement('meta');
        meta.setAttribute('name', 'user-id');
        meta.setAttribute('content', '999');
        document.head.appendChild(meta);
    }
});

async function createTestRouter(initialPath = '/users') {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/users', component: Users },
            { path: '/dashboard', component: { template: '<div>Dashboard</div>' } },
            { path: '/profile', component: { template: '<div>Profile</div>' } },
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

function createHarmonieResponse(items: User[], currentPage = 1, total?: number, perPage = 15) {
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
                first: `${usersIndex.definition.url}?page=1`,
                last: `${usersIndex.definition.url}?page=${lastPage}`,
                prev: currentPage > 1 ? `${usersIndex.definition.url}?page=${currentPage - 1}` : null,
                next: currentPage < lastPage ? `${usersIndex.definition.url}?page=${currentPage + 1}` : null,
            },
            filters: [],
        },
    };
}

describe('Users', () => {
    it('updates URL when changing page', async () => {
        mockAxios.get.mockResolvedValue(createHarmonieResponse([], 2, 30));

        const router = await createTestRouter();
        const wrapper = mount(Users, {
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

        const router = await createTestRouter('/users?page=2&search=test&status=verified');
        const wrapper = mount(Users, {
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

        const router = await createTestRouter('/users?search=test&status=verified&date_from=2024-01-01&date_to=2024-12-31');
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

         
        const vm = wrapper.vm as any;

        // Verify filters are initially set from URL
        expect(vm.listing.filters.search).toBe('test');
        expect(vm.listing.filters.status).toBe('verified');
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
        expect(vm.listing.filters.status).toBe('all');
        expect(vm.listing.filters.date_from).toBe('');
        expect(vm.listing.filters.date_to).toBe('');
    });
    it('removes individual search filter when removeFilter is called', async () => {
        mockAxios.get.mockResolvedValue(createHarmonieResponse([], 1, 0));

        const router = await createTestRouter('/users?search=test&status=verified');
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

         
        const vm = wrapper.vm as any;
        expect(vm.listing.filters.search).toBe('test');
        expect(vm.listing.filters.status).toBe('verified');

        // Remove search filter
        await vm.listing.removeFilter('search');
        await flushPromises();
        await wrapper.vm.$nextTick();

        // Verify search is cleared but status remains
        expect(vm.listing.filters.search).toBe('');
        expect(vm.listing.filters.status).toBe('verified');

        // Verify URL is updated - search should be removed, status should remain
        expect(router.currentRoute.value.query.search).toBeUndefined();
        expect(router.currentRoute.value.query.status).toBe('verified');
    });
    it('removes individual date_from filter when removeFilter is called', async () => {
        mockAxios.get.mockResolvedValue(createHarmonieResponse([], 1, 0));

        const router = await createTestRouter('/users?date_from=2024-01-01&date_to=2024-12-31');
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

         
        const vm = wrapper.vm as any;
        expect(vm.listing.filters.date_from).toBe('2024-01-01');
        expect(vm.listing.filters.date_to).toBe('2024-12-31');

        // Remove date_from filter
        await vm.listing.removeFilter('date_from');
        await flushPromises();
        await wrapper.vm.$nextTick();

        // Verify date_from is cleared but date_to remains
        expect(vm.listing.filters.date_from).toBe('');
        expect(vm.listing.filters.date_to).toBe('2024-12-31');

        // Verify URL is updated - date_from should be removed, date_to should remain
        expect(router.currentRoute.value.query.date_from).toBeUndefined();
        expect(router.currentRoute.value.query.date_to).toBe('2024-12-31');
    });
    it('removes individual date_to filter when removeFilter is called', async () => {
        mockAxios.get.mockResolvedValue(createHarmonieResponse([], 1, 0));

        const router = await createTestRouter('/users?date_from=2024-01-01&date_to=2024-12-31');
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

         
        const vm = wrapper.vm as any;
        expect(vm.listing.filters.date_from).toBe('2024-01-01');
        expect(vm.listing.filters.date_to).toBe('2024-12-31');

        // Remove date_to filter
        await vm.listing.removeFilter('date_to');
        await flushPromises();
        await wrapper.vm.$nextTick();

        // Verify date_to is cleared but date_from remains
        expect(vm.listing.filters.date_from).toBe('2024-01-01');
        expect(vm.listing.filters.date_to).toBe('');

        // Verify URL is updated - date_to should be removed, date_from should remain
        expect(router.currentRoute.value.query.date_from).toBe('2024-01-01');
        expect(router.currentRoute.value.query.date_to).toBeUndefined();
    });
    it('removes individual status filter when removeFilter is called', async () => {
        mockAxios.get.mockResolvedValue(createHarmonieResponse([], 1, 0));

        const router = await createTestRouter('/users?search=test&status=verified');
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

         
        const vm = wrapper.vm as any;
        expect(vm.listing.filters.search).toBe('test');
        expect(vm.listing.filters.status).toBe('verified');

        // Remove status filter
        await vm.listing.removeFilter('status');
        await flushPromises();
        await wrapper.vm.$nextTick();

        // Verify status is reset to 'all' but search remains
        expect(vm.listing.filters.search).toBe('test');
        expect(vm.listing.filters.status).toBe('all');

        // Verify URL is updated - status should be removed (not included when 'all'), search should remain
        expect(router.currentRoute.value.query.search).toBe('test');
        expect(router.currentRoute.value.query.status).toBeUndefined();
    });
    it('resets pagination to page 1 when resetFilters is called', async () => {
        mockAxios.get
            .mockResolvedValueOnce(createHarmonieResponse([], 2, 30))
            .mockResolvedValueOnce(createHarmonieResponse([], 1, 30));

        const router = await createTestRouter('/users?page=2&search=test');
        const wrapper = mount(Users, {
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
        // Verify pagination is reset to page 1
        expect(vm.currentPage).toBe(1);
        // When page is 1, Listing class doesn't include it in URL query (only includes page if > 1)
        expect(router.currentRoute.value.query.page).toBeUndefined();
    });
});

