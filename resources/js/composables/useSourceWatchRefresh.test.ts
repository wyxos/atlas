import { describe, expect, it, beforeEach, vi } from 'vitest';
import { useSourceWatchRefresh } from './useSourceWatchRefresh';
import type { FeedItem } from './useTabs';
import type { File } from '@/types/file';

const toastSpy = vi.hoisted(() => vi.fn());

vi.mock('@/components/ui/toast/use-toast', () => ({
    useToast: () => toastSpy,
}));

vi.mock('@/components/toasts/StatusToast.vue', () => ({
    default: {},
}));

vi.mock('@/actions/App/Http/Controllers/FilesController', () => ({
    watchSourceAndRefreshMedia: {
        url: (id: number) => `/api/files/${id}/source-watch-refresh`,
    },
}));

const mockAxios = {
    post: vi.fn(),
};

Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

function makeItem(overrides: Partial<FeedItem> = {}): FeedItem {
    return {
        id: 42,
        width: 500,
        height: 500,
        page: 1,
        key: '1-42',
        index: 0,
        src: 'https://example.com/image-42.jpg',
        source: 'deviantart.com',
        ...overrides,
    };
}

function makeFile(overrides: Partial<File> = {}): File {
    return {
        id: 42,
        source: 'deviantart.com',
        source_id: 'deviation-id',
        filename: 'fresh.png',
        ext: 'png',
        size: 4096,
        width: null,
        height: null,
        mime_type: 'image/png',
        hash: null,
        title: null,
        description: null,
        url: 'https://images.example.test/fresh.png',
        file_url: 'https://images.example.test/fresh.png',
        referrer_url: 'https://www.deviantart.com/exampleartist/art/example',
        path: null,
        absolute_path: null,
        absolute_preview_path: null,
        preview_url: 'https://images.example.test/fresh-preview.jpg',
        cover_url: null,
        disk_url: null,
        preview_file_url: null,
        poster_url: null,
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
        downloaded: false,
        downloaded_at: null,
        imported_at: null,
        download_progress: 0,
        not_found: false,
        listing_metadata: null,
        detail_metadata: null,
        metadata: null,
        containers: [],
        capabilities: {
            refresh_source_media: true,
            watch_source_and_refresh: true,
        },
        created_at: '2026-01-01T00:00:00Z',
        updated_at: '2026-01-01T00:00:00Z',
        ...overrides,
    };
}

describe('useSourceWatchRefresh', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('posts source watch refresh and applies returned file data', async () => {
        const setFileData = vi.fn();
        const refreshedFile = makeFile();
        const actions = useSourceWatchRefresh({ setFileData });

        mockAxios.post.mockResolvedValueOnce({
            data: {
                supported: true,
                watched: true,
                changed: true,
                message: 'Source account watched and media refreshed.',
                file: refreshedFile,
            },
        });

        await actions.watchAndRefresh(makeItem(), 'exampleartist');

        expect(mockAxios.post).toHaveBeenCalledWith('/api/files/42/source-watch-refresh');
        expect(setFileData).toHaveBeenCalledWith(refreshedFile);
        expect(toastSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                props: expect.objectContaining({
                    title: 'Source media refreshed',
                    variant: 'success',
                }),
            }),
            expect.objectContaining({ id: 'source-watch-refresh-42' }),
        );
    });

    it('does not run for unsupported sources or disabled capabilities', async () => {
        const actions = useSourceWatchRefresh({ setFileData: vi.fn() });

        expect(actions.canWatchAndRefresh(makeItem({ source: 'civitai.com' }), 'exampleartist')).toBe(false);
        expect(actions.canWatchAndRefresh(makeItem({
            capabilities: {
                refresh_source_media: true,
                watch_source_and_refresh: false,
            },
        }), 'exampleartist')).toBe(false);

        await actions.watchAndRefresh(makeItem({ source: 'civitai.com' }), 'exampleartist');

        expect(mockAxios.post).not.toHaveBeenCalled();
    });
});
