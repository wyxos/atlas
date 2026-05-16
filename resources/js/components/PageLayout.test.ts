import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import PageLayout from './PageLayout.vue';

describe('PageLayout', () => {
    it('does not add default page gutters', () => {
        const wrapper = mount(PageLayout, {
            slots: {
                default: '<div>Page content</div>',
            },
        });

        expect(wrapper.get('[data-test="page-layout"]').classes()).not.toContain('md:p-8');
        expect(wrapper.get('[data-test="page-layout-content"]').classes()).not.toContain('p-4');
        expect(wrapper.get('[data-test="page-layout-content"]').classes()).not.toContain('md:p-8');
    });
});
