import { beforeEach, describe, expect, it, vi } from 'vitest';

function createMatchResponse(items: Array<{ request_id?: string; reaction?: string | null; exists?: boolean }>) {
    return new Response(JSON.stringify({
        matches: items,
    }), { status: 200 });
}

async function flushPromises(): Promise<void> {
    await Promise.resolve();
    await Promise.resolve();
}

describe('background-atlas-check-queue', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    it('coalesces concurrent badge checks for the same url into one fetch', async () => {
        const fetchMock = vi.fn().mockImplementation((_endpoint: string, init?: RequestInit) => {
            const body = JSON.parse(String(init?.body ?? '{}')) as {
                items?: Array<{ request_id?: string }>;
            };

            return Promise.resolve(createMatchResponse([
                {
                    request_id: body.items?.[0]?.request_id,
                    exists: true,
                    reaction: 'like',
                },
            ]));
        });
        vi.stubGlobal('fetch', fetchMock);

        const { enqueueGlobalBadgeCheck } = await import('./background-atlas-check-queue');

        const first = enqueueGlobalBadgeCheck({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            normalizedMediaUrl: 'https://cdn.example.com/image.jpg',
        });
        const second = enqueueGlobalBadgeCheck({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            normalizedMediaUrl: 'https://cdn.example.com/image.jpg',
        });

        expect(fetchMock).not.toHaveBeenCalled();

        await vi.waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(1);
        });
        const [firstResponse, secondResponse] = await Promise.all([first, second]);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(firstResponse).toEqual({
            ok: true,
            status: 200,
            payload: {
                exists: true,
                reaction: 'like',
                reactedAt: null,
                downloadedAt: null,
                blacklistedAt: null,
            },
        });
        expect(secondResponse).toEqual(firstResponse);
    });

    it('coalesces concurrent referrer checks for the same url into one fetch', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn().mockImplementation((_endpoint: string, init?: RequestInit) => {
            const body = JSON.parse(String(init?.body ?? '{}')) as {
                items?: Array<{ request_id?: string }>;
            };

            return Promise.resolve(createMatchResponse([
                {
                    request_id: body.items?.[0]?.request_id,
                    exists: true,
                    reaction: 'funny',
                },
            ]));
        });
        vi.stubGlobal('fetch', fetchMock);

        const { enqueueGlobalReferrerCheck } = await import('./background-atlas-check-queue');

        const first = enqueueGlobalReferrerCheck({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            normalizedReferrerUrl: 'https://example.com/post#image-1',
        });
        const second = enqueueGlobalReferrerCheck({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            normalizedReferrerUrl: 'https://example.com/post#image-1',
        });

        await flushPromises();
        await vi.advanceTimersByTimeAsync(12);
        const [firstResponse, secondResponse] = await Promise.all([first, second]);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(firstResponse.payload.reaction).toBe('funny');
        expect(secondResponse).toEqual(firstResponse);
    });

    it('flushes short badge batches on the debounce timer and long batches immediately at the threshold', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn().mockImplementation((_endpoint: string, init?: RequestInit) => {
            const body = JSON.parse(String(init?.body ?? '{}')) as {
                items?: Array<{ request_id?: string }>;
            };

            return Promise.resolve(createMatchResponse(
                (body.items ?? []).map((item) => ({
                    request_id: item.request_id,
                    exists: false,
                    reaction: null,
                })),
            ));
        });
        vi.stubGlobal('fetch', fetchMock);

        const { enqueueGlobalBadgeCheck } = await import('./background-atlas-check-queue');

        const smallBatch = Array.from({ length: 19 }, (_, index) => enqueueGlobalBadgeCheck({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            normalizedMediaUrl: `https://cdn.example.com/small-${index}.jpg`,
        }));

        expect(fetchMock).not.toHaveBeenCalled();
        await flushPromises();
        await vi.advanceTimersByTimeAsync(9);
        expect(fetchMock).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);
        await Promise.all(smallBatch);
        expect(fetchMock).toHaveBeenCalledTimes(1);

        fetchMock.mockClear();

        const largeBatch = Array.from({ length: 20 }, (_, index) => enqueueGlobalBadgeCheck({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            normalizedMediaUrl: `https://cdn.example.com/large-${index}.jpg`,
        }));

        await flushPromises();
        await vi.waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(1);
        });
        await Promise.all(largeBatch);
    });

    it('caches successful empty matches but retries after network failures', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(createMatchResponse([]))
            .mockRejectedValueOnce(new Error('offline'))
            .mockResolvedValueOnce(createMatchResponse([]));
        vi.stubGlobal('fetch', fetchMock);

        const { enqueueGlobalBadgeCheck } = await import('./background-atlas-check-queue');

        const first = enqueueGlobalBadgeCheck({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            normalizedMediaUrl: 'https://cdn.example.com/cached.jpg',
        });
        await vi.waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(1);
        });
        expect((await first).payload.exists).toBe(false);

        const cached = await enqueueGlobalBadgeCheck({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            normalizedMediaUrl: 'https://cdn.example.com/cached.jpg',
        });
        expect(cached.payload.exists).toBe(false);
        expect(fetchMock).toHaveBeenCalledTimes(1);

        const failed = enqueueGlobalBadgeCheck({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token-two',
            normalizedMediaUrl: 'https://cdn.example.com/failure.jpg',
        });
        await vi.waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(2);
        });
        expect((await failed).ok).toBe(false);

        const retried = enqueueGlobalBadgeCheck({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token-two',
            normalizedMediaUrl: 'https://cdn.example.com/failure.jpg',
        });
        await vi.waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(3);
        });
        expect((await retried).ok).toBe(true);
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    it('attaches callers to the in-flight badge request after the batch has already flushed', async () => {
        let resolveFetch: (() => void) | null = null;
        const fetchMock = vi.fn().mockImplementation((_endpoint: string, init?: RequestInit) => {
            const body = JSON.parse(String(init?.body ?? '{}')) as {
                items?: Array<{ request_id?: string }>;
            };

            return new Promise<Response>((resolve) => {
                resolveFetch = () => resolve(createMatchResponse([
                    {
                        request_id: body.items?.[0]?.request_id,
                        exists: true,
                        reaction: 'love',
                    },
                ]));
            });
        });
        vi.stubGlobal('fetch', fetchMock);

        const { enqueueGlobalBadgeCheck } = await import('./background-atlas-check-queue');

        const first = enqueueGlobalBadgeCheck({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            normalizedMediaUrl: 'https://cdn.example.com/in-flight.jpg',
        });
        await vi.waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(1);
        });

        const second = enqueueGlobalBadgeCheck({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            normalizedMediaUrl: 'https://cdn.example.com/in-flight.jpg',
        });

        expect(fetchMock).toHaveBeenCalledTimes(1);

        resolveFetch?.();
        const [firstResponse, secondResponse] = await Promise.all([first, second]);

        expect(firstResponse.payload.reaction).toBe('love');
        expect(secondResponse).toEqual(firstResponse);
    });

    it('keeps badge and referrer queues independent', async () => {
        const fetchMock = vi.fn().mockImplementation((endpoint: string, init?: RequestInit) => {
            const body = JSON.parse(String(init?.body ?? '{}')) as {
                items?: Array<{ request_id?: string }>;
            };

            return Promise.resolve(createMatchResponse(
                (body.items ?? []).map((item) => ({
                    request_id: item.request_id,
                    exists: true,
                    reaction: endpoint.includes('referrer-checks') ? 'funny' : 'like',
                })),
            ));
        });
        vi.stubGlobal('fetch', fetchMock);

        const { enqueueGlobalBadgeCheck, enqueueGlobalReferrerCheck } = await import('./background-atlas-check-queue');

        const badgePromise = enqueueGlobalBadgeCheck({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            normalizedMediaUrl: 'https://cdn.example.com/independent.jpg',
        });
        const referrerPromise = enqueueGlobalReferrerCheck({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            normalizedReferrerUrl: 'https://example.com/post#independent',
        });

        await vi.waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(2);
        });
        const [badgeResponse, referrerResponse] = await Promise.all([badgePromise, referrerPromise]);

        expect(badgeResponse.payload.reaction).toBe('like');
        expect(referrerResponse.payload.reaction).toBe('funny');
    });
});
