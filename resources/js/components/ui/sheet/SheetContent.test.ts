import { mount } from '@vue/test-utils';
import { defineComponent, h, nextTick } from 'vue';
import { afterEach, describe, expect, it } from 'vitest';
import Sheet from './Sheet.vue';
import SheetContent from './SheetContent.vue';
import SheetDescription from './SheetDescription.vue';
import SheetTitle from './SheetTitle.vue';

describe('SheetContent', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('renders above the global audio player layer', async () => {
        const wrapper = mount(defineComponent({
            setup() {
                return () => h(Sheet, { open: true }, {
                    default: () => h(SheetContent, null, {
                        default: () => [
                            h(SheetTitle, null, { default: () => 'Advanced Filters' }),
                            h(SheetDescription, null, { default: () => 'Filter the current browse tab.' }),
                            h('button', 'Apply'),
                        ],
                    }),
                });
            },
        }), { attachTo: document.body });

        await nextTick();

        const overlay = document.body.querySelector('[data-slot="sheet-overlay"]');
        const content = document.body.querySelector('[data-slot="sheet-content"]');

        expect(overlay).not.toBeNull();
        expect(content).not.toBeNull();
        expect(overlay?.className.toString()).toContain('z-[80]');
        expect(content?.className.toString()).toContain('z-[90]');

        wrapper.unmount();
    });
});
