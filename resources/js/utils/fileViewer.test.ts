import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    clearFileViewerPreloadCache,
    preloadImage,
    resolveFileViewerMediaType,
} from './fileViewer';
import type { FeedItem } from '@/composables/useTabs';

describe('fileViewer', () => {
    beforeEach(() => {
        clearFileViewerPreloadCache({ abortPending: true });
    });

    afterEach(() => {
        clearFileViewerPreloadCache({ abortPending: true });
        vi.restoreAllMocks();
    });

    it('treats application/mp4 items as video', () => {
        const item: FeedItem = {
            id: 2660650,
            width: 640,
            height: 360,
            page: 1,
            key: '1-2660650',
            index: 0,
            src: '/api/files/2660650/icon',
            mime_type: 'application/mp4',
        };

        expect(resolveFileViewerMediaType(item)).toBe('video');
    });

    it('reuses the shared image preload for concurrent and later calls', async () => {
        const originalImage = window.Image;
        const requestedUrls: string[] = [];

        class MockImage {
            onload: null | (() => void) = null;
            onerror: null | (() => void) = null;
            naturalWidth = 1600;
            naturalHeight = 900;

            set src(value: string) {
                requestedUrls.push(value);
                queueMicrotask(() => {
                    this.onload?.();
                });
            }
        }

        Object.defineProperty(window, 'Image', {
            value: MockImage,
            configurable: true,
            writable: true,
        });

        try {
            const url = 'https://example.com/file.jpg';
            const [first, second] = await Promise.all([
                preloadImage(url),
                preloadImage(url),
            ]);

            expect(first).toEqual({ width: 1600, height: 900 });
            expect(second).toEqual({ width: 1600, height: 900 });
            expect(requestedUrls).toEqual([url]);

            const third = await preloadImage(url);

            expect(third).toEqual({ width: 1600, height: 900 });
            expect(requestedUrls).toEqual([url]);
        } finally {
            Object.defineProperty(window, 'Image', {
                value: originalImage,
                configurable: true,
                writable: true,
            });
        }
    });
});
