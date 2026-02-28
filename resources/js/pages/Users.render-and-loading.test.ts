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

        expect(mockAxios.get).toHaveBeenCalledWith(usersIndex.definition.url, {
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

        expect(wrapper.text()).toContain('Loading...');
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

        // Check that formatted dates include time in 24-hour format
        const dateRegex = /\d{2}:\d{2}/;
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
        expect(vm.deletionHandler.dialogOpen).toBe(false);
        expect(vm.deletionHandler.itemToDelete).toBe(null);

        // Open delete dialog using component method
        vm.deletionHandler.openDialog(mockUsers[0]);
        await wrapper.vm.$nextTick();

        // Check that dialog state is updated
        expect(vm.deletionHandler.dialogOpen).toBe(true);
        expect(vm.deletionHandler.itemToDelete).toEqual(mockUsers[0]);
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
        vm.deletionHandler.openDialog(mockUsers[0]);
        await wrapper.vm.$nextTick();

        // Cancel deletion using component method
        vm.deletionHandler.closeDialog();
        await wrapper.vm.$nextTick();

        // Verify delete was not called
        expect(mockAxios.delete).not.toHaveBeenCalled();
    });
});

