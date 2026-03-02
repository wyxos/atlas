import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { BadgeMatchResult } from './reaction-check-queue';
import type { ProgressEvent } from './download-progress-bus';

type BadgeStateCacheModule = typeof import('./badge-state-cache');

const TEST_URL = 'https://images.example.com/asset.png';

function emptyCheckResult(): BadgeMatchResult {
    return {
        exists: false,
        reaction: null,
        reactedAt: null,
        downloadedAt: null,
        blacklistedAt: null,
    };
}

async function loadModule(): Promise<BadgeStateCacheModule> {
    vi.resetModules();
    return import('./badge-state-cache');
}

describe('badge-state-cache', () => {
    beforeEach(() => {
        vi.resetModules();
    });

    it('keeps known reaction when check response is null during active local state', async () => {
        const cache = await loadModule();

        cache.persistBadgeState(TEST_URL, {
            exists: true,
            reaction: 'love',
            isDownloadLocked: true,
            status: 'downloading',
        });

        cache.persistBadgeCheckResult(TEST_URL, emptyCheckResult());

        expect(cache.getPersistedBadgeState(TEST_URL)).toMatchObject({
            exists: true,
            reaction: 'love',
            isDownloadLocked: true,
            status: 'downloading',
        });
    });

    it('allows null check response to clear reaction when state is not locally protected', async () => {
        const cache = await loadModule();

        cache.persistBadgeState(TEST_URL, {
            exists: false,
            reaction: 'like',
            isDownloadLocked: false,
            status: 'completed',
        });

        cache.persistBadgeCheckResult(TEST_URL, emptyCheckResult());

        expect(cache.getPersistedBadgeState(TEST_URL)).toMatchObject({
            exists: false,
            reaction: null,
            isDownloadLocked: false,
            status: 'completed',
        });
    });

    it('persists transfer progress updates and keeps last known status/percent when event omits fields', async () => {
        const cache = await loadModule();

        cache.persistBadgeState(TEST_URL, {
            fileId: 10,
            transferId: 25,
            status: 'queued',
            percent: 5,
            isDownloadLocked: true,
        });

        const progressOnlyEvent: ProgressEvent = {
            event: 'DownloadTransferProgressUpdated',
            fileId: 10,
            transferId: 25,
            sourceUrl: null,
            referrerUrl: null,
            status: null,
            percent: 42,
            reaction: null,
            reactedAt: undefined,
            downloadedAt: undefined,
            blacklistedAt: undefined,
            payload: {},
        };
        cache.persistDownloadProgressEvent(progressOnlyEvent);

        const statusOnlyEvent: ProgressEvent = {
            event: 'DownloadTransferQueued',
            fileId: 10,
            transferId: 25,
            sourceUrl: null,
            referrerUrl: null,
            status: 'downloading',
            percent: null,
            reaction: null,
            reactedAt: undefined,
            downloadedAt: undefined,
            blacklistedAt: undefined,
            payload: {},
        };
        cache.persistDownloadProgressEvent(statusOnlyEvent);

        expect(cache.getPersistedBadgeState(TEST_URL)).toMatchObject({
            fileId: 10,
            transferId: 25,
            status: 'downloading',
            percent: 42,
            isDownloadLocked: true,
        });
    });

    it('merges partial state updates and unlocks when transfer reaches terminal status', async () => {
        const cache = await loadModule();

        cache.persistBadgeState(TEST_URL, {
            exists: true,
            reaction: 'funny',
            fileId: 77,
            transferId: 88,
            status: 'downloading',
            percent: 75,
            isDownloadLocked: true,
        });

        cache.persistBadgeState(TEST_URL, {
            downloadedAt: '2026-01-01T12:00:00Z',
        });

        const completedEvent: ProgressEvent = {
            event: 'DownloadTransferProgressUpdated',
            fileId: null,
            transferId: 88,
            sourceUrl: null,
            referrerUrl: null,
            status: 'completed',
            percent: 100,
            reaction: null,
            reactedAt: undefined,
            downloadedAt: undefined,
            blacklistedAt: undefined,
            payload: {},
        };
        cache.persistDownloadProgressEvent(completedEvent);

        expect(cache.getPersistedBadgeState(TEST_URL)).toMatchObject({
            exists: true,
            reaction: 'funny',
            fileId: 77,
            transferId: 88,
            status: 'completed',
            percent: 100,
            downloadedAt: '2026-01-01T12:00:00Z',
            isDownloadLocked: false,
        });
    });

    it('merges reacted/downloaded/blacklisted timestamps from progress events', async () => {
        const cache = await loadModule();

        cache.persistBadgeState(TEST_URL, {
            fileId: 51,
            transferId: 61,
            status: 'downloading',
            percent: 40,
            isDownloadLocked: true,
        });

        const event: ProgressEvent = {
            event: 'DownloadTransferProgressUpdated',
            fileId: 51,
            transferId: 61,
            sourceUrl: TEST_URL,
            referrerUrl: null,
            status: 'completed',
            percent: 100,
            reaction: 'funny',
            reactedAt: '2026-03-01T08:15:00Z',
            downloadedAt: '2026-03-01T08:20:00Z',
            blacklistedAt: null,
            payload: {},
        };
        cache.persistDownloadProgressEvent(event);

        expect(cache.getPersistedBadgeState(TEST_URL)).toMatchObject({
            exists: true,
            reaction: 'funny',
            reactedAt: '2026-03-01T08:15:00Z',
            downloadedAt: '2026-03-01T08:20:00Z',
            blacklistedAt: null,
            status: 'completed',
            percent: 100,
            isDownloadLocked: false,
        });
    });

    it('maps progress updates by source url when ids are not yet known', async () => {
        const cache = await loadModule();

        const event: ProgressEvent = {
            event: 'DownloadTransferQueued',
            fileId: null,
            transferId: null,
            sourceUrl: `${TEST_URL}#fragment`,
            referrerUrl: null,
            status: 'queued',
            percent: 12,
            reaction: null,
            reactedAt: undefined,
            downloadedAt: undefined,
            blacklistedAt: undefined,
            payload: {},
        };
        cache.persistDownloadProgressEvent(event);

        expect(cache.getPersistedBadgeState(TEST_URL)).toMatchObject({
            status: 'queued',
            percent: 12,
            isDownloadLocked: true,
        });
    });

    it('persists reaction from progress event and marks state as existing for restore', async () => {
        const cache = await loadModule();

        const event: ProgressEvent = {
            event: 'DownloadTransferQueued',
            fileId: 22,
            transferId: 33,
            sourceUrl: TEST_URL,
            referrerUrl: null,
            status: 'queued',
            percent: 7,
            reaction: 'love',
            reactedAt: undefined,
            downloadedAt: undefined,
            blacklistedAt: undefined,
            payload: {},
        };
        cache.persistDownloadProgressEvent(event);

        expect(cache.getPersistedBadgeState(TEST_URL)).toMatchObject({
            exists: true,
            reaction: 'love',
            fileId: 22,
            transferId: 33,
            status: 'queued',
            percent: 7,
        });
    });

    it('does not clobber a known reaction when later progress events have null reaction', async () => {
        const cache = await loadModule();

        cache.persistBadgeState(TEST_URL, {
            exists: true,
            reaction: 'like',
            fileId: 10,
            transferId: 25,
            status: 'downloading',
            percent: 30,
            isDownloadLocked: true,
        });

        const event: ProgressEvent = {
            event: 'DownloadTransferProgressUpdated',
            fileId: 10,
            transferId: 25,
            sourceUrl: null,
            referrerUrl: null,
            status: 'downloading',
            percent: 55,
            reaction: null,
            reactedAt: undefined,
            downloadedAt: undefined,
            blacklistedAt: undefined,
            payload: {},
        };
        cache.persistDownloadProgressEvent(event);

        expect(cache.getPersistedBadgeState(TEST_URL)).toMatchObject({
            exists: true,
            reaction: 'like',
            status: 'downloading',
            percent: 55,
        });
    });
});
