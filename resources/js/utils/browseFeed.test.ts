import { describe, expect, it } from 'vitest';
import { resolveBrowseFeed } from './browseFeed';

describe('resolveBrowseFeed', () => {
    it('uses local feed and service markers when present', () => {
        expect(resolveBrowseFeed({ feed: 'local', service: 'civit-ai-images' })).toBe('local');
        expect(resolveBrowseFeed({ feed: 'online', service: 'local' })).toBe('local');
        expect(resolveBrowseFeed({ feed: 'online', service: 'civit-ai-images' })).toBe('online');
    });

    it('treats legacy local service tabs as library tabs', () => {
        expect(resolveBrowseFeed({ service: 'local', source: 'all' })).toBe('local');
    });

    it('does not treat local source filters as local feed markers', () => {
        expect(resolveBrowseFeed({ source: 'local' })).toBe('online');
        expect(resolveBrowseFeed({ source: ['local'] })).toBe('online');
    });
});
