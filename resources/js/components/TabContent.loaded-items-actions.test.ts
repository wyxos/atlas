import { describe, it, expect, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import * as setup from './TabContent.test.setup';
import TabContent from './TabContent.vue';
import type { FeedItem } from '@/composables/useTabs';

const {
    mount,
    mockAxios,
    mockRemove,
} = setup;

describe('TabContent - Loaded items actions', () => {
    it('runs the loaded items favorite action against every currently loaded item', async () => {
        const tab = {
            id: 778,
            label: 'Browse 1',
            params: {
                feed: 'online',
                service: 'test-service',
                page: 1,
            },
            items: [
                {
                    id: 101,
                    width: 500,
                    height: 500,
                    page: 1,
                    key: '1-101',
                    index: 0,
                    src: 'https://example.com/preview101.jpg',
                    preview: 'https://example.com/preview101.jpg',
                    original: 'https://example.com/original101.jpg',
                    type: 'image',
                    notFound: false,
                    previewed_count: 0,
                    seen_count: 0,
                },
                {
                    id: 102,
                    width: 500,
                    height: 500,
                    page: 1,
                    key: '1-102',
                    index: 1,
                    src: 'https://example.com/preview102.jpg',
                    preview: 'https://example.com/preview102.jpg',
                    original: 'https://example.com/original102.jpg',
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
        mockAxios.post.mockResolvedValueOnce({
            data: {
                reactions: [
                    { file_id: 101, reaction: { type: 'love' } },
                    { file_id: 102, reaction: { type: 'love' } },
                ],
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

        await wrapper.get('[data-test="loaded-items-favorite-all"]').trigger('click');
        await flushPromises();
        await nextTick();

        expect(mockAxios.post).toHaveBeenCalledWith('/api/files/reactions/batch/store', {
            reactions: [
                { file_id: 101, type: 'love' },
                { file_id: 102, type: 'love' },
            ],
        });
        expect(mockRemove).toHaveBeenCalledTimes(2);
        expect(mockRemove).toHaveBeenNthCalledWith(1, '101');
        expect(mockRemove).toHaveBeenNthCalledWith(2, '102');
    });

    it('increments preview counts for all loaded items by four', async () => {
        const tab = {
            id: 779,
            label: 'Browse 1',
            params: {
                feed: 'online',
                service: 'test-service',
                page: 1,
            },
            items: [
                {
                    id: 201,
                    width: 500,
                    height: 500,
                    page: 1,
                    key: '1-201',
                    index: 0,
                    src: 'https://example.com/preview201.jpg',
                    preview: 'https://example.com/preview201.jpg',
                    original: 'https://example.com/original201.jpg',
                    type: 'image',
                    notFound: false,
                    previewed_count: 1,
                    seen_count: 0,
                },
                {
                    id: 202,
                    width: 500,
                    height: 500,
                    page: 1,
                    key: '1-202',
                    index: 1,
                    src: 'https://example.com/preview202.jpg',
                    preview: 'https://example.com/preview202.jpg',
                    original: 'https://example.com/original202.jpg',
                    type: 'image',
                    notFound: false,
                    previewed_count: 2,
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
        mockAxios.post.mockResolvedValueOnce({
            data: {
                results: [
                    { id: 201, previewed_count: 5, will_auto_dislike: false },
                    { id: 202, previewed_count: 6, will_auto_dislike: false },
                ],
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

        await wrapper.get('[data-test="loaded-items-increment-preview-4"]').trigger('click');
        await flushPromises();
        await nextTick();

        expect(mockAxios.post).toHaveBeenCalledWith('/api/files/preview/batch', {
            file_ids: [201, 202],
            increments: 4,
        });
        expect((wrapper.vm as any).items.map((item: FeedItem) => item.previewed_count)).toEqual([5, 6]);
    });
});
