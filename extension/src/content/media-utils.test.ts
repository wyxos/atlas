import { describe, expect, it } from 'vitest';
import { resolveReactionMediaUrl, shouldExcludeAnchorHref, shouldExcludeMediaOrAnchorUrl } from './media-utils';

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

describe('shouldExcludeMediaOrAnchorUrl', () => {
    it('excludes domain-root urls', () => {
        expect(shouldExcludeMediaOrAnchorUrl('https://deviantart.com')).toBe(true);
        expect(shouldExcludeMediaOrAnchorUrl('https://youtube.com/')).toBe(true);
        expect(shouldExcludeMediaOrAnchorUrl('https://www.youtube.com')).toBe(true);
    });

    it('does not exclude non-root media urls', () => {
        expect(shouldExcludeMediaOrAnchorUrl('https://images.example.com/path/file.jpg')).toBe(false);
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
