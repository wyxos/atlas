import { describe, expect, it } from 'vitest';
import {
    cleanupReferrerUrl,
    parseStoredReferrerQueryParamsToStripByDomain,
    STRIP_ALL_QUERY_PARAMS,
    validateReferrerQueryParam,
    normalizeReferrerQueryParams,
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

    it('strips all query params when the wildcard is configured for the domain', () => {
        expect(cleanupReferrerUrl('https://domain.com/?id=123&tag=blue#image-2', {
            'domain.com': [STRIP_ALL_QUERY_PARAMS],
        })).toBe('https://domain.com/#image-2');
    });
});

describe('parseStoredReferrerQueryParamsToStripByDomain', () => {
    it('normalizes legacy string and array storage shapes', () => {
        expect(parseStoredReferrerQueryParamsToStripByDomain({
            'https://www.example.com/path': 'tag, tags',
            '.sub.example.com.': ['Tag', STRIP_ALL_QUERY_PARAMS, 'filter', 12],
            'empty.example.com': [],
        })).toEqual({
            'www.example.com': ['tag', 'tags'],
            'sub.example.com': [STRIP_ALL_QUERY_PARAMS],
        });
    });
});

describe('validateReferrerQueryParam', () => {
    it('rejects invalid query param names', () => {
        expect(validateReferrerQueryParam('')).toBe('Referrer query parameter name cannot be empty.');
        expect(validateReferrerQueryParam('tag=value')).toBe('Invalid referrer query parameter "tag=value".');
        expect(validateReferrerQueryParam(STRIP_ALL_QUERY_PARAMS)).toBeNull();
    });
});

describe('normalizeReferrerQueryParams', () => {
    it('collapses wildcard query cleanup to a single entry', () => {
        expect(normalizeReferrerQueryParams(['tag', STRIP_ALL_QUERY_PARAMS, 'tags'])).toEqual([STRIP_ALL_QUERY_PARAMS]);
    });
});
