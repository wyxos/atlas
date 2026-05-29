import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { describe, expect, it } from 'vitest';
import LoadedItemsBatchActionDialog from './LoadedItemsBatchActionDialog.vue';

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

function mountDialog(action: 'love' | 'like' | 'blacklist' | null = 'blacklist') {
    return mount(LoadedItemsBatchActionDialog, {
        props: {
            action,
            itemCount: 3,
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

describe('LoadedItemsBatchActionDialog', () => {
    it('renders the pending batch action and item count', () => {
        const wrapper = mountDialog('blacklist');

        expect(wrapper.get('[data-test="loaded-items-batch-action-confirm"]').text()).toContain('Blacklist all loaded items?');
        expect(wrapper.get('[data-test="loaded-items-batch-action-confirm"]').text()).toContain('3 loaded items');
    });

    it('routes cancel, confirm, and close events', async () => {
        const wrapper = mountDialog('love');

        await wrapper.get('[data-test="loaded-items-batch-action-cancel"]').trigger('click');
        await wrapper.get('[data-test="loaded-items-batch-action-confirm-button"]').trigger('click');
        await wrapper.get('[data-test="dialog-close-stub"]').trigger('click');

        expect(wrapper.emitted('cancel')).toHaveLength(2);
        expect(wrapper.emitted('confirm')).toHaveLength(1);
    });

    it('stays closed when no batch action is pending', () => {
        const wrapper = mountDialog(null);

        expect(wrapper.find('[data-test="loaded-items-batch-action-confirm"]').exists()).toBe(false);
    });
});
