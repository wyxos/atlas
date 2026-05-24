import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import DashboardLayout from './DashboardLayout.vue';

async function createTestRouter(initialPath = '/dashboard') {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/dashboard', component: { template: '<div>Dashboard</div>' } },
            { path: '/audio', redirect: '/playlists/all' },
            { path: '/playlists/:playlistSlug', component: { template: '<div>Audio</div>' } },
        ],
    });
    await router.push(initialPath);
    return router;
}

function setViewportWidth(width: number): void {
    Object.defineProperty(window, 'innerWidth', {
        configurable: true,
        value: width,
    });
}

describe('DashboardLayout', () => {
    it('opens the mobile menu as a full-width list with compact icons', async () => {
        setViewportWidth(430);
        const router = await createTestRouter();
        const wrapper = mount(DashboardLayout, {
            props: {
                appVersion: '1.16.1',
            },
            slots: {
                default: '<div>Page content</div>',
            },
            global: {
                plugins: [router],
            },
        });

        await wrapper.find('[aria-label="Toggle menu"]').trigger('click');

        expect(wrapper.find('aside').classes()).toContain('w-full');
        expect(wrapper.find('[aria-label="Toggle menu"]').exists()).toBe(false);
        expect(wrapper.find('[aria-label="Close menu"]').exists()).toBe(true);

        const dashboardLink = wrapper.find('a[href="/dashboard"]');
        expect(dashboardLink.classes()).toContain('flex-row');
        expect(dashboardLink.classes()).toContain('lg:flex-col');
        expect(dashboardLink.find('svg').classes()).toContain('size-5');
    });

    it('renders the static global audio player in the app shell', async () => {
        setViewportWidth(430);
        const router = await createTestRouter('/playlists/all');
        const wrapper = mount(DashboardLayout, {
            slots: {
                default: '<div>Audio page</div>',
            },
            global: {
                plugins: [router],
            },
        });

        expect(wrapper.classes()).toContain('h-screen');
        expect(wrapper.classes()).toContain('overflow-hidden');
        expect(wrapper.classes()).toContain('app-gradient');
        expect(wrapper.find('[data-test="global-audio-player"]').exists()).toBe(true);
    });
});
