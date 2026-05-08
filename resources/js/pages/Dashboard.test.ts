import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
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
            funny: number;
        };
        blacklisted: number;
        blacklisted_manual: number;
        blacklisted_feed_removed: number;
        auto_blacklisted: number;
        not_found: number;
        unreacted_not_blacklisted: number;
        unreacted_previewed_not_blacklisted: number;
        unreacted_unpreviewed_not_blacklisted: number;
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
            browse_tab?: {
                label: string;
                params: Record<string, unknown>;
            } | null;
            files_count: number;
        }>;
        top_favorites: Array<{
            id: number;
            type: string;
            source: string;
            source_id: string;
            referrer: string | null;
            browse_tab?: {
                label: string;
                params: Record<string, unknown>;
            } | null;
            files_count: number;
        }>;
        top_blacklisted: Array<{
            id: number;
            type: string;
            source: string;
            source_id: string;
            referrer: string | null;
            browse_tab?: {
                label: string;
                params: Record<string, unknown>;
            } | null;
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
            funny: 0,
        },
        blacklisted: 0,
        blacklisted_manual: 0,
        blacklisted_feed_removed: 0,
        auto_blacklisted: 0,
        not_found: 0,
        unreacted_not_blacklisted: 0,
        unreacted_previewed_not_blacklisted: 0,
        unreacted_unpreviewed_not_blacklisted: 0,
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
        configurable: true,
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
        vi.useRealTimers();
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

        expect(wrapper.text()).toContain('Moderation coverage');
        expect(wrapper.text()).toContain('Library health');
        expect(wrapper.text()).toContain('Positive outcomes');
        expect(wrapper.text()).toContain('Removal impact');
    });

    it('renders moderation coverage and regrouped metric labels', async () => {
        const metrics = createMetrics();
        metrics.files.total = 100;
        metrics.files.blacklisted = 9;
        metrics.files.blacklisted_manual = 4;
        metrics.files.auto_blacklisted = 3;
        metrics.files.blacklisted_feed_removed = 2;
        metrics.files.reactions.love = 5;
        metrics.files.reactions.like = 3;
        metrics.files.reactions.funny = 2;
        metrics.files.unreacted_not_blacklisted = 11;
        metrics.files.unreacted_previewed_not_blacklisted = 6;
        metrics.files.unreacted_unpreviewed_not_blacklisted = 5;

        mockMetricsRequest(metrics);

        const router = await createTestRouter();
        const wrapper = mount(Dashboard, {
            global: {
                plugins: [router],
            },
        });

        await Promise.resolve();
        await wrapper.vm.$nextTick();

        const text = wrapper.text();
        expect(text).toContain('Decision coverage');
        expect(text).toContain('89%');
        expect(text).toContain('Unseen');
        expect(text).toContain('Seen, no decision');
        expect(text).toContain('Kept');
        expect(text).toContain('Removed');
        expect(text).toContain('Source split');
        expect(text).toContain('Storage state');
        expect(text).toContain('Availability');
        expect(text).toContain('Remote only');
        expect(text).toContain('Decision coverage');
        expect(text).toContain('Backlog split');
        expect(text).toContain('Previewed, no decision');
        expect(text).toContain('Reaction split');
        expect(text).toContain('Favorite');
        expect(text).toContain('50%');
        expect(text).toContain('Like');
        expect(text).toContain('30%');
        expect(text).toContain('Funny');
        expect(text).toContain('20%');
        expect(text).toContain('Total blacklisted');
        expect(text).toContain('Blacklist source');
        expect(text).toContain('Manual');
        expect(text).toContain('Auto');
        expect(text).toContain('Out of feed');
        expect(text).toContain('Feed state');
        expect(text).toContain('Still in feed');
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
                browse_tab: {
                    label: 'CivitAI Images: User Desync - 1',
                    params: {
                        feed: 'online',
                        service: 'civit-ai-images',
                        page: 1,
                        limit: 20,
                        username: 'Desync',
                    },
                },
                files_count: 12,
            },
            {
                id: 2,
                type: 'Post',
                source: 'Other',
                source_id: '42',
                referrer: 'https://example.com/post/42',
                browse_tab: null,
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

        await Promise.resolve();
        await wrapper.vm.$nextTick();

        expect(wrapper.find('a[href="https://civitai.com/user/Desync"]').exists()).toBe(true);
        expect(wrapper.findAll('button').filter((button) => button.text() === 'Open in app')).toHaveLength(1);
    });

    it('renders container totals from metrics response', async () => {
        const metrics = createMetrics();
        metrics.containers.total = 987;
        metrics.containers.blacklisted = 12;

        mockMetricsRequest(metrics);

        const router = await createTestRouter();
        const wrapper = mount(Dashboard, {
            global: {
                plugins: [router],
            },
        });

        await Promise.resolve();
        await wrapper.vm.$nextTick();

        const text = wrapper.text();
        expect(text).toContain('Total containers:');
        expect(text).toContain('987');
        expect(text).toContain('Blacklisted containers:');
        expect(text).toContain('12');
    });
});

