// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { buildItemLookupKeys, buildPrimaryItemLookupUrl } from './itemLookup';

describe('itemLookup', () => {
  it('builds eligible lookup keys from item url and referrer', () => {
    const keys = buildItemLookupKeys({
      url: 'https://cdn.example.com/media/file.jpg#hash',
      referrer_url: 'https://example.com/post/1',
    });

    expect(keys).toContain('https://cdn.example.com/media/file.jpg#hash');
    expect(keys).toContain('https://cdn.example.com/media/file.jpg');
    expect(keys).toContain('https://example.com/post/1');
  });

  it('returns first lookup url as primary', () => {
    const key = buildPrimaryItemLookupUrl({
      url: 'https://cdn.example.com/media/file.jpg',
      referrer_url: '',
    });

    expect(key).toBe('https://cdn.example.com/media/file.jpg');
  });
});
