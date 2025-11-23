import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { createRouter, createWebHistory } from 'vue-router';
import Users from './Users.vue';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/users', component: Users },
    ],
});

describe('Users', () => {
    it('renders the users title', () => {
        const wrapper = mount(Users, {
            global: {
                plugins: [router],
            },
        });

        expect(wrapper.text()).toContain('Users');
    });

    it('renders placeholder message', () => {
        const wrapper = mount(Users, {
            global: {
                plugins: [router],
            },
        });

        expect(wrapper.text()).toContain('coming soon');
    });
});

