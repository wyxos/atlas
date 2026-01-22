import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createRouter, createMemoryHistory } from 'vue-router';
import Dashboard from './Dashboard.vue';

type DashboardMetrics = {
    files: {
        total: number;
        downloaded: number;
        local: number;
        non_local: number;
        reactions: {
            love: number;
            like: number;
            dislike: number;
            funny: number;
        };
        blacklisted: {
            total: number;
            manual: number;
            auto: number;
        };
        not_found: number;
        unreacted_not_blacklisted: number;
    };
    containers: {
        total: number;
        blacklisted: number;
        top_downloads: Array<{
            id: number;
            type: string;
            source: string;
            source_id: string;
            referrer: string | null;
            files_count: number;
        }>;
        top_favorites: Array<{
            id: number;
            type: string;
            source: string;
            source_id: string;
            referrer: string | null;
            files_count: number;
        }>;
        top_blacklisted: Array<{
            id: number;
            type: string;
            source: string;
            source_id: string;
            referrer: string | null;
            files_count: number;
        }>;
    };
};

const createMetrics = (): DashboardMetrics => ({
    files: {
        total: 0,
        downloaded: 0,
        local: 0,
        non_local: 0,
        reactions: {
            love: 0,
            like: 0,
            dislike: 0,
            funny: 0,
        },
        blacklisted: {
            total: 0,
            manual: 0,
            auto: 0,
        },
        not_found: 0,
        unreacted_not_blacklisted: 0,
    },
    containers: {
        total: 0,
        blacklisted: 0,
        top_downloads: [],
        top_favorites: [],
        top_blacklisted: [],
    },
});

const mockMetricsRequest = (metrics: DashboardMetrics) => {
    const mockAxios = {
        get: vi.fn().mockResolvedValue({ data: metrics }),
    };
    Object.defineProperty(window, 'axios', {
        value: mockAxios,
        writable: true,
    });
    return mockAxios;
};

async function createTestRouter(initialPath = '/dashboard') {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/dashboard', component: Dashboard },
            { path: '/users', component: { template: '<div>Users</div>' } },
            { path: '/profile', component: { template: '<div>Profile</div>' } },
        ],
    });
    await router.push(initialPath);
    return router;
}

describe('Dashboard', () => {
    beforeEach(() => {
        mockMetricsRequest(createMetrics());
    });

    it('renders the dashboard title', async () => {
        const router = await createTestRouter();
        const wrapper = mount(Dashboard, {
            global: {
                plugins: [router],
            },
        });

        expect(wrapper.text()).toContain('Dashboard');
    });

    it('renders dashboard subtitle', async () => {
        const router = await createTestRouter();
        const wrapper = mount(Dashboard, {
            global: {
                plugins: [router],
            },
        });

        expect(wrapper.text()).toContain('File volume and moderation impact at a glance.');
    });

    it('renders section headers', async () => {
        const router = await createTestRouter();
        const wrapper = mount(Dashboard, {
            global: {
                plugins: [router],
            },
        });

        expect(wrapper.text()).toContain('Reactions');
        expect(wrapper.text()).toContain('Blacklist breakdown');
    });

    it('shows container actions for supported sources', async () => {
        const metrics = createMetrics();
        metrics.containers.total = 2;
        metrics.containers.top_downloads = [
            {
                id: 1,
                type: 'User',
                source: 'CivitAI',
                source_id: 'Desync',
                referrer: 'https://civitai.com/user/Desync',
                files_count: 12,
            },
            {
                id: 2,
                type: 'Post',
                source: 'Other',
                source_id: '42',
                referrer: 'https://example.com/post/42',
                files_count: 5,
            },
        ];

        mockMetricsRequest(metrics);

        const router = await createTestRouter();
        const wrapper = mount(Dashboard, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        expect(wrapper.find('a[href="https://civitai.com/user/Desync"]').exists()).toBe(true);
        expect(wrapper.findAll('button').filter((button) => button.text() === 'Open in app')).toHaveLength(1);
    });
});

