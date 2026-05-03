import { describe, expect, it, vi } from 'vitest';
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
        await input.setValue('Pinned Search V2!?');
        await input.trigger('keydown', { key: 'Enter' });

        expect(wrapper.emitted('rename')).toEqual([['Pinned Search V2!?']]);
        wrapper.unmount();
    });

    it('opens the custom label editor on double click', async () => {
        const wrapper = mount(Tab, {
            props: {
                id: 1,
                label: 'Generated Label',
                customLabel: 'Pinned Search',
            },
        });

        await wrapper.get('[role="button"]').trigger('dblclick', { button: 0 });

        const input = wrapper.get('[data-test="tab-custom-label-input"]');
        expect((input.element as HTMLInputElement).value).toBe('Pinned Search');
    });

    it('renders close-range context actions with correct disabled state and emits close others', async () => {
        const wrapper = mount(Tab, {
            attachTo: document.body,
            props: {
                id: 1,
                label: 'Generated Label',
                customLabel: null,
                canCloseAbove: false,
                canCloseBelow: true,
                canCloseOthers: true,
            },
        });

        await wrapper.get('[role="button"]').trigger('contextmenu', {
            button: 2,
            clientX: 24,
            clientY: 24,
        });
        await new Promise((resolve) => window.setTimeout(resolve, 0));

        const closeAbove = document.body.querySelector('[data-test="tab-context-close-above"]');
        const closeOthers = document.body.querySelector('[data-test="tab-context-close-others"]');

        if (!(closeAbove instanceof HTMLElement) || !(closeOthers instanceof HTMLElement)) {
            throw new Error('Close actions did not render.');
        }

        expect(closeAbove.hasAttribute('data-disabled')).toBe(true);

        closeOthers.click();
        await new Promise((resolve) => window.setTimeout(resolve, 0));

        expect(wrapper.emitted('close-others')).toHaveLength(1);
        wrapper.unmount();
    });

    it('emits duplicate from the tab context menu', async () => {
        const wrapper = mount(Tab, {
            attachTo: document.body,
            props: {
                id: 1,
                label: 'Generated Label',
                customLabel: 'Pinned Search',
            },
        });

        await wrapper.get('[role="button"]').trigger('contextmenu', {
            button: 2,
            clientX: 24,
            clientY: 24,
        });
        await new Promise((resolve) => window.setTimeout(resolve, 0));

        const duplicateAction = document.body.querySelector('[data-test="tab-context-duplicate"]');
        if (!(duplicateAction instanceof HTMLElement)) {
            throw new Error('Duplicate action did not render.');
        }

        duplicateAction.click();
        await new Promise((resolve) => window.setTimeout(resolve, 0));

        expect(wrapper.emitted('duplicate')).toHaveLength(1);
        wrapper.unmount();
    });

    it('emits drag lifecycle events with before and after drop indicators', async () => {
        const wrapper = mount(Tab, {
            props: {
                id: 1,
                label: 'Generated Label',
                customLabel: null,
            },
        });

        const button = wrapper.get('[role="button"]');
        const dataTransfer = {
            setData: vi.fn(),
            effectAllowed: '',
            dropEffect: '',
        };

        Object.defineProperty(button.element, 'getBoundingClientRect', {
            value: () => ({
                top: 0,
                bottom: 40,
                left: 0,
                right: 120,
                width: 120,
                height: 40,
                x: 0,
                y: 0,
                toJSON: () => ({}),
            }),
        });

        await button.trigger('dragstart', { dataTransfer });
        await button.trigger('dragover', { clientY: 5, dataTransfer });
        await button.trigger('drop', { clientY: 35, dataTransfer });
        await button.trigger('dragend');

        expect(wrapper.emitted('drag-start')).toHaveLength(1);
        expect(wrapper.emitted('drag-over')).toEqual([['before']]);
        expect(wrapper.emitted('drag-drop')).toEqual([['after']]);
        expect(wrapper.emitted('drag-end')).toHaveLength(1);
    });
});
