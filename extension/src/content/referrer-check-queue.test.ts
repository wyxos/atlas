import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetStoredOptions = vi.fn();
const mockRequestQueuedReferrerCheckViaRuntime = vi.fn();

vi.mock('../atlas-options', () => ({
    getStoredOptions: mockGetStoredOptions,
}));

vi.mock('./atlas-request-log', () => ({
    atlasLoggedRuntimeRequest: vi.fn((_: string, __: string, ___: unknown, run: () => Promise<unknown>) => run()),
}));

vi.mock('../atlas-runtime-request', () => ({
    requestQueuedReferrerCheckViaRuntime: mockRequestQueuedReferrerCheckViaRuntime,
}));

describe('referrer-check-queue', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();

        mockGetStoredOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            siteCustomizations: [],
        });
        mockRequestQueuedReferrerCheckViaRuntime.mockResolvedValue({
            ok: true,
            status: 200,
            payload: {
                exists: true,
                reaction: 'like',
            },
        });
    });

    it('strips configured query params before delegating referrer checks to the background queue', async () => {
        const queue = await import('./referrer-check-queue');
        await queue.enqueueReferrerCheck('https://domain.com/?id=123&tag=blue+sky', ['tag', 'tags']);

        expect(mockRequestQueuedReferrerCheckViaRuntime).toHaveBeenCalledWith({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            normalizedReferrerUrl: 'https://domain.com/?id=123',
        });
    });

    it('stores successful runtime responses in the local synchronous mirror cache', async () => {
        const queue = await import('./referrer-check-queue');
        const result = await queue.enqueueReferrerCheck('https://example.com/gallery?tab=1#section');

        expect(result).toEqual({
            exists: true,
            reaction: 'like',
            reactedAt: null,
            downloadedAt: null,
            blacklistedAt: null,
        });
        expect(queue.getCachedReferrerCheck('https://example.com/gallery?tab=1#section')).toEqual(result);
    });

    it('falls back to the civitai.com referrer alias when a civitai.red check misses', async () => {
        mockRequestQueuedReferrerCheckViaRuntime.mockImplementation(({ normalizedReferrerUrl }) => Promise.resolve({
            ok: true,
            status: 200,
            payload: normalizedReferrerUrl === 'https://civitai.com/images/9101001'
                ? {
                    exists: true,
                    reaction: 'love',
                }
                : {
                    exists: false,
                    reaction: null,
                },
        }));

        const queue = await import('./referrer-check-queue');
        const result = await queue.enqueueReferrerCheck('https://civitai.red/images/9101001');

        expect(result.reaction).toBe('love');
        expect(mockRequestQueuedReferrerCheckViaRuntime).toHaveBeenCalledWith(expect.objectContaining({
            normalizedReferrerUrl: 'https://civitai.red/images/9101001',
        }));
        expect(mockRequestQueuedReferrerCheckViaRuntime).toHaveBeenCalledWith(expect.objectContaining({
            normalizedReferrerUrl: 'https://civitai.com/images/9101001',
        }));
        expect(queue.getCachedReferrerCheck('https://civitai.red/images/9101001')).toEqual(result);
        expect(queue.getCachedReferrerCheck('https://civitai.com/images/9101001')).toEqual(result);
    });

    it('supports optimistic local cache updates for synchronous anchor lookups', async () => {
        const queue = await import('./referrer-check-queue');

        queue.upsertReferrerCheckCache('https://example.com/gallery?tab=1#section', {
            exists: true,
            reaction: 'funny',
            downloadedAt: '2026-03-21T00:00:00Z',
        });

        expect(queue.getCachedReferrerCheck('https://example.com/gallery?tab=1#section')).toEqual({
            exists: true,
            reaction: 'funny',
            reactedAt: null,
            downloadedAt: '2026-03-21T00:00:00Z',
            blacklistedAt: null,
        });
    });

    it('does not poison the local mirror cache when the background runtime queue is unavailable', async () => {
        mockRequestQueuedReferrerCheckViaRuntime.mockResolvedValue(null);

        const queue = await import('./referrer-check-queue');
        const result = await queue.enqueueReferrerCheck('https://example.com/offline#section');

        expect(result).toEqual({
            exists: false,
            reaction: null,
            reactedAt: null,
            downloadedAt: null,
            blacklistedAt: null,
        });
        expect(queue.getCachedReferrerCheck('https://example.com/offline#section')).toBeNull();
    });
});
