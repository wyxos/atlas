import { describe, expect, it } from 'vitest';
import { isOpenTabHighlightEligibleUrl, normalizeOpenTabUrl } from './openTabUrl';

describe('normalizeOpenTabUrl', () => {
  it('normalizes http/https urls and strips hash', () => {
    expect(normalizeOpenTabUrl('https://WYXOS.com/demo#part')).toBe('https://wyxos.com/demo');
  });

  it('returns empty string for unsupported protocols', () => {
    expect(normalizeOpenTabUrl('ftp://wyxos.com/demo')).toBe('');
  });
});

describe('isOpenTabHighlightEligibleUrl', () => {
  it('rejects plain domain urls', () => {
    expect(isOpenTabHighlightEligibleUrl('https://wyxos.com')).toBe(false);
    expect(isOpenTabHighlightEligibleUrl('https://wyxos.com/')).toBe(false);
  });

  it('accepts path urls', () => {
    expect(isOpenTabHighlightEligibleUrl('https://wyxos.com/demo')).toBe(true);
  });

  it('accepts path+query urls', () => {
    expect(isOpenTabHighlightEligibleUrl('https://wyxos.com/demo?page=1')).toBe(true);
  });

  it('accepts query-only urls', () => {
    expect(isOpenTabHighlightEligibleUrl('https://wyxos.com?page=1')).toBe(true);
  });
});
