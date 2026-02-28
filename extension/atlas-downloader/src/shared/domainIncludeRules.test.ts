// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import {
  isValidRegexSource,
  normalizeDomain,
  normalizeRegexSource,
  parseDomainIncludeRules,
  serializeDomainIncludeRules,
} from './domainIncludeRules';

describe('domainIncludeRules', () => {
  it('normalizes domains from host-like values', () => {
    expect(normalizeDomain('https://WWW.DeviantArt.com/path')).toBe('www.deviantart.com');
    expect(normalizeDomain('*.deviantart.com')).toBe('deviantart.com');
    expect(normalizeDomain('')).toBe('');
  });

  it('normalizes regex source from slash-literal format', () => {
    expect(normalizeRegexSource('/foo.*/i')).toBe('foo.*');
    expect(normalizeRegexSource('foo.*')).toBe('foo.*');
  });

  it('validates regex sources', () => {
    expect(isValidRegexSource('foo.*')).toBe(true);
    expect(isValidRegexSource('foo(')).toBe(false);
  });

  it('parses, normalizes, deduplicates and serializes domain rules', () => {
    const raw = JSON.stringify([
      { domain: 'deviantart.com', patterns: ['.*\\/art\\/.*', '.*\\/art\\/.*', 'foo('] },
      { domain: '*.deviantart.com', patterns: ['.*deviationid=.*'] },
      { domain: ' ', patterns: ['.*ignored.*'] },
    ]);

    const parsed = parseDomainIncludeRules(raw);
    expect(parsed).toEqual([
      {
        domain: 'deviantart.com',
        patterns: ['.*\\/art\\/.*', '.*deviationid=.*'],
      },
    ]);

    expect(serializeDomainIncludeRules(parsed)).toBe(
      JSON.stringify([
        {
          domain: 'deviantart.com',
          patterns: ['.*\\/art\\/.*', '.*deviationid=.*'],
        },
      ])
    );
  });
});
