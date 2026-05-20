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
    unwatchSourceAccount: {
        url: (id: number) => `/api/files/${id}/source-unwatch`,
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
        source_access: {
            provider: 'deviantart',
            access_type: 'watchers',
            has_access: false,
            requires_watch: true,
            can_unwatch: false,
        },
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
            unwatch_source_account: true,
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

    it('only offers watch refresh when watcher access is missing', () => {
        const actions = useSourceWatchRefresh({ setFileData: vi.fn() });

        expect(actions.canWatchAndRefresh(makeItem(), 'exampleartist')).toBe(true);
        expect(actions.canWatchAndRefresh(makeItem({
            source_access: {
                provider: 'deviantart',
                access_type: 'watchers',
                has_access: true,
                requires_watch: false,
                can_unwatch: true,
            },
        }), 'exampleartist')).toBe(false);
        expect(actions.canWatchAndRefresh(makeItem({
            listing_metadata: {
                premium_folder_data: {
                    type: 'watchers',
                    has_access: false,
                },
            },
            source_access: {
                provider: 'deviantart',
                access_type: 'watchers',
                has_access: true,
                requires_watch: false,
                can_unwatch: true,
            },
        }), 'exampleartist')).toBe(true);
    });

    it('does not run when capabilities are disabled', async () => {
        const actions = useSourceWatchRefresh({ setFileData: vi.fn() });

        expect(actions.canWatchAndRefresh(makeItem({
            capabilities: {
                refresh_source_media: true,
                watch_source_and_refresh: false,
                unwatch_source_account: true,
            },
        }), 'exampleartist')).toBe(false);

        await actions.watchAndRefresh(makeItem({
            capabilities: {
                refresh_source_media: true,
                watch_source_and_refresh: false,
                unwatch_source_account: true,
            },
        }), 'exampleartist');

        expect(mockAxios.post).not.toHaveBeenCalled();
    });

    it('unwatches source accounts when watcher access is present', async () => {
        const setFileData = vi.fn();
        const refreshedFile = makeFile({
            listing_metadata: {
                premium_folder_data: {
                    type: 'watchers',
                    has_access: false,
                },
            },
            source_access: {
                provider: 'deviantart',
                access_type: 'watchers',
                has_access: false,
                requires_watch: true,
                can_unwatch: false,
            },
        });
        const actions = useSourceWatchRefresh({ setFileData });
        const item = makeItem({
            source_access: {
                provider: 'deviantart',
                access_type: 'watchers',
                has_access: true,
                requires_watch: false,
                can_unwatch: true,
            },
        });

        mockAxios.post.mockResolvedValueOnce({
            data: {
                supported: true,
                unwatched: true,
                message: 'Source account unwatched.',
                file: refreshedFile,
            },
        });

        expect(actions.canUnwatchSourceAccount(item, 'exampleartist')).toBe(true);

        await actions.unwatchSourceAccount(item, 'exampleartist');

        expect(mockAxios.post).toHaveBeenCalledWith('/api/files/42/source-unwatch');
        expect(setFileData).toHaveBeenCalledWith(refreshedFile);
        expect(toastSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                props: expect.objectContaining({
                    title: 'Source account unwatched',
                    variant: 'success',
                }),
            }),
            expect.objectContaining({ id: 'source-watch-refresh-42' }),
        );
    });
});
