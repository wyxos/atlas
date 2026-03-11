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
            referrerQueryParamsToStripByDomain: {},
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
            referrer_url: 'https://example.com/gallery?tab=1#section',
        });

        const sentBody = JSON.parse(init.body) as { items: Array<Record<string, unknown>> };
        expect(sentBody.items[0]).toMatchObject({
            request_id: 'ref-0',
        });
        expect(sentBody.items[0].referrer_hash).toEqual(expect.any(String));
        expect(sentBody.items[0].referrer_url).toBeUndefined();
    });

    it('hashes the cleaned referrer url when query params are configured to be stripped', async () => {
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
        await queue.enqueueReferrerCheck('https://domain.com/?id=123&tag=blue+sky', {
            'domain.com': ['tag', 'tags'],
        });

        const [, , requestLogPayload, init] = mockAtlasLoggedFetch.mock.calls[0] as [
            string,
            string,
            { items: Array<Record<string, unknown>> },
            { body: string },
        ];

        expect(requestLogPayload.items[0].referrer_url).toBe('https://domain.com/?id=123');

        const sentBody = JSON.parse(init.body) as { items: Array<Record<string, unknown>> };
        const encoder = new TextEncoder();
        const digest = await crypto.subtle.digest('SHA-256', encoder.encode('https://domain.com/?id=123'));
        const expectedHash = Array.from(new Uint8Array(digest))
            .map((byte) => byte.toString(16).padStart(2, '0'))
            .join('');
        expect(sentBody.items[0].referrer_hash).toBe(expectedHash);
    });

    it('treats different hash fragments as distinct referrer checks', async () => {
        mockAtlasLoggedFetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                matches: [
                    {
                        request_id: 'ref-0',
                        exists: true,
                        reaction: 'like',
                    },
                    {
                        request_id: 'ref-1',
                        exists: true,
                        reaction: 'like',
                    },
                ],
            }),
        });

        const queue = await import('./referrer-check-queue');
        await Promise.all([
            queue.enqueueReferrerCheck('https://example.com/gallery?tab=1#section-a'),
            queue.enqueueReferrerCheck('https://example.com/gallery?tab=1#section-b'),
        ]);

        expect(mockAtlasLoggedFetch).toHaveBeenCalledTimes(1);

        const [, , requestLogPayload, init] = mockAtlasLoggedFetch.mock.calls[0] as [
            string,
            string,
            { items: Array<Record<string, unknown>> },
            { body: string },
        ];

        expect(requestLogPayload.items).toHaveLength(2);
        expect(requestLogPayload.items.map((item) => item.referrer_url)).toEqual([
            'https://example.com/gallery?tab=1#section-a',
            'https://example.com/gallery?tab=1#section-b',
        ]);

        const sentBody = JSON.parse(init.body) as { items: Array<Record<string, unknown>> };
        expect(sentBody.items).toHaveLength(2);
        expect(sentBody.items[0].referrer_hash).not.toBe(sentBody.items[1].referrer_hash);
    });
});
