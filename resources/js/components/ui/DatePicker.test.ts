import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, ref, nextTick } from 'vue';
import DatePicker from './DatePicker.vue';

describe('DatePicker', () => {
    it('does not submit the form when trigger is clicked and shows calendar', async () => {
        const formSubmitted = ref(false);
        const model = ref('');

        const Wrapper = defineComponent({
            components: { DatePicker },
            setup() {
                return { formSubmitted, model };
            },
            template: `
                <form @submit.prevent="formSubmitted = true">
                    <DatePicker v-model="model" placeholder="Pick a date" />
                </form>
            `,
        });

        const wrapper = mount(Wrapper, {
            attachTo: document.body, // required for Teleport-based popover
        });

        // Click the DatePicker trigger button
        const triggerButton = wrapper.find('button');
        expect(triggerButton.exists()).toBe(true);
        await triggerButton.trigger('click');
        await nextTick();

        // Should not have submitted the form
        expect(formSubmitted.value).toBe(false);

        // Calendar popover should render into body (days of week visible)
        expect(document.body.textContent).toMatch(/Sun|Mon|Tue|Wed|Thu|Fri|Sat/);
    });
});


