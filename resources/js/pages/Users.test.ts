import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createRouter, createMemoryHistory } from 'vue-router';
import Users from './Users.vue';
import Oruga from '@oruga-ui/oruga-next';

// Mock axios
vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
    },
}));

// Mock window.axios
const mockAxios = {
    get: vi.fn(),
};

beforeEach(() => {
    vi.clearAllMocks();
    global.window = {
        ...global.window,
        axios: mockAxios,
    } as typeof window;
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

describe('Users', () => {
    it('renders the users title', async () => {
        mockAxios.get.mockResolvedValue({
            data: {
                data: [],
                links: {},
                meta: {
                    current_page: 1,
                    total: 0,
                    per_page: 15,
                    last_page: 1,
                },
            },
        });

        const router = await createTestRouter();
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        expect(wrapper.text()).toContain('Users');
    });

    it('fetches and displays users', async () => {
        const mockUsers = [
            {
                id: 1,
                name: 'John Doe',
                email: 'john@example.com',
                email_verified_at: '2024-01-01T00:00:00Z',
                created_at: '2024-01-01T00:00:00Z',
            },
            {
                id: 2,
                name: 'Jane Smith',
                email: 'jane@example.com',
                email_verified_at: null,
                created_at: '2024-01-02T00:00:00Z',
            },
        ];

        mockAxios.get.mockResolvedValue({
            data: {
                data: mockUsers,
                links: {},
                meta: {
                    current_page: 1,
                    total: 2,
                    per_page: 15,
                    last_page: 1,
                },
            },
        });

        const router = await createTestRouter();
        const wrapper = mount(Users, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 100));

        expect(mockAxios.get).toHaveBeenCalledWith('/api/users', {
            params: {
                page: 1,
                per_page: 15,
            },
        });
        expect(wrapper.text()).toContain('John Doe');
        expect(wrapper.text()).toContain('jane@example.com');
    });

    it('displays loading state', async () => {
        mockAxios.get.mockImplementation(() => new Promise(() => {})); // Never resolves

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
});

