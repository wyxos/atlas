import { describe, expect, it } from 'vitest';
import {
    canRestartDownloadQueueItem,
    canResumeDownloadQueueItem,
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
