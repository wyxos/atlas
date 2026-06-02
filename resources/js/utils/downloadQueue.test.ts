import { describe, expect, it, vi } from 'vitest';
import {
    canRestartDownloadQueueItem,
    canResumeDownloadQueueItem,
    DOWNLOAD_QUEUE_SOURCE_TAB_BRIDGE_ATTR,
    DOWNLOAD_QUEUE_SOURCE_TAB_MESSAGE_TYPE,
    downloadQueueItemMatchesSearch,
    getFailedDownloadQueueSourceUrls,
    openDownloadQueueSourceUrls,
} from '@/utils/downloadQueue';
import type { DownloadQueueItem } from '@/types/downloadQueue';

function makeItem(overrides: Partial<DownloadQueueItem> = {}): DownloadQueueItem {
    return {
        id: 1,
        status: 'failed',
        created_at: null,
        queued_at: null,
        started_at: null,
        finished_at: null,
        failed_at: null,
        percent: 45,
        error: null,
        ...overrides,
    };
}

describe('downloadQueue action availability', () => {
    it('uses backend resume flags for failed downloads', () => {
        expect(canResumeDownloadQueueItem(makeItem({ can_resume: true }))).toBe(true);
        expect(canResumeDownloadQueueItem(makeItem({ can_resume: false }))).toBe(false);
    });

    it('falls back to paused status when resume flags are absent', () => {
        expect(canResumeDownloadQueueItem(makeItem({ status: 'paused' }))).toBe(true);
        expect(canResumeDownloadQueueItem(makeItem({ status: 'failed' }))).toBe(false);
    });

    it('uses backend restart flags for restartable downloads', () => {
        expect(canRestartDownloadQueueItem(makeItem({ can_restart: true }))).toBe(true);
        expect(canRestartDownloadQueueItem(makeItem({ can_restart: false }))).toBe(false);
    });
});

describe('downloadQueue search', () => {
    it('matches exact terms from backend search text', () => {
        expect(downloadQueueItemMatchesSearch(makeItem({
            search_text: 'downloads/ab/cd/civitai-preview.png https://civitai.com/images/12345',
        }), 'civitai 12345')).toBe(true);
    });

    it('matches fuzzy subsequence terms without matching very short fuzzy tokens', () => {
        const item = makeItem({
            search_text: 'downloads/ab/cd/civitai-preview.png',
        });

        expect(downloadQueueItemMatchesSearch(item, 'cvta')).toBe(true);
        expect(downloadQueueItemMatchesSearch(item, 'ct')).toBe(false);
    });
});

describe('downloadQueue failed source URLs', () => {
    it('collects unique valid source pages for failed downloads only', () => {
        expect(getFailedDownloadQueueSourceUrls([
            makeItem({
                id: 1,
                status: 'failed',
                referrer_url: ' https://www.deviantart.com/artist/art/example ',
                url: 'https://expired-media.example.test/file.jpg?token=old',
            }),
            makeItem({
                id: 2,
                status: 'failed',
                referrer_url: 'https://www.deviantart.com/artist/art/example',
            }),
            makeItem({
                id: 3,
                status: 'failed',
                referrer_url: 'javascript:alert(1)',
            }),
            makeItem({
                id: 4,
                status: 'queued',
                referrer_url: 'https://www.deviantart.com/artist/art/queued',
            }),
        ])).toEqual([
            'https://www.deviantart.com/artist/art/example',
        ]);
    });

    it('falls back to opening every source URL from the page when the extension bridge is unavailable', () => {
        const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null);

        try {
            document.documentElement.removeAttribute(DOWNLOAD_QUEUE_SOURCE_TAB_BRIDGE_ATTR);

            openDownloadQueueSourceUrls([
                'https://www.deviantart.com/artist/art/first',
                'https://www.deviantart.com/artist/art/second',
            ]);

            expect(openSpy).toHaveBeenCalledTimes(2);
            expect(openSpy).toHaveBeenNthCalledWith(
                1,
                'https://www.deviantart.com/artist/art/first',
                '_blank',
                'noopener,noreferrer',
            );
            expect(openSpy).toHaveBeenNthCalledWith(
                2,
                'https://www.deviantart.com/artist/art/second',
                '_blank',
                'noopener,noreferrer',
            );
        } finally {
            openSpy.mockRestore();
        }
    });

    it('delegates all source URLs to the Atlas extension bridge when it is available', () => {
        const postMessageSpy = vi.spyOn(window, 'postMessage').mockImplementation(() => {});
        const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null);

        try {
            document.documentElement.setAttribute(DOWNLOAD_QUEUE_SOURCE_TAB_BRIDGE_ATTR, '1');

            openDownloadQueueSourceUrls([
                'https://www.deviantart.com/artist/art/first',
                'https://www.deviantart.com/artist/art/second',
            ]);

            expect(postMessageSpy).toHaveBeenCalledWith({
                type: DOWNLOAD_QUEUE_SOURCE_TAB_MESSAGE_TYPE,
                urls: [
                    'https://www.deviantart.com/artist/art/first',
                    'https://www.deviantart.com/artist/art/second',
                ],
            }, window.location.origin);
            expect(openSpy).not.toHaveBeenCalled();
        } finally {
            document.documentElement.removeAttribute(DOWNLOAD_QUEUE_SOURCE_TAB_BRIDGE_ATTR);
            postMessageSpy.mockRestore();
            openSpy.mockRestore();
        }
    });
});
