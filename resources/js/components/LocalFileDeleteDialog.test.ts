import { defineComponent } from 'vue';
import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import LocalFileDeleteDialog from './LocalFileDeleteDialog.vue';

const PassthroughStub = defineComponent({
    template: '<div><slot /></div>',
});

const ButtonStub = defineComponent({
    inheritAttrs: false,
    props: {
        disabled: Boolean,
    },
    emits: ['click'],
    template: '<button v-bind="$attrs" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
});

function mountDialog() {
    return mount(LocalFileDeleteDialog, {
        props: {
            open: true,
            filename: 'managed-file.jpg',
            deleting: false,
        },
        global: {
            stubs: {
                Button: ButtonStub,
                Dialog: PassthroughStub,
                DialogContent: PassthroughStub,
                DialogDescription: PassthroughStub,
                DialogFooter: PassthroughStub,
                DialogHeader: PassthroughStub,
                DialogTitle: PassthroughStub,
            },
        },
    });
}

describe('LocalFileDeleteDialog', () => {
    it('requires the explicit destructive action before confirming deletion', async () => {
        const wrapper = mountDialog();

        expect(wrapper.emitted('confirm')).toBeUndefined();
        expect(wrapper.text()).toContain('permanently removes the Atlas file record and any stored asset or generated previews when present');

        await wrapper.get('[data-test="local-file-delete-cancel"]').trigger('click');
        expect(wrapper.emitted('cancel')).toHaveLength(1);
        expect(wrapper.emitted('confirm')).toBeUndefined();

        await wrapper.get('[data-test="local-file-delete-confirm"]').trigger('click');
        expect(wrapper.emitted('confirm')).toHaveLength(1);
    });
});
