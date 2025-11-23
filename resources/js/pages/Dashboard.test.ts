import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { createRouter, createWebHistory } from 'vue-router';
import Dashboard from './Dashboard.vue';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/dashboard', component: Dashboard },
        { path: '/users', component: { template: '<div>Users</div>' } },
    ],
});

describe('Dashboard', () => {
    it('renders the dashboard title', () => {
        const wrapper = mount(Dashboard, {
            global: {
                plugins: [router],
            },
        });

        expect(wrapper.text()).toContain('Dashboard');
    });

    it('renders welcome message', () => {
        const wrapper = mount(Dashboard, {
            global: {
                plugins: [router],
            },
        });

        expect(wrapper.text()).toContain('Welcome to your dashboard');
    });

    it('has a link to users page', () => {
        const wrapper = mount(Dashboard, {
            global: {
                plugins: [router],
            },
        });

        const usersLink = wrapper.find('a[href="/users"]');
        expect(usersLink.exists()).toBe(true);
    });
});

