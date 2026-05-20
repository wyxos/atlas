import { ref } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import type { VibeViewerItem } from '@wyxos/vibe';
import type { FeedItem } from '@/composables/useTabs';
import type { File } from '@/types/file';
import { createSyncedFileViewerData } from './tabContentV2FileSync';

function createVibeItem(item: FeedItem): VibeViewerItem {
    return {
        id: String(item.id),
        type: 'image',
        url: item.originalUrl ?? item.url ?? '',
        preview: {
            url: item.preview ?? item.src ?? '',
        },
        feedItem: item,
        fileId: item.id,
        page: item.page,
        key: item.key,
    };
}

describe('createSyncedFileViewerData', () => {
    it('patches only the matching Vibe item after source file data refreshes', () => {
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
        const otherItem = {
            id: 84,
            page: 2,
            key: '2-84',
            index: 1,
            src: 'https://example.com/other-preview.jpg',
            preview: 'https://example.com/other-preview.jpg',
            originalUrl: 'https://example.com/other-original.jpg',
        } as FeedItem;
        const vibeItem = createVibeItem(item);
        const otherVibeItem = createVibeItem(otherItem);
        const fileData = ref<File | null>(null);
        const isLoadingFileData = ref(false);
        const setFileData = vi.fn((file: File) => {
            item.src = file.preview_url ?? item.src;
            item.preview = file.preview_url ?? item.preview;
            item.originalUrl = file.file_url ?? file.url ?? item.originalUrl;
        });

        const synced = createSyncedFileViewerData({
            fileViewerData: {
                fileData,
                isLoadingFileData,
                setFileData,
            },
            getCurrentVibeItems: () => [vibeItem, otherVibeItem],
        });

        synced.value.setFileData({
            id: 42,
            url: 'https://example.com/new-original.jpg',
            preview_url: 'https://example.com/new-preview.jpg',
        } as File);

        expect(setFileData).toHaveBeenCalledTimes(1);
        expect(vibeItem).toMatchObject({
            id: '42',
            url: 'https://example.com/new-original.jpg',
            preview: {
                url: 'https://example.com/new-preview.jpg',
            },
            feedItem: item,
            fileId: 42,
            page: 2,
            key: '2-42',
        });
        expect(otherVibeItem).toMatchObject({
            id: '84',
            url: 'https://example.com/other-original.jpg',
            preview: {
                url: 'https://example.com/other-preview.jpg',
            },
        });
    });
});
