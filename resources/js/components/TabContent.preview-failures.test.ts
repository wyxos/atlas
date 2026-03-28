import { describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import * as setup from './TabContent.test.setup';
import TabContent from './TabContent.vue';
import FileViewer from './FileViewer.vue';
import type { FeedItem } from '@/composables/useTabs';

const {
    mount,
    mockAxios,
    mockCancelAutoDislikeCountdown,
    mockRemove,
} = setup;

describe('TabContent - Preview failure reconciliation', () => {
    it('reports civitai preview load failures to the backend', async () => {
        const tab = {
            id: 901,
            label: 'Browse 1',
            params: {
                feed: 'online',
                service: 'civit-ai-images',
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
                    src: 'https://image.civitai.com/token/guid/width=1216/guid.jpeg',
                    preview: 'https://image.civitai.com/token/guid/width=1216/guid.jpeg',
                    original: 'https://image.civitai.com/token/guid/original=true/guid.jpeg',
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
                availableServices: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
                onReaction: vi.fn(),
                updateActiveTab: vi.fn(),
            },
        });

        await flushPromises();
        await nextTick();

        const masonry = wrapper.findComponent({ name: 'MasonryGrid' });
        masonry.vm.$emit('failures', [
            {
                item: tab.items[0],
                error: new Event('error'),
            },
        ]);

        await flushPromises();

        expect(mockAxios.post).toHaveBeenCalledWith('/api/files/101/preview-failure');
    });

    it('marks current-tab items as 404 from the preview-failure response and removes them after the delay', async () => {
        vi.useFakeTimers();

        const tab = {
            id: 903,
            label: 'Browse 1',
            params: {
                feed: 'online',
                service: 'civit-ai-images',
                page: 1,
            },
            items: [
                {
                    id: 103,
                    width: 500,
                    height: 500,
                    page: 1,
                    key: '1-103',
                    index: 0,
                    src: 'https://image.civitai.com/token/guid-3/width=1216/guid-3.jpeg',
                    preview: 'https://image.civitai.com/token/guid-3/width=1216/guid-3.jpeg',
                    original: 'https://image.civitai.com/token/guid-3/original=true/guid-3.jpeg',
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
                fileId: 103,
                notFound: true,
                tabIds: [tab.id],
            },
        });

        try {
            const wrapper = mount(TabContent, {
                props: {
                    tabId: tab.id,
                    availableServices: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
                    onReaction: vi.fn(),
                    updateActiveTab: vi.fn(),
                },
            });

            await flushPromises();
            await nextTick();

            const masonry = wrapper.findComponent({ name: 'MasonryGrid' });
            masonry.vm.$emit('failures', [
                {
                    item: tab.items[0],
                    error: new Event('error'),
                },
            ]);

            await flushPromises();
            await nextTick();

            const itemsAfterResponse = masonry.props('items') as FeedItem[];
            expect(itemsAfterResponse[0]?.notFound).toBe(true);
            expect(mockCancelAutoDislikeCountdown).toHaveBeenCalledWith(103);

            await vi.advanceTimersByTimeAsync(5000);
            await nextTick();

            expect(mockRemove).toHaveBeenCalledWith('103');
            expect(masonry.props('items')).toHaveLength(0);

            wrapper.unmount();
        } finally {
            vi.useRealTimers();
        }
    });

    it('reports viewer full-size preload failures through the same not-found flow', async () => {
        vi.useFakeTimers();

        const tab = {
            id: 904,
            label: 'Browse 1',
            params: {
                feed: 'online',
                service: 'civit-ai-images',
                page: 1,
            },
            items: [
                {
                    id: 104,
                    width: 500,
                    height: 500,
                    page: 1,
                    key: '1-104',
                    index: 0,
                    src: 'https://image.civitai.com/token/guid-4/width=1216/guid-4.jpeg',
                    preview: 'https://image.civitai.com/token/guid-4/width=1216/guid-4.jpeg',
                    original: 'https://image.civitai.com/token/guid-4/original=true/guid-4.jpeg',
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
                fileId: 104,
                notFound: true,
                tabIds: [tab.id],
            },
        });

        try {
            const wrapper = mount(TabContent, {
                props: {
                    tabId: tab.id,
                    availableServices: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
                    onReaction: vi.fn(),
                    updateActiveTab: vi.fn(),
                },
            });

            await flushPromises();
            await nextTick();

            wrapper.findComponent(FileViewer).vm.$emit('preview-failure', tab.items[0]);

            await flushPromises();
            await nextTick();

            const masonry = wrapper.findComponent({ name: 'MasonryGrid' });
            const itemsAfterResponse = masonry.props('items') as FeedItem[];
            expect(mockAxios.post).toHaveBeenCalledWith('/api/files/104/preview-failure');
            expect(itemsAfterResponse[0]?.notFound).toBe(true);

            await vi.advanceTimersByTimeAsync(5000);
            await nextTick();

            expect(mockRemove).toHaveBeenCalledWith('104');
            expect(masonry.props('items')).toHaveLength(0);

            wrapper.unmount();
        } finally {
            vi.useRealTimers();
        }
    });

    it('marks current-tab items as 404 and removes them after the broadcast delay', async () => {
        vi.useFakeTimers();

        const userMeta = document.createElement('meta');
        userMeta.setAttribute('name', 'user-id');
        userMeta.setAttribute('content', '7');
        document.head.appendChild(userMeta);

        const listeners: Record<string, (payload: unknown) => void> = {};
        const privateMock = vi.fn(() => ({
            listen: vi.fn((event: string, callback: (payload: unknown) => void) => {
                listeners[event] = callback;
            }),
        }));
        const leaveMock = vi.fn();
        (window as typeof window & {
            Echo?: { private: typeof privateMock; leave: typeof leaveMock };
        }).Echo = {
            private: privateMock,
            leave: leaveMock,
        };

        const tab = {
            id: 902,
            label: 'Browse 1',
            params: {
                feed: 'online',
                service: 'civit-ai-images',
                page: 1,
            },
            items: [
                {
                    id: 102,
                    width: 500,
                    height: 500,
                    page: 1,
                    key: '1-102',
                    index: 0,
                    src: 'https://image.civitai.com/token/guid-2/width=1216/guid-2.jpeg',
                    preview: 'https://image.civitai.com/token/guid-2/width=1216/guid-2.jpeg',
                    original: 'https://image.civitai.com/token/guid-2/original=true/guid-2.jpeg',
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

        try {
            const wrapper = mount(TabContent, {
                props: {
                    tabId: tab.id,
                    availableServices: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
                    onReaction: vi.fn(),
                    updateActiveTab: vi.fn(),
                },
            });

            await flushPromises();
            await nextTick();

            const masonry = wrapper.findComponent({ name: 'MasonryGrid' });
            expect(listeners['.FileMarkedNotFound']).toBeTypeOf('function');

            listeners['.FileMarkedNotFound']({
                fileId: 102,
                tabIds: [tab.id],
            });

            await nextTick();

            const itemsAfterBroadcast = masonry.props('items') as FeedItem[];
            expect(itemsAfterBroadcast[0]?.notFound).toBe(true);
            expect(mockCancelAutoDislikeCountdown).toHaveBeenCalledWith(102);

            await vi.advanceTimersByTimeAsync(5000);
            await nextTick();

            expect(mockRemove).toHaveBeenCalledWith('102');
            expect(masonry.props('items')).toHaveLength(0);

            wrapper.unmount();
        } finally {
            userMeta.remove();
            vi.useRealTimers();
        }
    });
});
