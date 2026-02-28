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
    it('successfully deletes a user', async () => {
        const mockUsers = [
            {
                id: 1,
                name: 'John Doe',
                email: 'john@example.com',
                email_verified_at: '2024-01-01T00:00:00Z',
                last_login_at: null,
                created_at: '2024-01-01T00:00:00Z',
            },
            {
                id: 2,
                name: 'Jane Smith',
                email: 'jane@example.com',
                email_verified_at: null,
                last_login_at: null,
                created_at: '2024-01-02T00:00:00Z',
            },
        ];

        const remainingUsers = [mockUsers[1]];

        mockAxios.get
            .mockResolvedValueOnce(createHarmonieResponse(mockUsers, 1, 2))
            .mockResolvedValueOnce(createHarmonieResponse(remainingUsers, 1, 1));

        mockAxios.delete.mockResolvedValue({});

        const router = await createTestRouter();
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // Open delete dialog and confirm deletion using component methods
        const vm = wrapper.vm as unknown as UsersComponentInstance;
        vm.deletionHandler.openDialog(mockUsers[0]);
        await wrapper.vm.$nextTick();

        await vm.deletionHandler.delete();
        await waitForListingToLoad(wrapper);

        // Verify delete was called
        expect(mockAxios.delete).toHaveBeenCalledWith(usersDestroy.url({ user: 1 }));

        // Verify users list was refreshed
        expect(mockAxios.get).toHaveBeenCalledTimes(2);
    });
    it('displays error message when deletion fails', async () => {
        const mockUsers = [
            {
                id: 1,
                name: 'John Doe',
                email: 'john@example.com',
                email_verified_at: '2024-01-01T00:00:00Z',
                last_login_at: null,
                created_at: '2024-01-01T00:00:00Z',
            },
        ];

        mockAxios.get.mockResolvedValue(createHarmonieResponse(mockUsers, 1, 1));

        mockAxios.delete.mockRejectedValue(new Error('Network error'));

        const router = await createTestRouter();
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // Open delete dialog and confirm deletion using component methods
        const vm = wrapper.vm as unknown as UsersComponentInstance;
        vm.deletionHandler.openDialog(mockUsers[0]);
        await wrapper.vm.$nextTick();

        await vm.deletionHandler.delete();
        await flushPromises();
        await wrapper.vm.$nextTick();

        // Verify error state is set on the dialog
        expect(vm.deletionHandler.dialogOpen).toBe(true);
        expect(vm.deletionHandler.deleteError).toBe('Failed to delete user. Please try again later.');
        expect(vm.deletionHandler.canRetryDelete).toBe(false);
    });
    it('displays permission error when deletion is forbidden', async () => {
        const mockUsers = [
            {
                id: 1,
                name: 'John Doe',
                email: 'john@example.com',
                email_verified_at: '2024-01-01T00:00:00Z',
                last_login_at: null,
                created_at: '2024-01-01T00:00:00Z',
            },
        ];

        mockAxios.get.mockResolvedValue(createHarmonieResponse(mockUsers, 1, 1));

        const forbiddenError = new Error('Forbidden');
        (forbiddenError as { response?: { status?: number } }).response = { status: 403 };
        mockAxios.delete.mockRejectedValue(forbiddenError);

        const router = await createTestRouter();
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // Open delete dialog and confirm deletion using component methods
        const vm = wrapper.vm as unknown as UsersComponentInstance;
        vm.deletionHandler.openDialog(mockUsers[0]);
        await wrapper.vm.$nextTick();

        await vm.deletionHandler.delete();
        await flushPromises();
        await wrapper.vm.$nextTick();

        // Verify permission error state is set on the dialog
        expect(vm.deletionHandler.dialogOpen).toBe(true);
        expect(vm.deletionHandler.deleteError).toBe('You do not have permission to delete users.');
        expect(vm.deletionHandler.canRetryDelete).toBe(false);
    });
    it('hides delete button for current user', async () => {
        // Set the current user ID to match one of the mock users
        const metaTag = document.querySelector('meta[name="user-id"]');
        metaTag?.setAttribute('content', '1');

        const mockUsers = [
            {
                id: 1, // Current user
                name: 'Current User',
                email: 'current@example.com',
                email_verified_at: '2024-01-01T00:00:00Z',
                last_login_at: null,
                created_at: '2024-01-01T00:00:00Z',
            },
            {
                id: 2,
                name: 'Other User',
                email: 'other@example.com',
                email_verified_at: null,
                last_login_at: null,
                created_at: '2024-01-02T00:00:00Z',
            },
        ];

        mockAxios.get.mockResolvedValue(createHarmonieResponse(mockUsers, 1, 2));

        const router = await createTestRouter();
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // Find all delete buttons - should only have one (for the other user)
        const deleteButtons = wrapper.findAll('button').filter(btn =>
            btn.html().includes('Trash2') || btn.html().includes('trash')
        );

        // There should be only 1 delete button (for user id=2, not for current user id=1)
        expect(deleteButtons.length).toBe(1);

        // Reset meta tag for other tests
        metaTag?.setAttribute('content', '999');
    });
    it('loads filters from URL query parameters', async () => {
        mockAxios.get.mockResolvedValue(createHarmonieResponse([], 2, 0));

        const router = await createTestRouter('/users?page=2&search=john&status=verified&date_from=2024-01-01&date_to=2024-12-31');
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

         
        const vm = wrapper.vm as any;
        expect(vm.currentPage).toBe(2);
        expect(vm.listing.filters.search).toBe('john');
        expect(vm.listing.filters.status).toBe('verified');
        expect(vm.listing.filters.date_from).toBe('2024-01-01');
        expect(vm.listing.filters.date_to).toBe('2024-12-31');

        expect(mockAxios.get).toHaveBeenCalledWith(usersIndex.definition.url, {
            params: expect.objectContaining({
                page: 2,
                search: 'john',
                status: 'verified',
                date_from: '2024-01-01',
                date_to: '2024-12-31',
            }),
        });
    });
    it('updates URL when applying filters', async () => {
        mockAxios.get.mockResolvedValue(createHarmonieResponse([], 1, 0));

        const router = await createTestRouter();
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

         
        const vm = wrapper.vm as any;
        vm.listing.filters.search = 'test';
        vm.listing.filters.status = 'verified';
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
            status: 'verified',
            date_from: '2024-01-01',
            date_to: '2024-12-31',
        });
    });
});
