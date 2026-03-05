import { describe, expect, it } from 'vitest';
import {
    normalizeHashAwareUrl,
    normalizeUrl,
    resolveIdentifiedMediaResolution,
    resolveMediaResolution,
    resolveReactionMediaUrl,
    resolveReactionTargetUrl,
    shouldExcludeAnchorHref,
    shouldExcludeMediaOrAnchorUrl,
} from './media-utils';

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
