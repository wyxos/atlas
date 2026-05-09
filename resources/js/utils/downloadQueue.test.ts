import { describe, expect, it } from 'vitest';
import {
    canRestartDownloadQueueItem,
    canResumeDownloadQueueItem,
    downloadQueueItemMatchesSearch,
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
