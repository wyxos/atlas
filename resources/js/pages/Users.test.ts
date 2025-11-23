import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { createRouter, createMemoryHistory } from 'vue-router';
import Users from './Users.vue';

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
        const router = await createTestRouter();
        const wrapper = mount(Users, {
            global: {
                plugins: [router],
            },
        });

        expect(wrapper.text()).toContain('Users');
    });

    it('renders placeholder message', async () => {
        const router = await createTestRouter();
        const wrapper = mount(Users, {
            global: {
                plugins: [router],
            },
        });

        expect(wrapper.text()).toContain('coming soon');
    });
});

