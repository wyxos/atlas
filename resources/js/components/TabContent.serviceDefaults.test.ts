import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import TabContent from './TabContent.vue';
import { useBrowseForm } from '../composables/useBrowseForm';

// Minimal axios mock (TabContent fetches tab data on mount)
const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    patch: vi.fn(),
};

Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

beforeEach(() => {
    mockAxios.get.mockReset();
    mockAxios.post.mockReset();
    mockAxios.put.mockReset();
    mockAxios.delete.mockReset();
    mockAxios.patch.mockReset();

    // Default tab response: new tab with no params/items so Start Browsing form renders
    mockAxios.get.mockResolvedValue({
        data: {
            tab: {
                id: 123,
                label: 'Browse 123',
                params: {},
                items: [],
                position: 0,
                isActive: true,
            },
        },
    });

    // Reset singleton form between tests
    useBrowseForm().reset();
});

describe('TabContent - Service defaults', () => {
    it('applies service defaults when selecting service from header/form dropdown', async () => {
        const wrapper = mount(TabContent, {
            props: {
                tabId: 123,
                availableServices: [
                    {
                        key: 'civit-ai-images',
                        label: 'CivitAI Images',
                        defaults: {
                            limit: 20,
                            sort: 'Newest',
                            type: 'all',
                            period: 'AllTime',
                            nsfw: false,
                        },
                        schema: { fields: [] },
                    },
                ],
                onReaction: vi.fn(),
                updateActiveTab: vi.fn(),
            },
        });

        await flushPromises();
        await nextTick();

        const selects = wrapper.findAllComponents({ name: 'Select' });
        expect(selects.length).toBeGreaterThan(0);

        // Start Browsing form has a service select; emitting update should go through updateService() now.
        selects[0].vm.$emit('update:modelValue', 'civit-ai-images');
        await nextTick();

        const form = (wrapper.vm as any).browseForm;
        expect(form.data.service).toBe('civit-ai-images');
        expect(form.data.serviceFilters.sort).toBe('Newest');
        expect(form.data.serviceFilters.type).toBe('all');
        expect(form.data.serviceFilters.period).toBe('AllTime');
        expect(form.data.serviceFilters.nsfw).toBe(false);
    });
});
