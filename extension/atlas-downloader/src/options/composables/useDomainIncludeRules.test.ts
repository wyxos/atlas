// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import {
  isValidPatternSource,
  normalizeDomainInput,
  normalizePatternInput,
  parseDomainRules,
  serializeDomainRules,
  toEditableDomainRule,
} from './useDomainIncludeRules';

describe('useDomainIncludeRules', () => {
  it('normalizes domain and regex input', () => {
    expect(normalizeDomainInput('*.DeviantArt.com')).toBe('deviantart.com');
    expect(normalizePatternInput('/foo.*/i')).toBe('foo.*');
    expect(isValidPatternSource('foo.*')).toBe(true);
    expect(isValidPatternSource('foo(')).toBe(false);
  });

  it('parses rules from json and serializes editable rules', () => {
    const parsed = parseDomainRules(
      JSON.stringify([
        { domain: 'deviantart.com', patterns: ['.*\\/art\\/.*'] },
      ])
    );

    expect(parsed).toEqual([
      { domain: 'deviantart.com', patterns: ['.*\\/art\\/.*'] },
    ]);

    const editable = parsed.map((rule) => toEditableDomainRule(rule));
    editable[0].patterns.push({ value: '.*deviationid=.*', isEditing: false, draft: '.*deviationid=.*' });

    expect(serializeDomainRules(editable)).toEqual([
      { domain: 'deviantart.com', patterns: ['.*\\/art\\/.*', '.*deviationid=.*'] },
    ]);
  });
});
