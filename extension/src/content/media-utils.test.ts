import { describe, expect, it } from 'vitest';
import {
    hasRelatedPostThumbnailsBelowMedia,
    normalizeHashAwareUrl,
    normalizeUrl,
    resolveIdentifiedMediaResolution,
    resolveMediaResolution,
    resolveReactionMediaUrl,
    resolveReactionTargetUrl,
    shouldExcludeAnchorHref,
    shouldExcludeMediaOrAnchorUrl,
} from './media-utils';

function setMockRect(
    element: Element,
    rect: { left: number; top: number; width: number; height: number },
): void {
    const right = rect.left + rect.width;
    const bottom = rect.top + rect.height;

    Object.defineProperty(element, 'getBoundingClientRect', {
        configurable: true,
        value: () => ({
            x: rect.left,
            y: rect.top,
            left: rect.left,
            top: rect.top,
            right,
            bottom,
            width: rect.width,
            height: rect.height,
            toJSON: () => ({}),
        }) as DOMRect,
    });
}

describe('resolveReactionMediaUrl', () => {
    it('does not use video poster as reaction media url fallback', () => {
        const video = document.createElement('video');
        video.poster = 'https://cdn.example.com/poster.jpg';

        expect(resolveReactionMediaUrl(video)).toBeNull();
    });

    it('uses direct video src when available', () => {
        const video = document.createElement('video');
        video.src = 'https://cdn.example.com/video.mp4#fragment';

        expect(resolveReactionMediaUrl(video)).toBe('https://cdn.example.com/video.mp4');
    });
});

describe('resolveReactionTargetUrl', () => {
    it('falls back to page url for poster-only videos', () => {
        const video = document.createElement('video');
        video.poster = 'https://cdn.example.com/poster.jpg';

        expect(resolveReactionTargetUrl(video, 'https://www.facebook.com/reel/123')).toBe('https://www.facebook.com/reel/123');
    });
});

describe('normalizeHashAwareUrl', () => {
    it('keeps hash fragments for hash-aware comparisons', () => {
        expect(normalizeHashAwareUrl('https://example.com/page?tab=1#section')).toBe('https://example.com/page?tab=1#section');
    });
});

describe('normalizeUrl', () => {
    it('removes hash fragments for hash-insensitive checks', () => {
        expect(normalizeUrl('https://example.com/page?tab=1#section')).toBe('https://example.com/page?tab=1');
    });
});

describe('resolveIdentifiedMediaResolution', () => {
    it('returns null for images until natural dimensions are known', () => {
        const image = document.createElement('img');
        image.width = 640;
        image.height = 360;

        expect(resolveIdentifiedMediaResolution(image)).toBeNull();
        expect(resolveMediaResolution(image)).toEqual({ width: 640, height: 360 });

        Object.defineProperty(image, 'naturalWidth', { value: 1920, configurable: true });
        Object.defineProperty(image, 'naturalHeight', { value: 1080, configurable: true });

        expect(resolveIdentifiedMediaResolution(image)).toEqual({ width: 1920, height: 1080 });
    });

    it('returns null for videos until metadata dimensions are known', () => {
        const video = document.createElement('video');
        Object.defineProperty(video, 'clientWidth', { value: 640, configurable: true });
        Object.defineProperty(video, 'clientHeight', { value: 360, configurable: true });

        expect(resolveIdentifiedMediaResolution(video)).toBeNull();
        expect(resolveMediaResolution(video)).toEqual({ width: 640, height: 360 });

        Object.defineProperty(video, 'videoWidth', { value: 1920, configurable: true });
        Object.defineProperty(video, 'videoHeight', { value: 1080, configurable: true });

        expect(resolveIdentifiedMediaResolution(video)).toEqual({ width: 1920, height: 1080 });
    });
});

describe('shouldExcludeMediaOrAnchorUrl', () => {
    it('allows domain-root urls', () => {
        expect(shouldExcludeMediaOrAnchorUrl('https://deviantart.com')).toBe(false);
        expect(shouldExcludeMediaOrAnchorUrl('https://youtube.com/')).toBe(false);
        expect(shouldExcludeMediaOrAnchorUrl('https://www.youtube.com')).toBe(false);
    });

    it('does not exclude non-root media urls', () => {
        expect(shouldExcludeMediaOrAnchorUrl('https://images.example.com/path/file.jpg')).toBe(false);
    });

    it('allows root urls when query or hash is present', () => {
        expect(shouldExcludeMediaOrAnchorUrl('https://youtube.com/?v=abc123')).toBe(false);
        expect(shouldExcludeMediaOrAnchorUrl('https://youtube.com/#watch')).toBe(false);
    });
});

describe('shouldExcludeAnchorHref', () => {
    it('excludes hash href', () => {
        expect(shouldExcludeAnchorHref('#', 'https://example.com/page')).toBe(true);
    });

    it('excludes email/phone href', () => {
        expect(shouldExcludeAnchorHref('mailto:test@example.com', null)).toBe(true);
        expect(shouldExcludeAnchorHref('tel:+1-212-555-0123', null)).toBe(true);
    });
});

describe('hasRelatedPostThumbnailsBelowMedia', () => {
    function buildDeviantArtAllImagesSection(): {
        main: HTMLElement;
        media: HTMLImageElement;
        section: HTMLElement;
    } {
        document.body.innerHTML = '';

        const main = document.createElement('main');
        const media = document.createElement('img');
        main.appendChild(media);

        const section = document.createElement('section');
        const headingWrap = document.createElement('span');
        const heading = document.createElement('h2');
        heading.textContent = 'All Images';
        headingWrap.appendChild(heading);
        section.appendChild(headingWrap);

        const track = document.createElement('div');
        const thumbStrip = document.createElement('div');

        const thumbA = document.createElement('button');
        const thumbAImage = document.createElement('img');
        thumbAImage.src = 'https://images.example.com/one-150.jpg';
        thumbAImage.alt = 'Untitled';
        thumbA.appendChild(thumbAImage);

        const thumbB = document.createElement('button');
        const thumbBImage = document.createElement('img');
        thumbBImage.src = 'https://images.example.com/two-150.jpg';
        thumbBImage.alt = 'grok_image_1772597638273.jpg';
        thumbB.appendChild(thumbBImage);

        thumbStrip.appendChild(thumbA);
        thumbStrip.appendChild(thumbB);
        track.appendChild(thumbStrip);
        section.appendChild(track);

        const helperToggle = document.createElement('button');
        helperToggle.setAttribute('aria-label', 'Click to view images by scrolling through them');
        section.appendChild(helperToggle);

        main.appendChild(section);
        document.body.appendChild(main);

        setMockRect(media, { left: 120, top: 80, width: 420, height: 360 });
        setMockRect(section, { left: 260, top: 452, width: 340, height: 56 });

        return {
            main,
            media,
            section,
        };
    }

    it('returns true for DeviantArt when the All Images strip is shown below the media', () => {
        const { media } = buildDeviantArtAllImagesSection();

        expect(hasRelatedPostThumbnailsBelowMedia(media, 'www.deviantart.com')).toBe(true);
    });

    it('returns false on non-DeviantArt hosts even when similar controls exist', () => {
        const { media } = buildDeviantArtAllImagesSection();

        expect(hasRelatedPostThumbnailsBelowMedia(media, 'www.example.com')).toBe(false);
    });

    it('returns false when the DeviantArt section has fewer than two thumbnail buttons', () => {
        const { media, section } = buildDeviantArtAllImagesSection();
        const secondThumb = section.querySelectorAll('button img')[1]?.closest('button');
        secondThumb?.remove();

        expect(hasRelatedPostThumbnailsBelowMedia(media, 'www.deviantart.com')).toBe(false);
    });
});
