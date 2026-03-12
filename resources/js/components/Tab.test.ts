import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Tab from './Tab.vue';

describe('Tab', () => {
    it('renders nickname as the primary label and the generated label as secondary text', () => {
        const wrapper = mount(Tab, {
            props: {
                id: 1,
                label: 'Generated Label',
                nickname: 'Pinned Search',
            },
        });

        expect(wrapper.text()).toContain('Pinned Search');
        expect(wrapper.text()).toContain('Generated Label');
    });

    it('emits rename when the nickname editor is saved', async () => {
        const wrapper = mount(Tab, {
            props: {
                id: 1,
                label: 'Generated Label',
                nickname: null,
            },
        });

        await wrapper.get('[data-test="tab-rename-button"]').trigger('click');

        const input = wrapper.get('[data-test="tab-nickname-input"]');
        await input.setValue('Pinned Search');
        await input.trigger('keydown', { key: 'Enter' });

        expect(wrapper.emitted('rename')).toEqual([['Pinned Search']]);
    });
});
