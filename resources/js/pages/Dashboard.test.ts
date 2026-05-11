import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createRouter, createMemoryHistory } from 'vue-router';
import Dashboard from './Dashboard.vue';

type DashboardMetrics = {
    files: {
        total: number;
        active_total: number;
        downloaded: number;
        stored: number;
        records_only: number;
        downloaded_stored_not_blacklisted: number;
        stored_not_blacklisted: number;
        records_only_not_blacklisted: number;
        local: number;
        non_local: number;
        local_available: number;
        non_local_available: number;
        file_types: {
            image: number;
            video: number;
            audio: number;
            other: number;
        };
        file_types_stored_not_blacklisted: {
            image: number;
            video: number;
            audio: number;
            other: number;
        };
        reactions: {
            love: number;
            like: number;
            funny: number;
        };
        reacted: number;
        unreacted: number;
        blacklisted: number;
        blacklisted_manual: number;
        blacklisted_feed_removed: number;
        blacklisted_manual_in_feed: number;
        blacklisted_auto_in_feed: number;
        auto_blacklisted: number;
        not_found: number;
        not_found_records_only_not_blacklisted: number;
        previewed_not_blacklisted: number;
        unpreviewed_not_blacklisted: number;
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
        active_total: 0,
        downloaded: 0,
        stored: 0,
        records_only: 0,
        downloaded_stored_not_blacklisted: 0,
        stored_not_blacklisted: 0,
        records_only_not_blacklisted: 0,
        local: 0,
        non_local: 0,
        local_available: 0,
        non_local_available: 0,
        file_types: {
            image: 0,
            video: 0,
            audio: 0,
            other: 0,
        },
        file_types_stored_not_blacklisted: {
            image: 0,
            video: 0,
            audio: 0,
            other: 0,
        },
        reactions: {
            love: 0,
            like: 0,
            funny: 0,
        },
        reacted: 0,
        unreacted: 0,
        blacklisted: 0,
        blacklisted_manual: 0,
        blacklisted_feed_removed: 0,
        blacklisted_manual_in_feed: 0,
        blacklisted_auto_in_feed: 0,
        auto_blacklisted: 0,
        not_found: 0,
        not_found_records_only_not_blacklisted: 0,
        previewed_not_blacklisted: 0,
        unpreviewed_not_blacklisted: 0,
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

        expect(wrapper.text()).toContain('Library records, storage coverage, and moderation impact at a glance.');
    });

    it('renders section headers', async () => {
        const router = await createTestRouter();
        const wrapper = mount(Dashboard, {
            global: {
                plugins: [router],
            },
        });

        expect(wrapper.text()).toContain('Review coverage');
        expect(wrapper.text()).toContain('Library inventory');
        expect(wrapper.text()).not.toContain('Positive reaction categories');
        expect(wrapper.text()).not.toContain('Removal impact');
    });

    it('renders review coverage and regrouped metric labels', async () => {
        const metrics = createMetrics();
        metrics.files.total = 100;
        metrics.files.blacklisted = 9;
        metrics.files.blacklisted_manual = 4;
        metrics.files.blacklisted_manual_in_feed = 4;
        metrics.files.blacklisted_auto_in_feed = 3;
        metrics.files.auto_blacklisted = 3;
        metrics.files.blacklisted_feed_removed = 2;
        metrics.files.active_total = 91;
        metrics.files.stored = 40;
        metrics.files.records_only = 60;
        metrics.files.stored_not_blacklisted = 40;
        metrics.files.records_only_not_blacklisted = 51;
        metrics.files.downloaded = 25;
        metrics.files.downloaded_stored_not_blacklisted = 25;
        metrics.files.local = 30;
        metrics.files.non_local = 70;
        metrics.files.local_available = 20;
        metrics.files.non_local_available = 75;
        metrics.files.file_types.image = 45;
        metrics.files.file_types.video = 30;
        metrics.files.file_types.audio = 15;
        metrics.files.file_types.other = 10;
        metrics.files.file_types_stored_not_blacklisted.image = 20;
        metrics.files.file_types_stored_not_blacklisted.video = 10;
        metrics.files.file_types_stored_not_blacklisted.audio = 5;
        metrics.files.file_types_stored_not_blacklisted.other = 5;
        metrics.files.not_found = 5;
        metrics.files.not_found_records_only_not_blacklisted = 5;
        metrics.files.previewed_not_blacklisted = 50;
        metrics.files.unpreviewed_not_blacklisted = 41;
        metrics.files.reacted = 60;
        metrics.files.unreacted = 31;
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
        expect(text).toContain('Previewed');
        expect(text).toContain('55%');
        expect(text).toContain('Preview state');
        expect(text).toContain('Not previewed');
        expect(text).toContain('Total files');
        expect(text).toContain('Storage coverage');
        expect(text).toContain('On disk');
        expect(text).toContain('Online records');
        expect(text).toContain('Not found');
        expect(text).toContain('Stored origin');
        expect(text).toContain('Imported');
        expect(text).toContain('File types');
        expect(text).toContain('Images');
        expect(text).toContain('Videos');
        expect(text).toContain('Audio');
        expect(text).toContain('Other');
        expect(text).toContain('40 / 91');
        expect(text).toContain('20 / 40');
        expect(text).toContain('Reaction state');
        expect(text).toContain('Reacted');
        expect(text).toContain('Unreacted');
        expect(text).toContain('31 / 91');
        expect(text).toContain('Downloaded');
        expect(text).not.toContain('Decision coverage');
        expect(text).not.toContain('Backlog split');
        expect(text).toContain('Reaction types');
        expect(text).toContain('Favorite');
        expect(text).toContain('50%');
        expect(text).toContain('Like');
        expect(text).toContain('30%');
        expect(text).toContain('Funny');
        expect(text).toContain('20%');
        expect(text).toContain('Manual');
        expect(text).toContain('Auto');
        expect(text).toContain('Out of feed');
        expect(text).toContain('Removal state');
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

