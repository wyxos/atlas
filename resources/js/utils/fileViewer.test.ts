import { describe, expect, it } from 'vitest';
import { resolveFileViewerMediaType } from './fileViewer';
import type { FeedItem } from '@/composables/useTabs';

describe('fileViewer', () => {
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
});
