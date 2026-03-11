import { describe, expect, it } from 'vitest';
import {
    cleanupReferrerUrl,
    parseStoredReferrerQueryParamsToStripByDomain,
    validateReferrerQueryParam,
} from './referrer-cleanup';

describe('cleanupReferrerUrl', () => {
    it('strips configured query params for matching domains and preserves the rest of the url', () => {
        expect(cleanupReferrerUrl('https://domain.com/?id=123&tag=blue+sky', {
            'domain.com': ['tag', 'tags'],
        })).toBe('https://domain.com/?id=123');
    });

    it('leaves urls unchanged when the hostname has no cleanup rules', () => {
        expect(cleanupReferrerUrl('https://other.example.com/?id=123&tag=blue', {
            'domain.com': ['tag'],
        })).toBe('https://other.example.com/?id=123&tag=blue');
    });

    it('preserves hash fragments after stripping matching query params', () => {
        expect(cleanupReferrerUrl('https://domain.com/?id=123&tag=blue#image-2', {
            'domain.com': ['tag'],
        })).toBe('https://domain.com/?id=123#image-2');
    });
});

describe('parseStoredReferrerQueryParamsToStripByDomain', () => {
    it('normalizes legacy string and array storage shapes', () => {
        expect(parseStoredReferrerQueryParamsToStripByDomain({
            'https://www.example.com/path': 'tag, tags',
            '.sub.example.com.': ['Tag', 'filter', 12],
            'empty.example.com': [],
        })).toEqual({
            'www.example.com': ['tag', 'tags'],
            'sub.example.com': ['tag', 'filter'],
        });
    });
});

describe('validateReferrerQueryParam', () => {
    it('rejects invalid query param names', () => {
        expect(validateReferrerQueryParam('')).toBe('Referrer query parameter name cannot be empty.');
        expect(validateReferrerQueryParam('tag=value')).toBe('Invalid referrer query parameter "tag=value".');
    });
});
