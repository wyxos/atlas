import { describe, expect, it } from 'vitest';

import { normalizeComparableOpenTabUrl, normalizeComparableOpenTabUrls } from './open-tab-url';

describe('open-tab-url', () => {
    it('excludes plain root urls without query or hash', () => {
        expect(normalizeComparableOpenTabUrl('https://x.com')).toBeNull();
        expect(normalizeComparableOpenTabUrl('https://facebook.com/')).toBeNull();
    });

    it('keeps hashes and query strings for comparable urls', () => {
        expect(normalizeComparableOpenTabUrl('https://example.com/post?view=full#image-1')).toBe(
            'https://example.com/post?view=full#image-1',
        );
        expect(normalizeComparableOpenTabUrl('https://example.com/#watch')).toBe('https://example.com/#watch');
    });

    it('normalizes comparable url arrays and removes duplicates', () => {
        expect(normalizeComparableOpenTabUrls([
            'https://example.com/post#image-1',
            'https://example.com/post#image-1',
            'https://example.com',
            'mailto:test@example.com',
        ])).toEqual([
            'https://example.com/post#image-1',
        ]);
    });
});
