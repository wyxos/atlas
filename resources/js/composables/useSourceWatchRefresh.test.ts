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
    refreshSourceMedia: {
        url: (id: number) => `/api/files/${id}/refresh-source-media`,
    },
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

function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((resolvePromise) => {
        resolve = resolvePromise;
    });

    return { promise, resolve };
}

describe('useSourceWatchRefresh', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockAxios.post.mockReset();
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

        await actions.watchAndRefresh(makeItem(), 'posted-response-artist');

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

    it('posts source media refresh and applies returned file data', async () => {
        const setFileData = vi.fn();
        const refreshedFile = makeFile({
            url: 'https://images.example.test/after-refresh.png',
            preview_url: 'https://images.example.test/after-refresh-preview.jpg',
            source_access: {
                provider: 'deviantart',
                access_type: 'watchers',
                has_access: false,
                requires_watch: true,
                can_unwatch: false,
            },
        });
        const actions = useSourceWatchRefresh({ setFileData });

        mockAxios.post.mockResolvedValueOnce({
            data: {
                changed: true,
                message: 'Source media refreshed.',
                file: refreshedFile,
            },
        });

        await actions.refreshSourceMedia(makeItem({
            source_access: null,
            capabilities: {
                refresh_source_media: true,
                watch_source_and_refresh: true,
                unwatch_source_account: true,
            },
        }));

        expect(mockAxios.post).toHaveBeenCalledWith('/api/files/42/refresh-source-media');
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

    it('offers watch refresh for supported source accounts until they are known to be watched', () => {
        const actions = useSourceWatchRefresh({ setFileData: vi.fn() });

        expect(actions.canWatchAndRefresh(makeItem(), 'exampleartist')).toBe(true);
        expect(actions.canWatchAndRefresh(makeItem({
            source_access: {
                provider: 'deviantart',
                access_type: null,
                has_access: true,
                requires_watch: false,
                can_unwatch: false,
            },
        }), 'exampleartist')).toBe(true);
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

    it('keeps a successfully watched account available to a remounted composable instance', async () => {
        const firstInstance = useSourceWatchRefresh({ setFileData: vi.fn() });
        const item = makeItem({
            id: 142,
            source_access: {
                provider: 'deviantart',
                access_type: null,
                has_access: true,
                requires_watch: false,
                can_unwatch: false,
            },
        });

        mockAxios.post.mockResolvedValueOnce({
            data: {
                supported: true,
                watched: true,
                changed: false,
                message: 'Source account watched. Source media is already current.',
            },
        });

        await firstInstance.watchAndRefresh(item, 'remount-artist');

        const remountedInstance = useSourceWatchRefresh({ setFileData: vi.fn() });

        expect(remountedInstance.canWatchAndRefresh(item, 'remount-artist')).toBe(false);
        expect(remountedInstance.canUnwatchSourceAccount(item, 'remount-artist')).toBe(true);
    });

    it('tracks the pending operation type for refresh, watch, and unwatch actions', async () => {
        const actions = useSourceWatchRefresh({ setFileData: vi.fn() });

        const refreshItem = makeItem({
            id: 241,
            capabilities: {
                refresh_source_media: true,
                watch_source_and_refresh: true,
                unwatch_source_account: true,
            },
        });
        const refreshResponse = deferred<{ data: { changed: boolean } }>();
        mockAxios.post.mockReturnValueOnce(refreshResponse.promise);

        const refreshRequest = actions.refreshSourceMedia(refreshItem);
        expect(actions.pendingOperationFor(refreshItem)).toBe('refresh');
        refreshResponse.resolve({ data: { changed: false } });
        await refreshRequest;
        expect(actions.pendingOperationFor(refreshItem)).toBeNull();

        const watchItem = makeItem({ id: 242 });
        const watchResponse = deferred<{ data: { supported: boolean; watched: boolean; changed: boolean } }>();
        mockAxios.post.mockReturnValueOnce(watchResponse.promise);

        const watchRequest = actions.watchAndRefresh(watchItem, 'pending-operation-artist');
        expect(actions.pendingOperationFor(watchItem)).toBe('watch');
        watchResponse.resolve({
            data: {
                supported: true,
                watched: true,
                changed: false,
            },
        });
        await watchRequest;
        expect(actions.pendingOperationFor(watchItem)).toBeNull();

        const unwatchItem = makeItem({
            id: 243,
            source_access: {
                provider: 'deviantart',
                access_type: 'watchers',
                has_access: true,
                requires_watch: false,
                can_unwatch: true,
            },
        });
        const unwatchResponse = deferred<{ data: { supported: boolean; unwatched: boolean } }>();
        mockAxios.post.mockReturnValueOnce(unwatchResponse.promise);

        const unwatchRequest = actions.unwatchSourceAccount(unwatchItem, 'pending-unwatch-artist');
        expect(actions.pendingOperationFor(unwatchItem)).toBe('unwatch');
        unwatchResponse.resolve({
            data: {
                supported: true,
                unwatched: true,
            },
        });
        await unwatchRequest;
        expect(actions.pendingOperationFor(unwatchItem)).toBeNull();
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
