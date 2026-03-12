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

    it('opens the custom label editor from the context menu and emits rename when saved', async () => {
        const wrapper = mount(Tab, {
            attachTo: document.body,
            props: {
                id: 1,
                label: 'Generated Label',
                customLabel: null,
            },
        });

        await wrapper.get('[role="button"]').trigger('contextmenu', {
            button: 2,
            clientX: 24,
            clientY: 24,
        });
        await new Promise((resolve) => window.setTimeout(resolve, 0));

        const renameAction = document.body.querySelector('[data-test="tab-context-rename"]');
        if (!(renameAction instanceof HTMLElement)) {
            throw new Error('Rename action did not render.');
        }

        renameAction.click();
        await new Promise((resolve) => window.setTimeout(resolve, 0));

        const input = wrapper.get('[data-test="tab-custom-label-input"]');
        await input.setValue('Pinned Search');
        await input.trigger('keydown', { key: 'Enter' });

        expect(wrapper.emitted('rename')).toEqual([['Pinned Search']]);
        wrapper.unmount();
    });
});
