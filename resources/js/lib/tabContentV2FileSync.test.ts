import { computed, ref } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import type { VibeStatus } from '@wyxos/vibe';
import type { FeedItem } from '@/composables/useTabs';
import type { File } from '@/types/file';
import { createSyncedFileViewerData } from './tabContentV2FileSync';

function createStatus(): VibeStatus {
    return {
        activeIndex: 0,
        currentCursor: '2',
        errorMessage: null,
        fillCollectedCount: null,
        fillCompletedCalls: 0,
        fillCursor: null,
        fillDelayRemainingMs: null,
        fillLoadedCount: 0,
        fillMode: 'idle',
        fillProgress: null,
        fillTargetCalls: null,
        fillTargetCount: null,
        fillTotalCount: null,
        hasNextPage: true,
        hasPreviousPage: true,
        itemCount: 1,
        itemsRevision: 0,
        loadState: 'loaded',
        nextBoundaryLoadProgress: 0,
        nextCursor: '3',
        pageLoadingLocked: false,
        phase: 'idle',
        previousBoundaryLoadProgress: 0,
        previousCursor: '1',
        removedCount: 0,
        removedIds: [],
        removedRevision: 0,
        surfaceMode: 'list',
    };
}

describe('createSyncedFileViewerData', () => {
    it('remaps Vibe initial items after source file data refreshes', () => {
        const item = {
            id: 42,
            width: 640,
            height: 480,
            page: 2,
            key: '2-42',
            index: 0,
            src: 'https://example.com/old-preview.jpg',
            preview: 'https://example.com/old-preview.jpg',
            originalUrl: 'https://example.com/old-original.jpg',
        } as FeedItem;
        const fileData = ref<File | null>(null);
        const isLoadingFileData = ref(false);
        const setFileData = vi.fn((file: File) => {
            item.src = file.preview_url ?? item.src;
            item.preview = file.preview_url ?? item.preview;
            item.originalUrl = file.file_url ?? file.url ?? item.originalUrl;
        });
        const hydratedInitialState = ref();
        const masonryRenderKey = ref(4);

        const synced = createSyncedFileViewerData({
            activeIndex: ref(0),
            fallbackItems: computed(() => [item]),
            fileViewerData: {
                fileData,
                isLoadingFileData,
                setFileData,
            },
            getCurrentItems: () => [item],
            hydratedInitialState,
            masonryRenderKey,
            startPageToken: ref(1),
            vibeStatus: computed(createStatus),
        });

        synced.value.setFileData({
            id: 42,
            url: 'https://example.com/new-original.jpg',
            preview_url: 'https://example.com/new-preview.jpg',
        } as File);

        expect(setFileData).toHaveBeenCalledTimes(1);
        expect(masonryRenderKey.value).toBe(5);
        expect(hydratedInitialState.value).toMatchObject({
            cursor: '2',
            nextCursor: '3',
            previousCursor: '1',
            activeIndex: 0,
            items: [
                {
                    id: '42',
                    url: 'https://example.com/new-original.jpg',
                    preview: {
                        url: 'https://example.com/new-preview.jpg',
                    },
                },
            ],
        });
    });
});
