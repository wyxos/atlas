import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { describe, expect, it } from 'vitest';
import LoadedItemsRemovalDialog from './LoadedItemsRemovalDialog.vue';

const buttonStub = defineComponent({
    name: 'ButtonStub',
    props: {
        disabled: { type: Boolean, default: false },
    },
    setup(props, { attrs, slots }) {
        return () => h('button', {
            ...attrs,
            disabled: props.disabled,
        }, slots.default?.());
    },
});

const dialogStub = defineComponent({
    name: 'DialogStub',
    props: {
        modelValue: { type: Boolean, default: false },
    },
    emits: ['update:modelValue'],
    setup(props, { emit, slots }) {
        return () => props.modelValue
            ? h('div', { 'data-test': 'dialog-stub' }, [
                slots.default?.(),
                h('button', {
                    'data-test': 'dialog-close-stub',
                    onClick: () => emit('update:modelValue', false),
                }),
            ])
            : null;
    },
});

const passthroughStub = defineComponent({
    name: 'PassthroughStub',
    setup(_props, { attrs, slots }) {
        return () => h('div', attrs, slots.default?.());
    },
});

function mountDialog(props: { open?: boolean; itemCount?: number; removing?: boolean } = {}) {
    return mount(LoadedItemsRemovalDialog, {
        props: {
            open: props.open ?? true,
            itemCount: props.itemCount ?? 3,
            removing: props.removing ?? false,
        },
        global: {
            stubs: {
                Button: buttonStub,
                Dialog: dialogStub,
                DialogContent: passthroughStub,
                DialogDescription: passthroughStub,
                DialogFooter: passthroughStub,
                DialogHeader: passthroughStub,
                DialogTitle: passthroughStub,
            },
        },
    });
}

describe('LoadedItemsRemovalDialog', () => {
    it('renders the loaded item count', () => {
        const wrapper = mountDialog({ itemCount: 1 });

        expect(wrapper.get('[data-test="loaded-items-removal-confirm"]').text()).toContain('Remove loaded items from this tab?');
        expect(wrapper.get('[data-test="loaded-items-removal-confirm"]').text()).toContain('1 loaded item');
    });

    it('routes cancel, confirm, and close events', async () => {
        const wrapper = mountDialog();

        await wrapper.get('[data-test="loaded-items-removal-cancel"]').trigger('click');
        await wrapper.get('[data-test="loaded-items-removal-confirm-button"]').trigger('click');
        await wrapper.get('[data-test="dialog-close-stub"]').trigger('click');

        expect(wrapper.emitted('cancel')).toHaveLength(2);
        expect(wrapper.emitted('confirm')).toHaveLength(1);
    });

    it('disables actions and ignores close while removal is pending', async () => {
        const wrapper = mountDialog({ removing: true });

        expect(wrapper.get('[data-test="loaded-items-removal-cancel"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-test="loaded-items-removal-confirm-button"]').attributes('disabled')).toBeDefined();

        await wrapper.get('[data-test="dialog-close-stub"]').trigger('click');

        expect(wrapper.emitted('cancel')).toBeFalsy();
    });
});
