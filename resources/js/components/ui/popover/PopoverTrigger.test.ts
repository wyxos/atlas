import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import Popover from './Popover.vue';
import PopoverContent from './PopoverContent.vue';
import PopoverTrigger from './PopoverTrigger.vue';

afterEach(() => {
    document.body.innerHTML = '';
});

describe('PopoverTrigger', () => {
    it('does not create a full-width click target around as-child triggers', async () => {
        const wrapper = mount({
            components: {
                Popover,
                PopoverContent,
                PopoverTrigger,
            },
            template: `
                <Popover>
                    <PopoverTrigger as-child>
                        <button data-test="trigger">Open</button>
                    </PopoverTrigger>
                    <PopoverContent>
                        <div data-test="content">Content</div>
                    </PopoverContent>
                </Popover>
            `,
        }, {
            attachTo: document.body,
        });

        const triggerWrapper = wrapper.get('[data-test="trigger"]').element.parentElement as HTMLElement;

        expect(triggerWrapper.tagName).toBe('SPAN');
        expect(triggerWrapper.style.display).toBe('contents');

        await wrapper.get('[data-test="trigger"]').trigger('click');
        await nextTick();

        expect(document.body.querySelector('[data-test="content"]')).not.toBeNull();
    });
});
