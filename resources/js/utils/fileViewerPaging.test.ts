import { describe, expect, it } from 'vitest';
import type { FeedItem } from '@/composables/useTabs';
import {
    calculateFileViewerPagingLayout,
    resolveFileViewerPagingMediaTarget,
} from './fileViewerPaging';

function createFeedItem(overrides: Partial<FeedItem> = {}): FeedItem {
    return {
        id: 1,
        width: 800,
        height: 600,
        page: 1,
        key: '1-1',
        index: 0,
        src: 'preview.jpg',
        ...overrides,
    };
}

describe('fileViewerPaging', () => {
    it('resolves image targets with preview and full-size sources', () => {
        const target = resolveFileViewerPagingMediaTarget(createFeedItem({
            id: 5,
            preview: 'preview.jpg',
            original: 'full.jpg',
        }));

        expect(target.mediaType).toBe('image');
        expect(target.previewSrc).toBe('preview.jpg');
        expect(target.fullSizeUrl).toBe('full.jpg');
        expect(target.overlayImage).toEqual({
            src: 'preview.jpg',
            srcset: undefined,
            sizes: undefined,
            alt: '5',
        });
        expect(target.initialFullSizeImage).toBeNull();
        expect(target.isLoading).toBe(true);
    });

    it('uses the preview image as the initial media for audio targets', () => {
        const target = resolveFileViewerPagingMediaTarget(createFeedItem({
            preview: 'audio-icon.jpg',
            original: 'audio.mp3',
            media_kind: 'audio',
        }));

        expect(target.mediaType).toBe('audio');
        expect(target.initialFullSizeImage).toBe('audio-icon.jpg');
        expect(target.fullSizeUrl).toBe('audio.mp3');
        expect(target.isAudio).toBe(true);
        expect(target.isLoading).toBe(false);
    });

    it('calculates centered filled layout with the sheet width applied', () => {
        const layout = calculateFileViewerPagingLayout({
            containerWidth: 1200,
            containerHeight: 900,
            borderWidth: 4,
            mediaWidth: 800,
            mediaHeight: 600,
            isFilled: true,
            fillComplete: true,
            isClosing: false,
            isSheetOpen: true,
        });

        expect(layout.availableWidth).toBe(872);
        expect(layout.availableHeight).toBe(892);
        expect(layout.imageSize).toEqual({ width: 800, height: 600 });
        expect(layout.centerPosition).toEqual({ top: 146, left: 36 });
    });
});
