import { afterEach, describe, expect, it } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import AudioFilterSheet from './AudioFilterSheet.vue';

afterEach(() => {
    document.body.innerHTML = '';
});

describe('AudioFilterSheet', () => {
    it('pads the sheet body on mobile', async () => {
        mount(AudioFilterSheet, {
            attachTo: document.body,
            props: {
                open: true,
                activeFilter: 'all',
                visibleCount: 0,
                totalCount: 0,
            },
        });
        await flushPromises();

        const sheetBody = document.body.querySelector('[data-test="audio-filter-sheet-body"]');

        expect(sheetBody).not.toBeNull();
        expect(sheetBody?.classList.contains('px-6')).toBe(true);
    });
});
