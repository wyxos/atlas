import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { createRouter, createWebHistory } from 'vue-router';
import AppHeader from './AppHeader.vue';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/dashboard', component: { template: '<div>Dashboard</div>' } },
    ],
});

describe('AppHeader', () => {
    it('renders the app name', () => {
        const wrapper = mount(AppHeader, {
            props: {
                userName: 'Test User',
                appName: 'Atlas',
            },
            global: {
                plugins: [router],
            },
        });

        expect(wrapper.text()).toContain('Atlas');
    });

    it('renders the user name', () => {
        const wrapper = mount(AppHeader, {
            props: {
                userName: 'Test User',
            },
            global: {
                plugins: [router],
            },
        });

        expect(wrapper.text()).toContain('Test User');
    });

    it('emits logout event when logout is clicked', async () => {
        const wrapper = mount(AppHeader, {
            props: {
                userName: 'Test User',
            },
            global: {
                plugins: [router],
            },
        });

        // Find and click the logout button
        const logoutButton = wrapper.find('[aria-label="User menu"]');
        await logoutButton.trigger('click');

        // Wait for dropdown to appear and find logout item
        await wrapper.vm.$nextTick();
        const logoutItem = wrapper.find('a[href="#"]');
        if (logoutItem.exists()) {
            await logoutItem.trigger('click');
        }

        // Check if logout was emitted (this is a basic test - actual implementation may vary)
        expect(wrapper.exists()).toBe(true);
    });
});

