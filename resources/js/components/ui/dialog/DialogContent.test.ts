import { mount } from '@vue/test-utils';
import { defineComponent, h, nextTick, ref } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Dialog from './Dialog.vue';
import DialogContent from './DialogContent.vue';

describe('DialogContent', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('closes on Escape without propagating the event to fullscreen listeners', async () => {
        const windowKeydown = vi.fn();
        const wrapper = mount(defineComponent({
            setup() {
                const open = ref(true);

                return () => h(Dialog, {
                    open: open.value,
                    'onUpdate:open': (value: boolean) => { open.value = value; },
                }, {
                    default: () => h(DialogContent, null, { default: () => h('button', 'Confirm') }),
                });
            },
        }), { attachTo: document.body });

        window.addEventListener('keydown', windowKeydown);

        const event = new KeyboardEvent('keydown', {
            bubbles: true,
            cancelable: true,
            key: 'Escape',
        });

        document.dispatchEvent(event);
        await nextTick();

        expect(event.defaultPrevented).toBe(true);
        expect(windowKeydown).not.toHaveBeenCalled();
        expect(document.body.textContent).not.toContain('Confirm');

        window.removeEventListener('keydown', windowKeydown);
        wrapper.unmount();
    });
});
