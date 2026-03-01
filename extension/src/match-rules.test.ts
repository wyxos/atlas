import { describe, expect, it } from 'vitest';
import { DEFAULT_MATCH_RULES, normalizeMatchRules, parseStoredMatchRules, urlMatchesAnyRule } from './match-rules';

describe('match-rules', () => {
    it('provides deviantart defaults', () => {
        expect(DEFAULT_MATCH_RULES).toEqual([
            {
                domain: 'deviantart.com',
                regexes: ['.*\\/art\\/.*', '.*images-wix.*'],
            },
        ]);
    });

    it('normalizes domains and removes duplicate regexes', () => {
        const normalized = normalizeMatchRules([
            { domain: '  DeviantArt.COM ', regexes: ['.*images-wix.*', '.*images-wix.*', '  .*\\/art\\/.*  '] },
        ]);

        expect(normalized).toEqual([
            { domain: 'deviantart.com', regexes: ['.*images-wix.*', '.*\\/art\\/.*'] },
        ]);
    });

    it('matches rule by page domain and regex against url', () => {
        const rules = [
            {
                domain: 'deviantart.com',
                regexes: ['.*\\/art\\/.*', '.*images-wix.*'],
            },
        ];

        expect(urlMatchesAnyRule('https://www.deviantart.com/user/art/work', rules, 'www.deviantart.com')).toBe(true);
        expect(urlMatchesAnyRule('https://images-wixmp.com/f/some-image.jpg', rules, 'www.deviantart.com')).toBe(true);
        expect(urlMatchesAnyRule('https://example.com/not-match', rules, 'www.deviantart.com')).toBe(false);
        expect(urlMatchesAnyRule('https://images-wixmp.com/f/some-image.jpg', rules, 'pixiv.net')).toBe(true);
    });

    it('allows all urls when no page-domain rule exists', () => {
        const rules = [
            {
                domain: 'deviantart.com',
                regexes: ['.*\\/art\\/.*'],
            },
        ];

        expect(urlMatchesAnyRule('https://twitter.com/some/image.jpg', rules, 'twitter.com')).toBe(true);
    });

    it('parses stored rule arrays', () => {
        const parsed = parseStoredMatchRules([
            { domain: 'DeviantArt.com', regexes: ['.*images-wix.*'] },
        ]);

        expect(parsed).toEqual([
            { domain: 'deviantart.com', regexes: ['.*images-wix.*'] },
        ]);
    });
});
