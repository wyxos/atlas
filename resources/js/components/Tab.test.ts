import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Tab from './Tab.vue';

describe('Tab', () => {
    it('renders custom label as the primary label and the generated label as secondary text', () => {
        const wrapper = mount(Tab, {
            props: {
                id: 1,
                label: 'Generated Label',
                customLabel: 'Pinned Search',
            },
        });

        expect(wrapper.text()).toContain('Pinned Search');
        expect(wrapper.text()).toContain('Generated Label');
    });

    it('emits rename when the custom label editor is saved', async () => {
        const wrapper = mount(Tab, {
            props: {
                id: 1,
                label: 'Generated Label',
                customLabel: null,
            },
        });

        await wrapper.get('[data-test="tab-rename-button"]').trigger('click');

        const input = wrapper.get('[data-test="tab-custom-label-input"]');
        await input.setValue('Pinned Search');
        await input.trigger('keydown', { key: 'Enter' });

        expect(wrapper.emitted('rename')).toEqual([['Pinned Search']]);
    });
});
