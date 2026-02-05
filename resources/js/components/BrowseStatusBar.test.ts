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
});
