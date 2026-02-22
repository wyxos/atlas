import { describe, expect, it } from 'vitest';
import { collectOpenTabUrls, normalizeTabUrlForOpenState } from './openTabs';

describe('normalizeTabUrlForOpenState', () => {
  it('keeps web urls and strips hash', () => {
    expect(normalizeTabUrlForOpenState('https://Example.com/path?q=1#section')).toBe(
      'https://example.com/path?q=1'
    );
    expect(normalizeTabUrlForOpenState('http://example.com/a#x')).toBe('http://example.com/a');
  });

  it('ignores non-http urls and invalid values', () => {
    expect(normalizeTabUrlForOpenState('chrome://extensions')).toBe('');
    expect(normalizeTabUrlForOpenState('about:blank')).toBe('');
    expect(normalizeTabUrlForOpenState('bad-url')).toBe('');
  });
});

describe('collectOpenTabUrls', () => {
  it('deduplicates normalized urls', () => {
    const tabs = [
      { url: 'https://example.com/artist?id=1#top' },
      { url: 'https://EXAMPLE.com/artist?id=1' },
      { url: 'https://example.com/artist?id=2' },
    ];

    expect(collectOpenTabUrls(tabs)).toEqual([
      'https://example.com/artist?id=1',
      'https://example.com/artist?id=2',
    ]);
  });
});
