import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetStoredOptions = vi.fn();
const mockAtlasLoggedFetch = vi.fn();

vi.mock('../atlas-options', () => ({
    getStoredOptions: mockGetStoredOptions,
}));

vi.mock('./atlas-request-log', () => ({
    atlasLoggedFetch: mockAtlasLoggedFetch,
}));

describe('referrer-check-queue', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.useRealTimers();

        mockGetStoredOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            matchRules: [],
        });
    });

    it('logs original referrer url while sending hash-only payload to backend', async () => {
        mockAtlasLoggedFetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                matches: [
                    {
                        request_id: 'ref-0',
                        exists: true,
                        reaction: 'like',
                    },
                ],
            }),
        });

        const queue = await import('./referrer-check-queue');
        await queue.enqueueReferrerCheck('https://example.com/gallery?tab=1#section');

        expect(mockAtlasLoggedFetch).toHaveBeenCalledTimes(1);

        const [endpoint, method, requestLogPayload, init] = mockAtlasLoggedFetch.mock.calls[0] as [
            string,
            string,
            { items: Array<Record<string, unknown>> },
            { body: string },
        ];

        expect(endpoint).toBe('https://atlas.test/api/extension/referrer-checks');
        expect(method).toBe('POST');
        expect(requestLogPayload.items[0]).toMatchObject({
            request_id: 'ref-0',
            referrer_url: 'https://example.com/gallery?tab=1',
        });

        const sentBody = JSON.parse(init.body) as { items: Array<Record<string, unknown>> };
        expect(sentBody.items[0]).toMatchObject({
            request_id: 'ref-0',
        });
        expect(sentBody.items[0].referrer_hash).toEqual(expect.any(String));
        expect(sentBody.items[0].referrer_url).toBeUndefined();
    });
});
