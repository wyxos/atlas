import { ref } from 'vue';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import type { FeedItem } from '@/composables/useTabs';
import { createTabContentV2Resolve } from './tabContentV2';

function createFeedItem(id: number): FeedItem {
    return {
        id,
        width: 512,
        height: 512,
        page: 1,
        key: `1-${id}`,
        index: id - 1,
        src: `https://example.test/${id}/preview.jpg`,
        preview: `https://example.test/${id}/preview.jpg`,
        original: `https://example.test/${id}/original.jpg`,
        originalUrl: `https://example.test/${id}/original.jpg`,
        type: 'image',
    } as FeedItem;
}

describe('tabContentV2 resolve', () => {
    beforeEach(() => {
        window.axios = {
            get: vi.fn(),
            post: vi.fn(),
            put: vi.fn(),
            patch: vi.fn(),
            delete: vi.fn(),
        } as typeof window.axios;
    });

    it('stores backend totalAvailable from the Vibe resolve fetch path', async () => {
        const items = ref<FeedItem[]>([]);
        const itemsBuckets = ref<Array<{ cursor: string | null; items: FeedItem[]; nextCursor: string | null; previousCursor: string | null }>>([]);
        const totalAvailable = ref<number | null>(null);
        const updateActiveTab = vi.fn();

        window.axios.get = vi.fn().mockResolvedValue({
            data: {
                items: [createFeedItem(1), createFeedItem(2)],
                nextPage: 2,
                previousPage: null,
                total: 381,
            },
        }) as typeof window.axios.get;

        const resolve = createTabContentV2Resolve({
            form: {
                getData: () => ({
                    feed: 'local',
                    limit: 20,
                    page: 1,
                    service: '',
                    serviceFilters: {},
                    source: 'all',
                    tab_id: 1,
                }),
            } as any,
            startPageToken: ref(1),
            totalAvailable,
            updateActiveTab,
            items,
            itemsBuckets,
            availableServices: ref([]),
            localService: ref(null),
            toast: {
                error: vi.fn(),
            },
        });

        const result = await resolve({ cursor: null, pageSize: 20 });

        expect(totalAvailable.value).toBe(381);
        expect(items.value).toHaveLength(2);
        expect(updateActiveTab).toHaveBeenCalledWith([
            expect.objectContaining({ id: 1 }),
            expect.objectContaining({ id: 2 }),
        ]);
        expect(result.nextPage).toBe('2');
        expect(result.items).toHaveLength(2);
    });
});
