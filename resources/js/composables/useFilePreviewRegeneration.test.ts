import { flushPromises, mount } from '@vue/test-utils';
import { computed, defineComponent, h, nextTick, ref } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useFilePreviewRegeneration } from './useFilePreviewRegeneration';
import type { FeedItem } from '@/composables/useTabs';
import type { File } from '@/types/file';

const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
};

vi.mock('@/actions/App/Http/Controllers/FilesController', () => ({
    show: {
        url: (id: number) => `/api/files/${id}`,
    },
}));

Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => {});
});

describe('useFilePreviewRegeneration', () => {
    it('does not auto queue non-retryable failed preview states', async () => {
        const items = ref<FeedItem[]>([
            createPreviewRegenerationItem({
                preview_generation: {
                    status: 'failed',
                    can_retry: false,
                    message: 'Original file cannot be repaired.',
                },
            }),
        ]);

        mountPreviewRegenerationHarness(items);

        await flushPromises();

        expect(mockAxios.post).not.toHaveBeenCalled();
    });

    it('updates file data when the backend queues an original redownload', async () => {
        const items = ref<FeedItem[]>([createPreviewRegenerationItem()]);
        const setFileData = vi.fn((file: File) => {
            items.value = items.value.map((item) => (item.id === file.id
                ? {
                    ...item,
                    downloaded: file.downloaded,
                    preview_generation: file.preview_generation,
                }
                : item));
        });
        const file = createPreviewRegenerationFile({
            downloaded: false,
            path: null,
            preview_generation: null,
        });

        mockAxios.post.mockResolvedValueOnce({
            data: {
                queued: true,
                action: 'redownload_queued',
                file,
            },
        });

        mountPreviewRegenerationHarness(items, setFileData);
        await flushPromises();
        await nextTick();

        expect(mockAxios.post).toHaveBeenCalledTimes(1);
        expect(mockAxios.post).toHaveBeenCalledWith('/api/files/42/preview-assets');
        expect(setFileData).toHaveBeenCalledWith(file);
    });

    it('updates file data and stops auto queueing when preview generation is unavailable', async () => {
        const items = ref<FeedItem[]>([createPreviewRegenerationItem()]);
        const setFileData = vi.fn((file: File) => {
            items.value = items.value.map((item) => (item.id === file.id
                ? {
                    ...item,
                    preview_generation: file.preview_generation,
                }
                : item));
        });
        const file = createPreviewRegenerationFile({
            preview_generation: {
                status: 'unavailable',
                can_retry: false,
                message: 'Original file is missing and the remote source is no longer available.',
            },
        });

        mockAxios.post.mockResolvedValueOnce({
            data: {
                queued: false,
                action: 'unavailable',
                file,
            },
        });

        mountPreviewRegenerationHarness(items, setFileData);
        await flushPromises();
        await nextTick();
        await flushPromises();

        expect(mockAxios.post).toHaveBeenCalledTimes(1);
        expect(setFileData).toHaveBeenCalledWith(file);
    });
});

function mountPreviewRegenerationHarness(
    items: { value: FeedItem[] },
    setFileData: (file: File) => void = vi.fn(),
) {
    const Harness = defineComponent({
        setup() {
            useFilePreviewRegeneration({
                sessionItems: computed(() => items.value),
                setFileData,
            });

            return () => h('div');
        },
    });

    return mount(Harness);
}

function createPreviewRegenerationItem(overrides: Partial<FeedItem> = {}): FeedItem {
    return {
        id: 42,
        width: 500,
        height: 500,
        page: 1,
        key: '1-42',
        index: 0,
        src: '',
        preview: '',
        thumbnail: '',
        downloaded: true,
        preview_generation: {
            status: 'missing',
            can_retry: true,
            message: 'Preview has not been generated yet.',
        },
        ...overrides,
    } as FeedItem;
}

function createPreviewRegenerationFile(overrides: Partial<File> = {}): File {
    return {
        id: 42,
        source: 'CivitAI',
        source_id: null,
        filename: 'source.png',
        ext: 'png',
        size: 100,
        width: 500,
        height: 500,
        mime_type: 'image/png',
        hash: null,
        title: null,
        description: null,
        url: 'https://example.test/original.png',
        file_url: '/api/files/42/downloaded',
        referrer_url: 'https://example.test/page',
        path: 'downloads/aa/bb/source.png',
        absolute_path: null,
        absolute_preview_path: null,
        preview_url: null,
        cover_url: null,
        disk_url: '/api/files/42/downloaded',
        preview_file_url: null,
        poster_url: null,
        preview_generation: {
            status: 'missing',
            can_retry: true,
            message: 'Preview has not been generated yet.',
        },
        preview_path: null,
        poster_path: null,
        tags: null,
        parent_id: null,
        chapter: null,
        previewed_at: null,
        previewed_count: 0,
        seen_at: null,
        seen_count: 0,
        auto_blacklisted: false,
        blacklisted_at: null,
        downloaded: true,
        downloaded_at: '2026-05-28T12:00:00Z',
        imported_at: null,
        download_progress: 0,
        not_found: false,
        listing_metadata: null,
        detail_metadata: null,
        containers: [],
        source_access: null,
        capabilities: {
            refresh_source_media: false,
            watch_source_and_refresh: false,
            unwatch_source_account: false,
        },
        created_at: '2026-05-28T12:00:00Z',
        updated_at: '2026-05-28T12:00:00Z',
        ...overrides,
    };
}
