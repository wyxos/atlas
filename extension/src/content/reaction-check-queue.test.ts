import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetStoredOptions = vi.fn();
const mockAtlasLoggedFetch = vi.fn();
const mockRequestAtlasViaRuntime = vi.fn();

vi.mock('../atlas-options', () => ({
    getStoredOptions: mockGetStoredOptions,
}));

vi.mock('./atlas-request-log', () => ({
    atlasLoggedFetch: mockAtlasLoggedFetch,
    atlasLoggedRuntimeRequest: vi.fn((_: string, __: string, ___: unknown, run: () => Promise<unknown>) => run()),
}));

vi.mock('../atlas-runtime-request', () => ({
    requestAtlasViaRuntime: mockRequestAtlasViaRuntime,
}));

describe('reaction-check-queue', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.useRealTimers();

        mockGetStoredOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            siteCustomizations: [],
        });
        mockRequestAtlasViaRuntime.mockResolvedValue(null);
    });

    it('coalesces concurrent checks for the same url and resolves both promises', async () => {
        mockAtlasLoggedFetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                matches: [
                    {
                        request_id: 'req-0',
                        exists: true,
                        reaction: 'like',
                    },
                ],
            }),
        });

        const queue = await import('./reaction-check-queue');

        const first = queue.enqueueReactionCheck('https://cdn.example.com/video.mp4');
        const second = queue.enqueueReactionCheck('https://cdn.example.com/video.mp4');

        const [firstResult, secondResult] = await Promise.all([first, second]);

        expect(firstResult).toEqual({
            exists: true,
            reaction: 'like',
            reactedAt: null,
            downloadedAt: null,
            blacklistedAt: null,
        });
        expect(secondResult).toEqual(firstResult);
        expect(mockAtlasLoggedFetch).toHaveBeenCalledTimes(1);
    });

    it('returns an empty result if hashing fails', async () => {
        const digestSpy = vi.spyOn(crypto.subtle, 'digest').mockRejectedValue(new Error('digest failed'));
        const queue = await import('./reaction-check-queue');

        const result = await queue.enqueueReactionCheck('https://cdn.example.com/fail.mp4');

        expect(result).toEqual({
            exists: false,
            reaction: null,
            reactedAt: null,
            downloadedAt: null,
            blacklistedAt: null,
        });
        expect(mockAtlasLoggedFetch).not.toHaveBeenCalled();
        digestSpy.mockRestore();
    });

    it('applies the active page domain media cleaner before hashing lookup requests', async () => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://civitai.com/images/123066308') as unknown as Location,
        });
        document.body.innerHTML = `
            <a href="/images/123066308">
                <img id="image" src="https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/8928e082-af52-4ade-a86e-d79e0ed63aa9/original=true,quality=90/f3a666a2-65dd-4738-a1f2-dd1de72f2636.jpeg" alt="image">
            </a>
        `;
        mockGetStoredOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            siteCustomizations: [
                {
                    domain: 'civitai.com',
                    matchRules: [],
                    referrerCleaner: {
                        stripQueryParams: [],
                    },
                    mediaCleaner: {
                        stripQueryParams: [],
                        rewriteRules: [],
                        strategies: ['civitaiCanonical'],
                    },
                },
            ],
        });
        mockAtlasLoggedFetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                matches: [
                    {
                        request_id: 'req-0',
                        exists: false,
                        reaction: null,
                    },
                ],
            }),
        });

        const image = document.getElementById('image');
        if (!(image instanceof HTMLImageElement)) {
            throw new Error('Expected image element.');
        }

        const queue = await import('./reaction-check-queue');
        await queue.enqueueReactionCheck(image.src, {
            media: image,
            candidatePageUrls: [window.location.href],
        });

        const [, , , init] = mockAtlasLoggedFetch.mock.calls[0] as [
            string,
            string,
            { items: Array<Record<string, unknown>> },
            { body: string },
        ];
        const sentBody = JSON.parse(init.body) as { items: Array<{ url_hash: string }> };
        const encoder = new TextEncoder();
        const digest = await crypto.subtle.digest('SHA-256', encoder.encode(
            'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/8928e082-af52-4ade-a86e-d79e0ed63aa9/original=true/8928e082-af52-4ade-a86e-d79e0ed63aa9.jpeg',
        ));
        const expectedHash = Array.from(new Uint8Array(digest))
            .map((byte) => byte.toString(16).padStart(2, '0'))
            .join('');

        expect(sentBody.items[0]?.url_hash).toBe(expectedHash);
    });
});
