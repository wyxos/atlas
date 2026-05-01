import { ref } from 'vue';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import type { FeedItem } from '@/composables/useTabs';
import { createTabContentV2Resolve, mapFeedItemToVibeItem } from './tabContentV2';

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
        const totalAvailable = ref<number | null>(null);

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
            toast: {
                error: vi.fn(),
            },
        });

        const result = await resolve({ cursor: null, pageSize: 20 });

        expect(totalAvailable.value).toBe(381);
        expect(result.nextPage).toBe('2');
        expect(result.total).toBe(381);
        expect(result.items).toHaveLength(2);
    });

    it('resolves a params-only CivitAI startup state as a page one model-version query', async () => {
        window.axios.get = vi.fn().mockResolvedValue({
            data: {
                items: [createFeedItem(1)],
                nextPage: '20|1773762966318',
                previousPage: null,
                total: null,
            },
        }) as typeof window.axios.get;

        const resolve = createTabContentV2Resolve({
            form: {
                getData: () => ({
                    feed: 'online',
                    limit: 20,
                    page: 1,
                    service: 'civit-ai-images',
                    serviceFilters: {
                        modelId: 1894057,
                        modelVersionId: 2457413,
                    },
                    source: 'all',
                    tab_id: 44,
                }),
            } as any,
            startPageToken: ref(1),
            toast: {
                error: vi.fn(),
            },
        });

        const result = await resolve({ cursor: null, pageSize: 20 });

        const requestedUrl = vi.mocked(window.axios.get).mock.calls[0]?.[0] as string;

        expect(decodeURIComponent(requestedUrl)).toContain('/api/browse?feed=online');
        expect(decodeURIComponent(requestedUrl)).toContain('tab_id=44');
        expect(decodeURIComponent(requestedUrl)).toContain('page=1');
        expect(decodeURIComponent(requestedUrl)).toContain('limit=20');
        expect(decodeURIComponent(requestedUrl)).toContain('service=civit-ai-images');
        expect(decodeURIComponent(requestedUrl)).toContain('modelId=1894057');
        expect(decodeURIComponent(requestedUrl)).toContain('modelVersionId=2457413');
        expect(result.total).toBeNull();
        expect(result.items).toHaveLength(1);
    });

    it('throws browse request failures instead of converting them into empty final pages', async () => {
        const totalAvailable = ref<number | null>(381);
        const toast = {
            error: vi.fn(),
        };

        window.axios.get = vi.fn().mockRejectedValue({
            response: {
                data: {
                    message: 'Upstream failed',
                },
            },
        }) as typeof window.axios.get;

        const resolve = createTabContentV2Resolve({
            form: {
                getData: () => ({
                    feed: 'online',
                    limit: 20,
                    page: 1,
                    service: 'civit-ai-images',
                    serviceFilters: {},
                    source: 'all',
                    tab_id: 44,
                }),
            } as any,
            startPageToken: ref('400|1777443670108'),
            totalAvailable,
            toast,
        });

        await expect(resolve({ cursor: '400|1777443670108', pageSize: 20 })).rejects.toThrow('Upstream failed');

        expect(toast.error).toHaveBeenCalledWith('Upstream failed');
        expect(totalAvailable.value).toBeNull();
    });

    it('marks audio and video previews with explicit renderable media types', () => {
        const audioItem = mapFeedItemToVibeItem({
            ...createFeedItem(10),
            preview: '/api/files/10/icon',
            src: '/api/files/10/icon',
            original: '/api/files/10/downloaded',
            originalUrl: '/api/files/10/downloaded',
            media_kind: 'audio',
            mime_type: 'audio/mpeg',
        });

        const videoItem = mapFeedItemToVibeItem({
            ...createFeedItem(11),
            preview: '/api/files/11/preview',
            src: '/api/files/11/preview',
            original: '/api/files/11/downloaded',
            originalUrl: '/api/files/11/downloaded',
            media_kind: 'video',
            mime_type: 'video/mp4',
            type: 'video',
        });

        expect(audioItem.type).toBe('audio');
        expect(audioItem.preview).toBeUndefined();
        expect(audioItem.healthCheck).toMatchObject({
            kind: 'playback',
            url: '/api/files/10/downloaded',
        });

        expect(videoItem.type).toBe('video');
        expect(videoItem.preview).toMatchObject({
            url: '/api/files/11/preview',
            mediaType: 'video',
        });
        expect(videoItem.healthCheck).toBeUndefined();
    });

    it('adds playback health checks for generic file tiles that render with icon previews', () => {
        const fileItem = mapFeedItemToVibeItem({
            ...createFeedItem(12),
            preview: '/api/files/12/icon',
            src: '/api/files/12/icon',
            original: '/api/files/12/downloaded',
            originalUrl: '/api/files/12/downloaded',
            media_kind: 'file',
            mime_type: 'application/pdf',
        });

        expect(fileItem.type).toBe('other');
        expect(fileItem.preview).toMatchObject({
            url: '/api/files/12/icon',
            mediaType: 'image',
        });
        expect(fileItem.healthCheck).toMatchObject({
            kind: 'playback',
            url: '/api/files/12/downloaded',
        });
    });
});
