import { describe, it, expect, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import * as setup from './TabContent.test.setup';
import TabContent from './TabContent.vue';
import { BrowseFormKey } from '@/composables/useBrowseForm';
import TabFilter from './TabFilter.vue';

const {
    mount,
    mockAxios,
} = setup;

describe('TabContent - Resume Session', () => {
    it('uses tab.params.page when it is a numeric string (restoredPages should not default to 1)', async () => {
        mockAxios.get.mockResolvedValueOnce({
            data: {
                tab: {
                    id: 123,
                    label: 'Browse 1',
                    params: {
                        page: 'CURSOR_NEXT',
                        service: 'test-service',
                    },
                    items: [
                        {
                            id: 1,
                            width: 500,
                            height: 500,
                            page: 5,
                            key: '5-1',
                            index: 0,
                            src: 'https://example.com/preview1.jpg',
                            preview: 'https://example.com/preview1.jpg',
                            original: 'https://example.com/original1.jpg',
                            type: 'image',
                            notFound: false,
                        },
                    ],
                    position: 0,
                    isActive: true,
                },
            },
        });

        const wrapper = mount(TabContent, {
            props: {
                tabId: 123,
                availableServices: [{ key: 'test-service', label: 'Test Service' }],
                onReaction: vi.fn(),
                updateActiveTab: vi.fn(),
            },
        });

        await flushPromises();
        await nextTick();

        const masonry = wrapper.findComponent({ name: 'MasonryGrid' });
        expect(masonry.exists()).toBe(true);
        expect(masonry.props('restoredPages')).toBeUndefined();
        expect(masonry.props('page')).toBe('CURSOR_NEXT');
            // Restored sessions should keep backfill enabled for online browsing.
            expect(masonry.props('mode')).toBe('backfill');
    });
});
describe('TabContent - Local Page Jump', () => {
    it('respects the local page value when applying filters (does not force page 1)', async () => {
        mockAxios.get.mockResolvedValueOnce({
            data: {
                tab: {
                    id: 444,
                    label: 'Browse 1',
                    params: {
                        feed: 'local',
                        source: 'all',
                        page: 1,
                        limit: 20,
                    },
                    items: [],
                    position: 0,
                    isActive: true,
                },
            },
        });

        const wrapper = mount(TabContent, {
            props: {
                tabId: 444,
                availableServices: [],
                onReaction: vi.fn(),
                updateActiveTab: vi.fn(),
            },
        });

        await flushPromises();

        const form = (wrapper.vm as any).$?.provides?.[BrowseFormKey];
        expect(form).toBeTruthy();

        // Simulate user entering a local page number in Advanced Filters.
        form.data.page = 50;

        const filter = wrapper.findComponent(TabFilter);
        expect(filter.exists()).toBe(true);
        filter.vm.$emit('apply');

        await nextTick();

        // Masonry should restart at the requested local page (numeric pagination).
        const masonry = wrapper.findComponent({ name: 'MasonryGrid' });
        expect(masonry.props('page')).toBe(50);
        expect(form.data.page).toBe(50);
    });

    it('renders the first-page control in the header actions and resets local pagination to page 1', async () => {
        mockAxios.get.mockResolvedValueOnce({
            data: {
                tab: {
                    id: 445,
                    label: 'Browse 1',
                    params: {
                        feed: 'local',
                        source: 'all',
                        page: 50,
                        limit: 20,
                    },
                    items: [],
                    position: 0,
                    isActive: true,
                },
            },
        });

        const wrapper = mount(TabContent, {
            props: {
                tabId: 445,
                availableServices: [],
                onReaction: vi.fn(),
                updateActiveTab: vi.fn(),
            },
        });

        await flushPromises();

        const form = (wrapper.vm as any).$?.provides?.[BrowseFormKey];
        expect(form).toBeTruthy();

        const firstPageButton = wrapper.find('[data-test="go-first-page-button"]');
        expect(firstPageButton.exists()).toBe(true);

        await firstPageButton.trigger('click');
        await nextTick();

        const masonry = wrapper.findComponent({ name: 'MasonryGrid' });
        expect(masonry.props('page')).toBe(1);
        expect(form.data.page).toBe(1);
    });
});
