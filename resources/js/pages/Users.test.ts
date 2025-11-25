import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
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
    Object.assign(global.window, {
        axios: mockAxios,
    });
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
    return router;
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

        await wrapper.vm.$nextTick();
        // Wait for the async fetchUsers to complete
        await new Promise((resolve) => setTimeout(resolve, 200));

        expect(mockAxios.get).toHaveBeenCalledWith('/api/users', {
            params: {
                page: 1,
                per_page: 15,
            },
        });

        // Wait for DOM updates after data is loaded
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 100));

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

        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 100));

        expect(wrapper.text()).toContain('Failed to load users');
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

        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 200));
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 100));

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

        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 200));
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 100));

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

        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 200));
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 100));

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

        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 200));
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 100));

        // Open delete dialog and confirm deletion using component methods
        const vm = wrapper.vm as unknown as UsersComponentInstance;
        vm.openDeleteDialog(mockUsers[0]);
        await wrapper.vm.$nextTick();

        await vm.handleDeleteConfirm();
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 200));
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 100));

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

        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 200));
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 100));

        // Open delete dialog and confirm deletion using component methods
        const vm = wrapper.vm as unknown as UsersComponentInstance;
        vm.openDeleteDialog(mockUsers[0]);
        await wrapper.vm.$nextTick();

        await vm.handleDeleteConfirm();
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 200));
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 100));

        // Verify error message is displayed
        expect(wrapper.text()).toContain('Failed to delete user');
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

        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 200));
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 100));

        // Open delete dialog and confirm deletion using component methods
        const vm = wrapper.vm as unknown as UsersComponentInstance;
        vm.openDeleteDialog(mockUsers[0]);
        await wrapper.vm.$nextTick();

        await vm.handleDeleteConfirm();
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 200));
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 100));

        // Verify permission error message is displayed
        expect(wrapper.text()).toContain('You do not have permission to delete users');
    });

    it('loads filters from URL query parameters', async () => {
        mockAxios.get.mockResolvedValue(createHarmonieResponse([], 2, 0));

        const router = await createTestRouter('/users?page=2&search=john&status=verified&date_from=2024-01-01&date_to=2024-12-31');
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 200));

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.currentPage).toBe(2);
        expect(vm.searchQuery).toBe('john');
        expect(vm.statusFilter).toBe('verified');
        expect(vm.dateFrom).toBe('2024-01-01');
        expect(vm.dateTo).toBe('2024-12-31');

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

        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 200));

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        vm.searchQuery = 'test';
        vm.statusFilter = 'verified';
        vm.dateFrom = '2024-01-01';
        vm.dateTo = '2024-12-31';

        await vm.applyFilters();
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 100));

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

        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 200));

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        await vm.handlePageChange(2);
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 100));

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

        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 200));

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        await vm.resetFilters();
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 100));

        expect(router.currentRoute.value.query).toEqual({});
    });
});

