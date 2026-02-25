// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import {
  applyReactionStatusUpdateFromPayload,
  collectReactionStatusLookupKeys,
  isHashSpecificReferrerLookupKey,
} from './statusUpdates';

describe('isHashSpecificReferrerLookupKey', () => {
  it('identifies #image-N fragments', () => {
    expect(isHashSpecificReferrerLookupKey('https://example.com/post#image-1')).toBe(true);
    expect(isHashSpecificReferrerLookupKey('https://example.com/post#image-20')).toBe(true);
    expect(isHashSpecificReferrerLookupKey('https://example.com/post#details')).toBe(false);
  });
});

describe('collectReactionStatusLookupKeys', () => {
  it('keeps #image-N referrers hash-specific while normalizing others', () => {
    const lookupKeys = collectReactionStatusLookupKeys({
      url: 'https://images.example.com/media/a.jpg',
      referrerUrl: 'https://example.com/post#image-2',
      previewUrl: 'https://images.example.com/preview/a-thumb.jpg#fragment',
    });

    expect(lookupKeys).toContain('https://images.example.com/media/a.jpg');
    expect(lookupKeys).toContain('https://example.com/post#image-2');
    expect(lookupKeys).toContain('https://images.example.com/preview/a-thumb.jpg#fragment');
    expect(lookupKeys).toContain('https://images.example.com/preview/a-thumb.jpg');
    expect(lookupKeys).not.toContain('https://example.com/post');
  });
});

describe('applyReactionStatusUpdateFromPayload', () => {
  it('updates cache with all reaction lookup keys', () => {
    const cache = new Map();
    const updated = applyReactionStatusUpdateFromPayload(
      {
        url: 'https://images.example.com/media/a.jpg',
        referrerUrl: 'https://example.com/post',
        previewUrl: 'https://images.example.com/preview/a-thumb.jpg',
        reactionType: 'love',
        downloaded: false,
        blacklisted: false,
        downloadProgress: 34,
        downloadedAt: null,
      },
      cache
    );

    expect(updated).toBe(true);
    expect(cache.get('https://images.example.com/media/a.jpg')?.reactionType).toBe('love');
    expect(cache.get('https://example.com/post')?.reactionType).toBe('love');
    expect(cache.get('https://images.example.com/preview/a-thumb.jpg')?.reactionType).toBe('love');
  });

  it('does not update cache when payload has no usable lookup key', () => {
    const cache = new Map();
    expect(applyReactionStatusUpdateFromPayload({ reactionType: 'like' }, cache)).toBe(false);
    expect(cache.size).toBe(0);
  });
});
