import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetStoredOptions = vi.fn();
const mockRequestQueuedBadgeCheckViaRuntime = vi.fn();

vi.mock('../atlas-options', () => ({
    getStoredOptions: mockGetStoredOptions,
}));

vi.mock('./atlas-request-log', () => ({
    atlasLoggedRuntimeRequest: vi.fn((_: string, __: string, ___: unknown, run: () => Promise<unknown>) => run()),
}));

vi.mock('../atlas-runtime-request', () => ({
    requestQueuedBadgeCheckViaRuntime: mockRequestQueuedBadgeCheckViaRuntime,
}));

describe('reaction-check-queue', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();

        mockGetStoredOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            siteCustomizations: [],
        });
        mockRequestQueuedBadgeCheckViaRuntime.mockResolvedValue({
            ok: true,
            status: 200,
            payload: {
                exists: true,
                reaction: 'like',
            },
        });
    });

    it('delegates normalized badge checks to the background runtime queue', async () => {
        const queue = await import('./reaction-check-queue');

        const result = await queue.enqueueReactionCheck('https://cdn.example.com/video.mp4?size=large#viewer');

        expect(mockRequestQueuedBadgeCheckViaRuntime).toHaveBeenCalledWith({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            normalizedMediaUrl: 'https://cdn.example.com/video.mp4?size=large',
        });
        expect(result).toEqual({
            exists: true,
            reaction: 'like',
            reactedAt: null,
            downloadedAt: null,
            blacklistedAt: null,
        });
    });

    it('applies the active page domain media cleaner before dispatching background checks', async () => {
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

        const image = document.getElementById('image');
        if (!(image instanceof HTMLImageElement)) {
            throw new Error('Expected image element.');
        }

        const queue = await import('./reaction-check-queue');
        await queue.enqueueReactionCheck(image.src, {
            media: image,
            candidatePageUrls: [window.location.href],
        });

        expect(mockRequestQueuedBadgeCheckViaRuntime).toHaveBeenCalledWith({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            normalizedMediaUrl: 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/8928e082-af52-4ade-a86e-d79e0ed63aa9/original=true/8928e082-af52-4ade-a86e-d79e0ed63aa9.jpeg',
        });
    });

    it('returns an empty result when the background runtime queue is unavailable', async () => {
        mockRequestQueuedBadgeCheckViaRuntime.mockResolvedValue(null);

        const queue = await import('./reaction-check-queue');
        const result = await queue.enqueueReactionCheck('https://cdn.example.com/offline.jpg');

        expect(result).toEqual({
            exists: false,
            reaction: null,
            reactedAt: null,
            downloadedAt: null,
            blacklistedAt: null,
        });
    });
});
