import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { createRouter, createMemoryHistory } from 'vue-router';
import Dashboard from './Dashboard.vue';

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
    it('renders the dashboard title', async () => {
        const router = await createTestRouter();
        const wrapper = mount(Dashboard, {
            global: {
                plugins: [router],
            },
        });

        expect(wrapper.text()).toContain('Dashboard');
    });

    it('renders welcome message', async () => {
        const router = await createTestRouter();
        const wrapper = mount(Dashboard, {
            global: {
                plugins: [router],
            },
        });

        expect(wrapper.text()).toContain('Welcome to your dashboard');
    });

    it('has a link to users page', async () => {
        const router = await createTestRouter();
        const wrapper = mount(Dashboard, {
            global: {
                plugins: [router],
            },
        });

        // router-link renders as an <a> tag with href="/users"
        const usersLink = wrapper.find('a[href="/users"]');
        expect(usersLink.exists()).toBe(true);
    });
});

