import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import LocalSourceDropdown from './LocalSourceDropdown.vue';

afterEach(() => {
    document.body.innerHTML = '';
});

describe('LocalSourceDropdown', () => {
    it('searches source options and emits selection updates', async () => {
        const wrapper = mount(LocalSourceDropdown, {
            attachTo: document.body,
            props: {
                modelValue: 'all',
                options: [
                    { label: 'All', value: 'all' },
                    { label: 'Library', value: 'local' },
                    { label: 'Spotify', value: 'Spotify' },
                    { label: 'Bandcamp', value: 'Bandcamp' },
                ],
            },
        });

        await wrapper.get('[data-test="source-select-trigger"]').trigger('click');
        await nextTick();

        const input = document.body.querySelector<HTMLInputElement>('[data-test="source-select-search"]');
        expect(input).not.toBeNull();

        input!.value = 'spot';
        input!.dispatchEvent(new Event('input', { bubbles: true }));
        await nextTick();

        const items = Array.from(document.body.querySelectorAll<HTMLElement>('[data-test="source-select-item"]'));
        expect(items.map((item) => item.textContent?.trim())).toEqual(['Spotify']);

        items[0].click();
        await nextTick();

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['Spotify']]);
    });

    it('shows an empty state when the search has no matches', async () => {
        const wrapper = mount(LocalSourceDropdown, {
            attachTo: document.body,
            props: {
                modelValue: 'all',
                options: [
                    { label: 'All', value: 'all' },
                    { label: 'Library', value: 'local' },
                    { label: 'Spotify', value: 'Spotify' },
                ],
            },
        });

        await wrapper.get('[data-test="source-select-trigger"]').trigger('click');
        await nextTick();

        const input = document.body.querySelector<HTMLInputElement>('[data-test="source-select-search"]');
        expect(input).not.toBeNull();

        input!.value = 'missing';
        input!.dispatchEvent(new Event('input', { bubbles: true }));
        await nextTick();

        expect(document.body.querySelector('[data-test="source-select-item"]')).toBeNull();
        expect(document.body.querySelector('[data-test="source-select-empty"]')?.textContent?.trim()).toBe('No sources found.');
    });
});
