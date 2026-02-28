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
    it('resets pagination to page 1 when removeFilter is called', async () => {
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

        // Remove a filter
        await vm.listing.removeFilter('search');
        await waitForListingToLoad(wrapper);

        // Verify pagination is reset to page 1
        expect(vm.currentPage).toBe(1);
        // When page is 1, Listing class doesn't include it in URL query (only includes page if > 1)
        expect(router.currentRoute.value.query.page).toBeUndefined();
    });
});

