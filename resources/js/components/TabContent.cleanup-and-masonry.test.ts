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
    mockRemove,
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

describe('TabContent - Container blacklist updates', () => {
    it('removes currently loaded items that belong to a newly blacklisted container', async () => {
        const tab = {
            id: 888,
            label: 'Browse 1',
            params: {
                feed: 'online',
                service: 'test-service',
                page: 1,
            },
            items: [
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
                    containers: [
                        { id: 101, type: 'User', source: 'CivitAI', source_id: 'PYBY_the_Fox' },
                    ],
                },
                {
                    id: 2,
                    width: 500,
                    height: 500,
                    page: 1,
                    key: '1-2',
                    index: 1,
                    src: 'https://example.com/preview2.jpg',
                    preview: 'https://example.com/preview2.jpg',
                    original: 'https://example.com/original2.jpg',
                    type: 'image',
                    notFound: false,
                    containers: [
                        { id: 202, type: 'User', source: 'CivitAI', source_id: 'other-user' },
                    ],
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

        const manager = wrapper.findComponent({ name: 'ContainerBlacklistManager' });
        expect(manager.exists()).toBe(true);

        manager.vm.$emit('blacklists-changed', {
            action: 'created',
            blacklist: {
                id: 101,
                type: 'User',
                source: 'CivitAI',
                source_id: 'PYBY_the_Fox',
                action_type: 'blacklist',
                blacklisted_at: '2026-03-14T00:00:00Z',
            },
        });

        await nextTick();

        const masonry = wrapper.findComponent({ name: 'MasonryGrid' });
        expect(mockRemove).toHaveBeenCalledTimes(1);
        expect(mockRemove).toHaveBeenCalledWith([
            expect.objectContaining({ id: 1 }),
        ]);
        expect(masonry.props('items')).toHaveLength(1);
        expect((masonry.props('items') as FeedItem[])[0].id).toBe(2);
    });

    it('filters later page loads that match a newly blacklisted container in the same tab', async () => {
        const tab = {
            id: 889,
            label: 'Browse 1',
            params: {
                feed: 'online',
                service: 'test-service',
                page: 1,
            },
            items: [],
            position: 0,
            isActive: true,
        };

        mockAxios.get
            .mockResolvedValueOnce({
                data: {
                    tab,
                },
            })
            .mockResolvedValueOnce({
                data: {
                    items: [
                        {
                            id: 10,
                            width: 500,
                            height: 500,
                            page: 'cursor-2',
                            key: 'cursor-2-10',
                            index: 0,
                            src: 'https://example.com/preview10.jpg',
                            preview: 'https://example.com/preview10.jpg',
                            original: 'https://example.com/original10.jpg',
                            type: 'image',
                            notFound: false,
                            containers: [
                                { id: 303, type: 'User', source: 'CivitAI', source_id: 'PYBY_the_Fox' },
                            ],
                        },
                        {
                            id: 11,
                            width: 500,
                            height: 500,
                            page: 'cursor-2',
                            key: 'cursor-2-11',
                            index: 1,
                            src: 'https://example.com/preview11.jpg',
                            preview: 'https://example.com/preview11.jpg',
                            original: 'https://example.com/original11.jpg',
                            type: 'image',
                            notFound: false,
                            containers: [
                                { id: 404, type: 'User', source: 'CivitAI', source_id: 'allowed-user' },
                            ],
                        },
                    ],
                    nextPage: null,
                    total: null,
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

        const manager = wrapper.findComponent({ name: 'ContainerBlacklistManager' });
        expect(manager.exists()).toBe(true);

        manager.vm.$emit('blacklists-changed', {
            action: 'created',
            blacklist: {
                id: 101,
                type: 'User',
                source: 'CivitAI',
                source_id: 'PYBY_the_Fox',
                action_type: 'blacklist',
                blacklisted_at: '2026-03-15T00:00:00Z',
            },
        });

        await nextTick();

        const masonry = wrapper.findComponent({ name: 'MasonryGrid' });
        expect(masonry.exists()).toBe(true);

        const getContent = masonry.props('getContent') as (
            page: string
        ) => Promise<{ items: FeedItem[]; nextPage: string | null }>;

        const result = await getContent('cursor-2');

        expect(result.items).toHaveLength(1);
        expect(result.items[0].id).toBe(11);
    });

    it('re-filters items appended from masonry backfill after a newly blacklisted container is active', async () => {
        const tab = {
            id: 890,
            label: 'Browse 1',
            params: {
                feed: 'online',
                service: 'test-service',
                page: 1,
            },
            items: [
                {
                    id: 21,
                    width: 500,
                    height: 500,
                    page: 1,
                    key: '1-21',
                    index: 0,
                    src: 'https://example.com/preview21.jpg',
                    preview: 'https://example.com/preview21.jpg',
                    original: 'https://example.com/original21.jpg',
                    type: 'image',
                    notFound: false,
                    containers: [
                        { id: 501, type: 'User', source: 'CivitAI', source_id: 'allowed-user' },
                    ],
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

        const manager = wrapper.findComponent({ name: 'ContainerBlacklistManager' });
        expect(manager.exists()).toBe(true);

        manager.vm.$emit('blacklists-changed', {
            action: 'created',
            blacklist: {
                id: 303,
                type: 'User',
                source: 'CivitAI',
                source_id: 'baraisgreat',
                referrer: 'https://civitai.com/user/baraisgreat',
                action_type: 'blacklist',
                blacklisted_at: '2026-03-18T01:54:00Z',
            },
        });

        await nextTick();

        const masonry = wrapper.findComponent({ name: 'MasonryGrid' });
        expect(masonry.exists()).toBe(true);

        masonry.vm.$emit('update:items', [
            ...tab.items,
            {
                id: 22,
                width: 500,
                height: 500,
                page: 'cursor-2',
                key: 'cursor-2-22',
                index: 1,
                src: 'https://example.com/preview22.jpg',
                preview: 'https://example.com/preview22.jpg',
                original: 'https://example.com/original22.jpg',
                type: 'image',
                notFound: false,
                containers: [
                    { id: 303, type: 'User', source: 'CivitAI', source_id: 'baraisgreat' },
                ],
            },
            {
                id: 23,
                width: 500,
                height: 500,
                page: 'cursor-2',
                key: 'cursor-2-23',
                index: 2,
                src: 'https://example.com/preview23.jpg',
                preview: 'https://example.com/preview23.jpg',
                original: 'https://example.com/original23.jpg',
                type: 'image',
                notFound: false,
                containers: [
                    { id: 601, type: 'User', source: 'CivitAI', source_id: 'allowed-later-user' },
                ],
            },
        ] satisfies FeedItem[]);

        await nextTick();

        expect((masonry.props('items') as FeedItem[]).map((item) => item.id)).toEqual([21, 23]);
    });
});
