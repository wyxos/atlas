import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import Profile from './Profile.vue';

describe('Profile', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        (window as unknown as { axios?: { post: ReturnType<typeof vi.fn>; delete: ReturnType<typeof vi.fn>; isAxiosError: ReturnType<typeof vi.fn> } }).axios = {
            post: vi.fn(),
            delete: vi.fn(),
            isAxiosError: vi.fn(),
        };
    });

    it('renders the profile page', () => {
        const wrapper = mount(Profile);
        expect(wrapper.text()).toContain('Profile');
        expect(wrapper.text()).toContain('Manage your account settings');
    });

    it('renders password change form', () => {
        const wrapper = mount(Profile);
        expect(wrapper.find('#current_password').exists()).toBe(true);
        expect(wrapper.find('#password').exists()).toBe(true);
        expect(wrapper.find('#password_confirmation').exists()).toBe(true);
    });

    it('renders delete account form', () => {
        const wrapper = mount(Profile);
        expect(wrapper.find('#delete_password').exists()).toBe(true);
    });

    it('handles password update successfully', async () => {
        const wrapper = mount(Profile);
        const mockResponse = { data: { message: 'Password updated successfully.' } };
        const mockPost = vi.fn().mockResolvedValue(mockResponse);
        const windowAxios = window as unknown as { axios?: { post: ReturnType<typeof vi.fn> } };
        windowAxios.axios = { post: mockPost };

        await wrapper.find('#current_password').setValue('oldpassword');
        await wrapper.find('#password').setValue('newpassword');
        await wrapper.find('#password_confirmation').setValue('newpassword');
        await wrapper.find('form').trigger('submit.prevent');

        await wrapper.vm.$nextTick();
        expect(mockPost).toHaveBeenCalledWith('/profile/password', {
            current_password: 'oldpassword',
            password: 'newpassword',
            password_confirmation: 'newpassword',
        });
    });
});

