import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createRouter, createMemoryHistory } from 'vue-router';
import Users from './Users.vue';
import Oruga from '@oruga-ui/oruga-next';

interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    last_login_at: string | null;
    created_at: string;
}

interface UsersComponentInstance {
    dialogOpen: boolean;
    userToDelete: User | null;
    openDeleteDialog: (user: User) => void;
    handleDeleteCancel: () => void;
    handleDeleteConfirm: () => Promise<void>;
    deleteError: string | null;
    canRetryDelete: boolean;
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
                first: '/api/users?page=1',
                last: `/api/users?page=${lastPage}`,
                prev: currentPage > 1 ? `/api/users?page=${currentPage - 1}` : null,
                next: currentPage < lastPage ? `/api/users?page=${currentPage + 1}` : null,
            },
            filters: [],
        },
    };
}

describe('Users', () => {
    it('renders the users title', async () => {
        mockAxios.get.mockResolvedValue(createHarmonieResponse([], 1, 0));

        const router = await createTestRouter();
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        expect(wrapper.text()).toContain('Users');
    });

    it('fetches and displays users', async () => {
        const mockUsers: User[] = [
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

        mockAxios.get.mockResolvedValue(createHarmonieResponse(mockUsers, 1, 2));

        const router = await createTestRouter();
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        expect(mockAxios.get).toHaveBeenCalledWith('/api/users', {
            params: {
                page: 1,
                per_page: 15,
            },
        });

        const text = wrapper.text();
        expect(text).toContain('John Doe');
        expect(text).toContain('jane@example.com');
    });

    it('displays loading state', async () => {
        mockAxios.get.mockImplementation(() => new Promise(() => { })); // Never resolves

        const router = await createTestRouter();
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Loading users');
    });

    it('displays error message on fetch failure', async () => {
        mockAxios.get.mockRejectedValue(new Error('Network error'));

        const router = await createTestRouter();
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // Verify onLoadError handler is working - should show customized message
        expect(wrapper.text()).toContain('Failed to load users');

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.error).toBe('Failed to load users. Please try again later.');
    });

    it('uses onLoadError handler for 403 permission errors', async () => {
        const forbiddenError = new Error('Forbidden');
        (forbiddenError as { response?: { status?: number } }).response = { status: 403 };
        mockAxios.get.mockRejectedValue(forbiddenError);

        const router = await createTestRouter();
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // Verify onLoadError handler customizes 403 error message
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.error).toBe('You do not have permission to view users.');
        expect(wrapper.text()).toContain('You do not have permission to view users.');
    });

    it('formats dates with time', async () => {
        const mockUsers = [
            {
                id: 1,
                name: 'John Doe',
                email: 'john@example.com',
                email_verified_at: '2024-01-01T14:30:00Z',
                last_login_at: '2024-01-15T10:45:00Z',
                created_at: '2024-01-01T08:15:00Z',
            },
        ];

        mockAxios.get.mockResolvedValue(createHarmonieResponse(mockUsers, 1, 1));

        const router = await createTestRouter();
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        const text = wrapper.text();

        // Check that formatted dates include time indicators (AM/PM)
        // The exact format may vary by locale, but should include time
        const dateRegex = /\d{1,2}:\d{2}\s*(AM|PM)/i;
        expect(text).toMatch(dateRegex);
    });

    it('opens delete dialog when delete button is clicked', async () => {
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

        const router = await createTestRouter();
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // Verify dialog is initially closed
        const vm = wrapper.vm as unknown as UsersComponentInstance;
        expect(vm.dialogOpen).toBe(false);
        expect(vm.userToDelete).toBe(null);

        // Open delete dialog using component method
        vm.openDeleteDialog(mockUsers[0]);
        await wrapper.vm.$nextTick();

        // Check that dialog state is updated
        expect(vm.dialogOpen).toBe(true);
        expect(vm.userToDelete).toEqual(mockUsers[0]);
    });

    it('cancels deletion when cancel button is clicked', async () => {
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

        const router = await createTestRouter();
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // Open delete dialog using component method
        const vm = wrapper.vm as unknown as UsersComponentInstance;
        vm.openDeleteDialog(mockUsers[0]);
        await wrapper.vm.$nextTick();

        // Cancel deletion using component method
        vm.handleDeleteCancel();
        await wrapper.vm.$nextTick();

        // Verify delete was not called
        expect(mockAxios.delete).not.toHaveBeenCalled();
    });

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
        vm.openDeleteDialog(mockUsers[0]);
        await wrapper.vm.$nextTick();

        await vm.handleDeleteConfirm();
        await waitForListingToLoad(wrapper);

        // Verify delete was called
        expect(mockAxios.delete).toHaveBeenCalledWith('/api/users/1');

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
        vm.openDeleteDialog(mockUsers[0]);
        await wrapper.vm.$nextTick();

        await vm.handleDeleteConfirm();
        await flushPromises();
        await wrapper.vm.$nextTick();

        // Verify error state is set on the dialog
        expect(vm.dialogOpen).toBe(true);
        expect(vm.deleteError).toBe('Failed to delete user. Please try again later.');
        expect(vm.canRetryDelete).toBe(false);
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
        vm.openDeleteDialog(mockUsers[0]);
        await wrapper.vm.$nextTick();

        await vm.handleDeleteConfirm();
        await flushPromises();
        await wrapper.vm.$nextTick();

        // Verify permission error state is set on the dialog
        expect(vm.dialogOpen).toBe(true);
        expect(vm.deleteError).toBe('You do not have permission to delete users.');
        expect(vm.canRetryDelete).toBe(false);
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

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.currentPage).toBe(2);
        expect(vm.listing.filters.search).toBe('john');
        expect(vm.listing.filters.status).toBe('verified');
        expect(vm.listing.filters.date_from).toBe('2024-01-01');
        expect(vm.listing.filters.date_to).toBe('2024-12-31');

        expect(mockAxios.get).toHaveBeenCalledWith('/api/users', {
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

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        vm.listing.filters.search = 'test';
        vm.listing.filters.status = 'verified';
        vm.listing.filters.date_from = '2024-01-01';
        vm.listing.filters.date_to = '2024-12-31';

        await vm.listing.applyFilters();
        await flushPromises();
        await wrapper.vm.$nextTick();

        expect(router.currentRoute.value.query).toEqual({
            search: 'test',
            status: 'verified',
            date_from: '2024-01-01',
            date_to: '2024-12-31',
        });
    });

    it('updates URL when changing page', async () => {
        mockAxios.get.mockResolvedValue(createHarmonieResponse([], 2, 30));

        const router = await createTestRouter();
        const wrapper = mount(Users, {
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

        const router = await createTestRouter('/users?page=2&search=test&status=verified');
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        await vm.listing.resetFilters();
        await flushPromises();
        await wrapper.vm.$nextTick();

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

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // Verify filters are initially set from URL
        expect(vm.listing.filters.search).toBe('test');
        expect(vm.listing.filters.status).toBe('verified');
        expect(vm.listing.filters.date_from).toBe('2024-01-01');
        expect(vm.listing.filters.date_to).toBe('2024-12-31');

        // Reset filters
        await vm.listing.resetFilters();
        await flushPromises();
        await wrapper.vm.$nextTick();

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

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
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

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
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

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
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

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
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

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.currentPage).toBe(2);

        // Reset filters
        await vm.listing.resetFilters();
        await waitForListingToLoad(wrapper);

        // Verify pagination is reset to page 1
        expect(vm.currentPage).toBe(1);
        // When page is 1, Listing class doesn't include it in URL query (only includes page if > 1)
        expect(router.currentRoute.value.query.page).toBeUndefined();
    });

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

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
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

