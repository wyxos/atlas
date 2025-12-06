import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { createRouter, createMemoryHistory } from 'vue-router';
import AppHeader from './AppHeader.vue';

async function createTestRouter(initialPath = '/dashboard') {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/dashboard', component: { template: '<div>Dashboard</div>' } },
            { path: '/users', component: { template: '<div>Users</div>' } },
            { path: '/profile', component: { template: '<div>Profile</div>' } },
        ],
    });
    await router.push(initialPath);
    return router;
}

describe('AppHeader', () => {
    it('renders the menu toggle button', async () => {
        const router = await createTestRouter();
        const wrapper = mount(AppHeader, {
            props: {
                userName: 'Test User',
                appName: 'Atlas',
            },
            global: {
                plugins: [router],
            },
        });

        // Check that the menu toggle button is rendered
        const menuButton = wrapper.find('[aria-label="Toggle menu"]');
        expect(menuButton.exists()).toBe(true);
    });

    it('emits toggle-menu event when menu button is clicked', async () => {
        const router = await createTestRouter();
        const wrapper = mount(AppHeader, {
            props: {
                userName: 'Test User',
            },
            global: {
                plugins: [router],
            },
        });

        // Find and click the menu toggle button
        const menuButton = wrapper.find('[aria-label="Toggle menu"]');
        await menuButton.trigger('click');

        // Check if toggle-menu was emitted
        expect(wrapper.emitted('toggle-menu')).toBeTruthy();
        expect(wrapper.emitted('toggle-menu')).toHaveLength(1);
    });
});

