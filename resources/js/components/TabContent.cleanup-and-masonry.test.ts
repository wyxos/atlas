import { describe, it, expect, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import * as setup from './TabContent.test.setup';
import TabContent from './TabContent.vue';
import TabFilter from './TabFilter.vue';
import type { FeedItem } from '@/composables/useTabs';

const {
    mount,
    mockAxios,
    mockClearAutoDislikeCountdowns,
    mockLoadNext,
} = setup;

describe('TabContent - Auto-dislike cleanup', () => {
    it('clears auto-dislike countdowns on unmount', async () => {
        mockAxios.get.mockResolvedValueOnce({
            data: {
                tab: {
                    id: 321,
                    label: 'Browse 1',
                    params: {
                        page: 1,
                        service: 'test-service',
                    },
                    items: [],
                    position: 0,
                    isActive: true,
                },
            },
        });

        const wrapper = mount(TabContent, {
            props: {
                tabId: 321,
                availableServices: [{ key: 'test-service', label: 'Test Service' }],
                onReaction: vi.fn(),
                updateActiveTab: vi.fn(),
            },
        });

        await flushPromises();

        wrapper.unmount();

        expect(mockClearAutoDislikeCountdowns).toHaveBeenCalled();
    });
});
describe('TabContent - Preview Cache Reset', () => {
    it('clears previewed items when applying filters/presets', async () => {
        const { __test } = await import('@/composables/useItemPreview');
        __test.mockClearPreviewedItems.mockClear();

        mockAxios.get.mockResolvedValueOnce({
            data: {
                tab: {
                    id: 990,
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
                tabId: 990,
                availableServices: [],
                onReaction: vi.fn(),
                updateActiveTab: vi.fn(),
            },
        });

        await flushPromises();

        const filter = wrapper.findComponent(TabFilter);
        expect(filter.exists()).toBe(true);

        filter.vm.$emit('apply');
        await nextTick();

        expect(__test.mockClearPreviewedItems).toHaveBeenCalled();

        wrapper.unmount();
    });
});
describe('TabContent - Masonry removed', () => {
    it('loads next page only when Masonry emits removed and no items remain in online mode', async () => {
        const tab = {
            id: 777,
            label: 'Browse 1',
            params: {
                feed: 'online',
                service: 'test-service',
                page: 1,
            },
            items: [
                {
                    id: 0,
                    width: 500,
                    height: 500,
                    page: 1,
                    key: '1-0',
                    index: 0,
                    src: 'https://example.com/preview0.jpg',
                    preview: 'https://example.com/preview0.jpg',
                    original: 'https://example.com/original0.jpg',
                    type: 'image',
                    notFound: false,
                    previewed_count: 0,
                    seen_count: 0,
                },
                {
                    id: 1,
                    width: 500,
                    height: 500,
                    page: 1,
                    key: '1-1',
                    index: 0,
                    src: 'https://example.com/preview1.jpg',
                    preview: 'https://example.com/preview1.jpg',
                    original: 'https://example.com/original1.jpg',
                    type: 'image',
                    notFound: false,
                    previewed_count: 0,
                    seen_count: 0,
                },
            ],
            position: 0,
            isActive: true,
        };

        mockAxios.get.mockResolvedValueOnce({
            data: {
                tab,
            },
        });

        const wrapper = mount(TabContent, {
            props: {
                tabId: tab.id,
                availableServices: [{ key: 'test-service', label: 'Test Service' }],
                onReaction: vi.fn(),
                updateActiveTab: vi.fn(),
            },
        });

        await flushPromises();
        await nextTick();

        const masonry = wrapper.findComponent({ name: 'MasonryGrid' });
        expect(masonry.exists()).toBe(true);

        masonry.vm.$emit('removed', {
            items: tab.items,
            ids: tab.items.map((item: FeedItem) => String(item.id)),
        });

        await nextTick();

        expect(mockLoadNext).not.toHaveBeenCalled();

        const vm = wrapper.vm as any;
        vm.items = [];
        await nextTick();

        masonry.vm.$emit('removed', {
            items: tab.items,
            ids: tab.items.map((item: FeedItem) => String(item.id)),
        });
        await nextTick();

        expect(mockLoadNext).toHaveBeenCalledTimes(1);
    });
});
