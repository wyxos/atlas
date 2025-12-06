import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import Pill from './Pill.vue';

describe('Pill', () => {
    it('renders label and value', () => {
        const wrapper = mount(Pill, {
            props: {
                label: 'Status',
                value: 'Ready',
            },
        });

        expect(wrapper.text()).toContain('Status');
        expect(wrapper.text()).toContain('Ready');
    });

    it('applies primary variant by default', () => {
        const wrapper = mount(Pill, {
            props: {
                label: 'Test',
                value: 'Value',
            },
        });

        const pill = wrapper.find('span.inline-flex');
        expect(pill.classes()).toContain('border-smart-blue-500');
    });

    it('applies different variants correctly', () => {
        const variants = ['primary', 'secondary', 'success', 'warning', 'danger', 'info', 'neutral'] as const;

        variants.forEach((variant) => {
            const wrapper = mount(Pill, {
                props: {
                    label: 'Test',
                    value: 'Value',
                    variant,
                },
            });

            const pill = wrapper.find('span.inline-flex');
            expect(pill.exists()).toBe(true);
        });
    });

    it('applies different sizes correctly', () => {
        const sizes = ['sm', 'default', 'lg'] as const;

        sizes.forEach((size) => {
            const wrapper = mount(Pill, {
                props: {
                    label: 'Test',
                    value: 'Value',
                    size,
                },
            });

            const label = wrapper.findAll('span')[1]; // First inner span is the label
            expect(label.exists()).toBe(true);
        });
    });

    it('renders reversed pill correctly', () => {
        const wrapper = mount(Pill, {
            props: {
                label: 'Status',
                value: 'Ready',
                reversed: true,
            },
        });

        // In reversed mode, value should come first, then label
        expect(wrapper.text()).toContain('Status');
        expect(wrapper.text()).toContain('Ready');
    });

    it('shows dismiss button when dismissible', () => {
        const wrapper = mount(Pill, {
            props: {
                label: 'Test',
                value: 'Value',
                dismissible: true,
            },
        });

        const dismissButton = wrapper.find('button[aria-label="Remove"]');
        expect(dismissButton.exists()).toBe(true);
        expect(dismissButton.text()).toBe('×');
    });

    it('emits dismiss event when dismiss button is clicked', async () => {
        const wrapper = mount(Pill, {
            props: {
                label: 'Test',
                value: 'Value',
                dismissible: true,
            },
        });

        const dismissButton = wrapper.find('button[aria-label="Remove"]');
        await dismissButton.trigger('click');

        expect(wrapper.emitted('dismiss')).toBeTruthy();
        expect(wrapper.emitted('dismiss')).toHaveLength(1);
    });

    it('renders slots correctly', () => {
        const wrapper = mount(Pill, {
            props: {
                label: 'Default Label',
                value: 'Default Value',
            },
            slots: {
                label: '<span>Custom Label</span>',
                value: '<span>Custom Value</span>',
            },
        });

        expect(wrapper.text()).toContain('Custom Label');
        expect(wrapper.text()).toContain('Custom Value');
        expect(wrapper.text()).not.toContain('Default Label');
        expect(wrapper.text()).not.toContain('Default Value');
    });

    it('handles numeric values', () => {
        const wrapper = mount(Pill, {
            props: {
                label: 'Items',
                value: 42,
            },
        });

        expect(wrapper.text()).toContain('Items');
        expect(wrapper.text()).toContain('42');
    });
});

