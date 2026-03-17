import { describe, expect, it } from 'vitest';
import { formatTabCountSummary, resolveTabDomainGroupKey, summarizeTabCounts } from './tab-counts';

describe('tab-counts', () => {
    it('groups common subdomains under the same registrable domain', () => {
        expect(resolveTabDomainGroupKey('https://www.civitai.com/models/1')).toBe('civitai.com');
        expect(resolveTabDomainGroupKey('https://api.civitai.com/images/2')).toBe('civitai.com');
    });

    it('keeps common country-code registrable domains together', () => {
        expect(resolveTabDomainGroupKey('https://news.bbc.co.uk/story')).toBe('bbc.co.uk');
    });

    it('ignores non-http urls when resolving the domain group', () => {
        expect(resolveTabDomainGroupKey('chrome://extensions')).toBeNull();
    });

    it('summarizes similar-domain and total tab counts', () => {
        expect(summarizeTabCounts([
            { url: 'https://www.civitai.com/models/1' },
            { url: 'https://api.civitai.com/images/2' },
            { url: 'https://example.com/post' },
        ], 'https://images.civitai.com/gallery/3')).toEqual({
            similarDomainCount: 2,
            totalCount: 3,
        });
    });

    it('renders the tab count summary label', () => {
        expect(formatTabCountSummary({
            similarDomainCount: 2,
            totalCount: 7,
        })).toBe('2/7');
        expect(formatTabCountSummary({
            similarDomainCount: null,
            totalCount: 7,
        })).toBe('—/7');
        expect(formatTabCountSummary(null)).toBe('—');
    });
});
