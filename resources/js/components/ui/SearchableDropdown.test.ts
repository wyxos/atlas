import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import SearchableDropdown from './SearchableDropdown.vue';

afterEach(() => {
    document.body.innerHTML = '';
});

describe('SearchableDropdown', () => {
    it('renders the selected option label instead of the raw value', () => {
        const wrapper = mount(SearchableDropdown, {
            props: {
                modelValue: 'deviantart-images',
                options: [
                    { label: 'CivitAI Images', value: 'civit-ai-images' },
                    { label: 'DeviantArt Images', value: 'deviantart-images' },
                ],
            },
        });

        expect(wrapper.text()).toContain('DeviantArt Images');
        expect(wrapper.text()).not.toContain('deviantart-images');
    });

    it('searches options rendered in the portal', async () => {
        const wrapper = mount(SearchableDropdown, {
            attachTo: document.body,
            props: {
                modelValue: 'civit-ai-images',
                options: [
                    { label: 'CivitAI Images', value: 'civit-ai-images' },
                    { label: 'DeviantArt Images', value: 'deviantart-images' },
                    { label: 'Wallhaven', value: 'wallhaven' },
                ],
            },
        });

        await wrapper.get('button[role="combobox"]').trigger('click');
        await nextTick();

        const input = document.body.querySelector<HTMLInputElement>('[data-test="searchable-dropdown-search"]');
        expect(input).not.toBeNull();

        input!.value = 'deviant';
        input!.dispatchEvent(new Event('input', { bubbles: true }));
        await nextTick();

        const items = Array.from(document.body.querySelectorAll<HTMLElement>('[data-test="searchable-dropdown-item"]'));
        expect(items.map((item) => item.textContent?.trim())).toEqual(['DeviantArt Images']);
    });
});
