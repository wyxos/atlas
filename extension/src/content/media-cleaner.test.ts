import { beforeEach, describe, expect, it, vi } from 'vitest';

function setWindowLocation(url: string): void {
    Object.defineProperty(window, 'location', {
        configurable: true,
        value: new URL(url) as unknown as Location,
    });
}

describe('applyMediaCleaner', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.doUnmock('./civitai-reaction-context');
        document.body.innerHTML = '';
    });

    it('applies strategy, then query stripping, then the first matching rewrite rule', async () => {
        const canonicalizeCivitAiMediaUrl = vi.fn(() => 'https://cdn.example.com/original.jpg?quality=90&token=abc');
        vi.doMock('./civitai-reaction-context', () => ({
            canonicalizeCivitAiMediaUrl,
        }));

        const { applyMediaCleaner } = await import('./media-cleaner');
        const cleaned = applyMediaCleaner(
            'https://cdn.example.com/input.jpg?quality=90&token=abc',
            {
                strategies: ['civitaiCanonical'],
                stripQueryParams: ['quality'],
                rewriteRules: [
                    {
                        pattern: 'original\\.jpg\\?token=abc$',
                        replace: 'canonical.jpg',
                    },
                    {
                        pattern: 'canonical\\.jpg$',
                        replace: 'should-not-run.jpg',
                    },
                ],
            },
        );

        expect(canonicalizeCivitAiMediaUrl).toHaveBeenCalledWith(
            'https://cdn.example.com/input.jpg?quality=90&token=abc',
            {
                media: null,
                candidatePageUrls: [],
            },
        );
        expect(cleaned).toBe('https://cdn.example.com/canonical.jpg');
    });

    it('canonicalizes civitai image-page variants to the stable stored media url', async () => {
        setWindowLocation('https://civitai.com/images/123066308');
        const { applyMediaCleaner } = await import('./media-cleaner');

        document.body.innerHTML = `
            <a href="/images/123066308">
                <img id="image" src="https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/8928e082-af52-4ade-a86e-d79e0ed63aa9/original=true,quality=90/f3a666a2-65dd-4738-a1f2-dd1de72f2636.jpeg" alt="image">
            </a>
        `;

        const image = document.getElementById('image');
        if (!(image instanceof HTMLImageElement)) {
            throw new Error('Expected image element.');
        }

        expect(applyMediaCleaner(image.src, {
            strategies: ['civitaiCanonical'],
            stripQueryParams: [],
            rewriteRules: [],
        }, {
            media: image,
            candidatePageUrls: [window.location.href],
        })).toBe(
            'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/8928e082-af52-4ade-a86e-d79e0ed63aa9/original=true/8928e082-af52-4ade-a86e-d79e0ed63aa9.jpeg',
        );
    });

    it('preserves civitai video canonicalization via candidate image-page urls', async () => {
        setWindowLocation('https://civitai.com/posts/16973563');
        const { applyMediaCleaner } = await import('./media-cleaner');

        document.body.innerHTML = `
            <a href="/images/76477306">
                <video id="video" src="https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/0d6b721b-6256-4849-99ba-05c7cbb5b64f/transcode=true,original=true,quality=90/4MGM4VAVYH67B8TYY43NVH1780.mp4"></video>
            </a>
        `;

        const video = document.getElementById('video');
        if (!(video instanceof HTMLVideoElement)) {
            throw new Error('Expected video element.');
        }

        expect(applyMediaCleaner(video.src, {
            strategies: ['civitaiCanonical'],
            stripQueryParams: [],
            rewriteRules: [],
        }, {
            media: video,
            candidatePageUrls: [window.location.href, 'https://civitai.com/images/76477306'],
        })).toBe(
            'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/0d6b721b-6256-4849-99ba-05c7cbb5b64f/transcode=true,original=true,quality=90/76477306.mp4',
        );
    });
});
