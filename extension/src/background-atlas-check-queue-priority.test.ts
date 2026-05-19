import { beforeEach, describe, expect, it, vi } from 'vitest';

type TestMatchResult = {
    exists: boolean;
};

function emptyTestResult(): TestMatchResult {
    return {
        exists: false,
    };
}

async function flushPromises(): Promise<void> {
    await Promise.resolve();
    await Promise.resolve();
}

describe('background atlas check queue priority controls', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    it('returns an empty cache-only miss without hashing or fetching', async () => {
        const digestMock = vi.fn().mockResolvedValue(new Uint8Array(32).buffer);
        const fetchMock = vi.fn();
        vi.stubGlobal('crypto', {
            ...crypto,
            subtle: {
                digest: digestMock,
            },
        });
        vi.stubGlobal('fetch', fetchMock);

        const { enqueueGlobalReferrerCheck } = await import('./background-atlas-check-queue');

        await expect(enqueueGlobalReferrerCheck({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            normalizedReferrerUrl: 'https://example.com/post#image-1',
            cacheOnly: true,
        })).resolves.toEqual({
            ok: true,
            status: 204,
            payload: {
                exists: false,
                reaction: null,
                reactedAt: null,
                downloadedAt: null,
                blacklistedAt: null,
            },
        });
        expect(digestMock).not.toHaveBeenCalled();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('lets active work evict lower-priority pending work when the queue is full', async () => {
        vi.useFakeTimers();
        vi.stubGlobal('crypto', {
            ...crypto,
            subtle: {
                digest: vi.fn().mockResolvedValue(new Uint8Array(32).buffer),
            },
        });
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
            matches: [
                {
                    request_id: 'req-0',
                    exists: true,
                },
            ],
        }), { status: 200 })));

        const { createBackgroundAtlasCheckQueue } = await import('./background-atlas-check-queue');
        const queue = createBackgroundAtlasCheckQueue<TestMatchResult>({
            batchDelayMs: 700,
            maxBatchSize: 50,
            maxPendingItems: 1,
            maxResultCacheEntries: 10,
            maxHashCacheEntries: 10,
            cacheTtlMs: 60_000,
            endpointPath: '/api/extension/test-checks',
            buildRequestItem: (requestId, entry) => ({
                request_id: requestId,
                input_hash: entry.inputHash,
            }),
            emptyPayload: emptyTestResult,
            parsePayloadItem: (row) => ({
                exists: row.exists === true,
            }),
        });

        const lowPriority = queue.enqueue({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            normalizedInput: 'low-priority',
            priority: 0,
        });
        await flushPromises();

        const activePriority = queue.enqueue({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            normalizedInput: 'active-priority',
            priority: 2,
        });
        await flushPromises();

        await expect(lowPriority).resolves.toEqual({
            ok: true,
            status: 204,
            payload: {
                exists: false,
            },
        });

        await vi.advanceTimersByTimeAsync(700);
        await expect(activePriority).resolves.toEqual({
            ok: true,
            status: 200,
            payload: {
                exists: true,
            },
        });
    });
});
