import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetStoredOptions = vi.fn();
const mockAtlasLoggedFetch = vi.fn();

vi.mock('../atlas-options', () => ({
    getStoredOptions: mockGetStoredOptions,
}));

vi.mock('./atlas-request-log', () => ({
    atlasLoggedFetch: mockAtlasLoggedFetch,
}));

describe('reaction-check-queue', () => {
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
});
