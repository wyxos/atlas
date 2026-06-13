import { describe, it, expect, vi, beforeEach } from 'vitest';
import { ref, reactive, nextTick } from 'vue';
import { flushPromises } from '@vue/test-utils';
import { useFileViewerData } from './useFileViewerData';

const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    patch: vi.fn(),
};

vi.mock('axios', () => ({
    default: mockAxios,
}));

vi.mock('@/actions/App/Http/Controllers/FilesController', () => ({
    incrementSeen: {
        url: (id: number) => `/api/files/${id}/seen`,
    },
    show: {
        url: (id: number) => `/api/files/${id}`,
    },
}));

vi.mock('@/actions/App/Http/Controllers/FileSourceMetadataController', () => ({
    default: {
        url: (args: number | { file: number; target: string }) => {
            if (typeof args === 'number') {
                return `/api/files/${args}/source-metadata/detail`;
            }

            return `/api/files/${args.file}/source-metadata/${args.target}`;
        },
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

describe('useFileViewerData', () => {
    it('fetches file data when navigating after overlay fill completes', async () => {
        mockAxios.get
            .mockResolvedValueOnce({ data: { file: { id: 1, filename: 'one.jpg' } } })
            .mockResolvedValueOnce({ data: { file: { id: 2, filename: 'two.jpg' } } });

        const items = ref([{ id: 1 }, { id: 2 }] as any[]);
        const navigation = reactive({ currentItemIndex: 0 as number | null });
        const overlay = reactive({ fillComplete: false });
        const sheet = reactive({ isOpen: true });

        const { fileData, isLoadingFileData } = useFileViewerData({
            items,
            navigation,
            overlay,
            sheet,
        });

        // Sheet is open but overlay isn't filled yet -> no fetch, but we should not show stale data.
        expect(mockAxios.get).not.toHaveBeenCalled();
        expect(fileData.value).toBeNull();
        expect(isLoadingFileData.value).toBe(true);

        overlay.fillComplete = true;
        await nextTick();
        await flushPromises();

        expect(mockAxios.get).toHaveBeenCalledWith('/api/files/1');
        expect(fileData.value?.id).toBe(1);
        expect(isLoadingFileData.value).toBe(false);

        // Navigate to the next file. Overlay transitions set fillComplete=false first.
        overlay.fillComplete = false;
        navigation.currentItemIndex = 1;
        await nextTick();

        expect(fileData.value).toBeNull();
        expect(isLoadingFileData.value).toBe(true);

        overlay.fillComplete = true;
        await nextTick();
        await flushPromises();

        expect(mockAxios.get).toHaveBeenLastCalledWith('/api/files/2');
        expect(fileData.value?.id).toBe(2);
        expect(isLoadingFileData.value).toBe(false);
    });

    it('updates active sheet data and matching feed item after source media refresh', () => {
        const items = ref([{
            id: 1,
            src: 'https://images.example.test/old-preview.jpg',
            preview: 'https://images.example.test/old-preview.jpg',
            original: 'https://images.example.test/old-original.jpg',
            url: 'https://images.example.test/old-original.jpg',
        }] as any[]);
        const navigation = reactive({ currentItemIndex: 0 as number | null });
        const overlay = reactive({ fillComplete: true });
        const sheet = reactive({ isOpen: false });

        const { fileData, setFileData } = useFileViewerData({
            items,
            navigation,
            overlay,
            sheet,
        });

        setFileData({
            id: 1,
            url: 'https://images.example.test/fresh-original.png',
            file_url: 'https://images.example.test/fresh-original.png',
            preview_url: 'https://images.example.test/fresh-preview.jpg',
            previewed_count: 3,
            seen_count: 4,
            auto_blacklisted: false,
            blacklisted_at: null,
            downloaded: false,
            not_found: false,
        } as any);

        expect(fileData.value?.id).toBe(1);
        expect(items.value[0].src).toBe('https://images.example.test/fresh-preview.jpg');
        expect(items.value[0].preview).toBe('https://images.example.test/fresh-preview.jpg');
        expect(items.value[0].original).toBe('https://images.example.test/fresh-original.png');
        expect(items.value[0].originalUrl).toBe('https://images.example.test/fresh-original.png');
        expect(items.value[0].previewed_count).toBe(3);
        expect(items.value[0].seen_count).toBe(4);
    });

    it('refreshes source metadata and updates the sheet and feed item metadata cache', async () => {
        mockAxios.post.mockResolvedValueOnce({
            data: {
                file: {
                    id: 1,
                    source: 'CivitAI',
                    source_id: '133523267',
                    url: 'https://image.civitai.com/fresh.jpeg',
                    file_url: 'https://image.civitai.com/fresh.jpeg',
                    preview_url: 'https://image.civitai.com/fresh-preview.jpeg',
                    previewed_count: 3,
                    seen_count: 4,
                    auto_blacklisted: false,
                    blacklisted_at: null,
                    downloaded: false,
                    not_found: false,
                    metadata: {
                        payload: {
                            prompt: 'restored prompt',
                        },
                    },
                    listing_metadata: {
                        id: 133523267,
                        meta: {
                            prompt: 'restored prompt',
                        },
                    },
                    detail_metadata: null,
                    containers: [],
                    capabilities: {
                        refresh_source_media: false,
                        restore_listing_metadata: true,
                        restore_detail_metadata: true,
                        watch_source_and_refresh: false,
                        unwatch_source_account: false,
                    },
                },
            },
        });

        const items = ref([{
            id: 1,
            src: 'https://image.civitai.com/stale-preview.jpeg',
            preview: 'https://image.civitai.com/stale-preview.jpeg',
            original: 'https://image.civitai.com/stale.jpeg',
            url: 'https://image.civitai.com/stale.jpeg',
            metadata: null,
        }] as any[]);
        const navigation = reactive({ currentItemIndex: 0 as number | null });
        const overlay = reactive({ fillComplete: true });
        const sheet = reactive({ isOpen: false });

        const {
            fileData,
            isRefreshingSourceMetadata,
            sourceMetadataRefreshError,
            refreshSourceMetadata,
        } = useFileViewerData({
            items,
            navigation,
            overlay,
            sheet,
        });

        const restored = await refreshSourceMetadata(1);

        expect(mockAxios.post).toHaveBeenCalledWith('/api/files/1/source-metadata/detail');
        expect(restored?.id).toBe(1);
        expect(isRefreshingSourceMetadata.value).toBe(false);
        expect(sourceMetadataRefreshError.value).toBeNull();
        expect(fileData.value?.metadata?.payload.prompt).toBe('restored prompt');
        expect(items.value[0].metadata).toEqual({ prompt: 'restored prompt' });
        expect(items.value[0].listing_metadata.meta.prompt).toBe('restored prompt');
        expect(items.value[0].src).toBe('https://image.civitai.com/fresh-preview.jpeg');
    });

    it('automatically refreshes source metadata after a detail-capable sheet file loads', async () => {
        mockAxios.get.mockResolvedValueOnce({
            data: {
                file: {
                    id: 1,
                    source: 'CivitAI',
                    source_id: '133523267',
                    filename: 'one.jpg',
                    metadata: null,
                    capabilities: {
                        refresh_source_media: false,
                        restore_listing_metadata: true,
                        restore_detail_metadata: true,
                        watch_source_and_refresh: false,
                        unwatch_source_account: false,
                    },
                },
            },
        });
        mockAxios.post.mockResolvedValueOnce({
            data: {
                file: {
                    id: 1,
                    source: 'CivitAI',
                    source_id: '133523267',
                    filename: 'one.jpg',
                    preview_url: 'https://image.civitai.com/fresh-preview.jpeg',
                    previewed_count: 3,
                    seen_count: 4,
                    auto_blacklisted: false,
                    blacklisted_at: null,
                    downloaded: false,
                    not_found: false,
                    metadata: {
                        payload: {
                            prompt: 'auto restored prompt',
                        },
                    },
                    listing_metadata: {
                        id: 133523267,
                        meta: {
                            prompt: 'auto restored prompt',
                        },
                    },
                    detail_metadata: null,
                    containers: [],
                    capabilities: {
                        refresh_source_media: false,
                        restore_listing_metadata: true,
                        restore_detail_metadata: true,
                        watch_source_and_refresh: false,
                        unwatch_source_account: false,
                    },
                },
            },
        });

        const items = ref([{
            id: 1,
            src: 'https://image.civitai.com/stale-preview.jpeg',
            preview: 'https://image.civitai.com/stale-preview.jpeg',
            metadata: null,
        }] as any[]);
        const navigation = reactive({ currentItemIndex: 0 as number | null });
        const overlay = reactive({ fillComplete: true });
        const sheet = reactive({ isOpen: true });

        const { fileData, isRefreshingSourceMetadata } = useFileViewerData({
            items,
            navigation,
            overlay,
            sheet,
        });

        await flushPromises();

        expect(mockAxios.get).toHaveBeenCalledWith('/api/files/1');
        expect(mockAxios.post).toHaveBeenCalledWith('/api/files/1/source-metadata/detail');
        expect(isRefreshingSourceMetadata.value).toBe(false);
        expect(fileData.value?.metadata?.payload.prompt).toBe('auto restored prompt');
        expect(items.value[0].metadata).toEqual({ prompt: 'auto restored prompt' });
    });

    it('does not automatically refresh source metadata when the file lacks detail restore capability', async () => {
        mockAxios.get.mockResolvedValueOnce({
            data: {
                file: {
                    id: 1,
                    source: 'CivitAI',
                    source_id: '133523267',
                    filename: 'one.jpg',
                    capabilities: {
                        refresh_source_media: false,
                        restore_listing_metadata: true,
                        restore_detail_metadata: false,
                        watch_source_and_refresh: false,
                        unwatch_source_account: false,
                    },
                },
            },
        });

        const items = ref([{ id: 1 }] as any[]);
        const navigation = reactive({ currentItemIndex: 0 as number | null });
        const overlay = reactive({ fillComplete: true });
        const sheet = reactive({ isOpen: true });

        useFileViewerData({
            items,
            navigation,
            overlay,
            sheet,
        });

        await flushPromises();

        expect(mockAxios.get).toHaveBeenCalledWith('/api/files/1');
        expect(mockAxios.post).not.toHaveBeenCalled();
    });

    it('keeps source metadata refresh errors visible without changing the current sheet file', async () => {
        mockAxios.post.mockRejectedValueOnce({
            response: {
                data: {
                    message: 'Source metadata refresh failed.',
                },
            },
        });

        const items = ref([{ id: 1 }] as any[]);
        const navigation = reactive({ currentItemIndex: 0 as number | null });
        const overlay = reactive({ fillComplete: true });
        const sheet = reactive({ isOpen: false });

        const { fileData, sourceMetadataRefreshError, refreshSourceMetadata } = useFileViewerData({
            items,
            navigation,
            overlay,
            sheet,
        });

        const restored = await refreshSourceMetadata(1);

        expect(restored).toBeNull();
        expect(fileData.value).toBeNull();
        expect(sourceMetadataRefreshError.value).toBe('Source metadata refresh failed.');
    });

    it('refreshes spotify sheet data without assigning the spotify page as native playback', () => {
        const items = ref([{
            id: 1066450,
            src: 'https://i.scdn.co/image/old-cover',
            preview: 'https://i.scdn.co/image/old-cover',
            original: null,
            originalUrl: null,
            url: 'https://open.spotify.com/track/5P97xlvOl6IadKTLVId5ap',
            media_kind: 'audio',
            source: 'Spotify',
            mime_type: 'audio/spotify',
        }] as any[]);
        const navigation = reactive({ currentItemIndex: 0 as number | null });
        const overlay = reactive({ fillComplete: true });
        const sheet = reactive({ isOpen: false });

        const { setFileData } = useFileViewerData({
            items,
            navigation,
            overlay,
            sheet,
        });

        setFileData({
            id: 1066450,
            source: 'Spotify',
            source_id: '5P97xlvOl6IadKTLVId5ap',
            spotify_uri: 'spotify:track:5P97xlvOl6IadKTLVId5ap',
            url: 'https://open.spotify.com/track/5P97xlvOl6IadKTLVId5ap',
            file_url: null,
            disk_url: null,
            preview_url: 'https://i.scdn.co/image/fresh-cover',
            cover_url: 'https://i.scdn.co/image/fresh-cover',
            preview_file_url: null,
            poster_url: null,
            previewed_count: 3,
            seen_count: 4,
            auto_blacklisted: false,
            blacklisted_at: null,
            downloaded: false,
            not_found: false,
            mime_type: 'audio/spotify',
        } as any);

        expect(items.value[0].src).toBe('https://i.scdn.co/image/fresh-cover');
        expect(items.value[0].preview).toBe('https://i.scdn.co/image/fresh-cover');
        expect(items.value[0].original).toBe('https://i.scdn.co/image/fresh-cover');
        expect(items.value[0].originalUrl).toBe('https://i.scdn.co/image/fresh-cover');
        expect(items.value[0].url).toBe('https://open.spotify.com/track/5P97xlvOl6IadKTLVId5ap');
        expect(items.value[0].spotify_uri).toBe('spotify:track:5P97xlvOl6IadKTLVId5ap');
    });

    it('keeps sheet data pinned to an explicit file id while navigation changes', async () => {
        mockAxios.get.mockResolvedValueOnce({ data: { file: { id: 2, filename: 'two.jpg' } } });

        const items = ref([{ id: 1 }, { id: 2 }] as any[]);
        const navigation = reactive({ currentItemIndex: 0 as number | null });
        const overlay = reactive({ fillComplete: true });
        const sheet = reactive({ isOpen: true });
        const targetFileId = ref<number | null>(2);

        const { fileData } = useFileViewerData({
            items,
            navigation,
            overlay,
            sheet,
            targetFileId,
        });

        await flushPromises();

        expect(mockAxios.get).toHaveBeenCalledWith('/api/files/2');
        expect(fileData.value?.id).toBe(2);

        navigation.currentItemIndex = 1;
        await nextTick();
        await flushPromises();

        expect(mockAxios.get).toHaveBeenCalledTimes(1);
        expect(fileData.value?.id).toBe(2);
    });
});
