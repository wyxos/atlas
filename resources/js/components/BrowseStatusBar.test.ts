import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import BrowseStatusBar from './BrowseStatusBar.vue';

describe('BrowseStatusBar', () => {
    it('uses tab next value when masonry is not available', () => {
        const wrapper = mount(BrowseStatusBar, {
            props: {
                items: [],
                masonry: null,
                tab: { params: { page: 'cursor-current', next: 'cursor-next' } },
                visible: true,
            },
        });

        const pills = wrapper.findAllComponents({ name: 'Pill' });
        const nextPill = pills.find((pill) => pill.props('label') === 'Next');

        expect(nextPill?.props('value')).toBe('cursor-next');
    });

    it('prefers masonry next page when available', () => {
        const wrapper = mount(BrowseStatusBar, {
            props: {
                items: [],
                masonry: { nextPage: 'cursor-from-masonry' },
                tab: { params: { next: 'cursor-next' } },
                visible: true,
            },
        });

        const pills = wrapper.findAllComponents({ name: 'Pill' });
        const nextPill = pills.find((pill) => pill.props('label') === 'Next');

        expect(nextPill?.props('value')).toBe('cursor-from-masonry');
    });

    it('disables total pill when total is null', () => {
        const wrapper = mount(BrowseStatusBar, {
            props: {
                items: [],
                masonry: null,
                tab: { params: { page: 1, next: null } },
                total: null,
                visible: true,
            },
        });

        const pills = wrapper.findAllComponents({ name: 'Pill' });
        const totalPill = pills.find((pill) => pill.props('label') === 'Total');

        expect(totalPill?.props('value')).toBe('N/A');
        expect(wrapper.get('[data-test="total-pill-wrapper"]').classes()).toContain('opacity-50');
    });

    it('emits firstPage when first page CTA is clicked', async () => {
        const wrapper = mount(BrowseStatusBar, {
            props: {
                items: [],
                masonry: null,
                tab: { params: { page: 2, next: null } },
                visible: true,
            },
        });

        await wrapper.get('[data-test="first-page-cta"]').trigger('click');

        expect(wrapper.emitted('firstPage')?.length).toBe(1);
    });
});
