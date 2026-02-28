// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import type { AtlasStatusCacheEntry } from '../interactions';
import { getCachedAtlasStatus } from './statusCache';

describe('statusCache', () => {
  it('returns cached status by exact key and strip-hash key', () => {
    const cache = new Map<string, AtlasStatusCacheEntry>();
    cache.set('https://example.com/post/1', {
      exists: true,
      downloaded: false,
      failed: false,
      blacklisted: false,
      reactionType: 'like',
      ts: Date.now(),
    });

    expect(getCachedAtlasStatus(cache, 30_000, 'https://example.com/post/1')).not.toBeNull();
    expect(getCachedAtlasStatus(cache, 30_000, 'https://example.com/post/1#comments')).not.toBeNull();
  });

  it('evicts stale entries', () => {
    const cache = new Map<string, AtlasStatusCacheEntry>();
    cache.set('https://example.com/post/1', {
      exists: true,
      downloaded: false,
      failed: false,
      blacklisted: false,
      reactionType: null,
      ts: Date.now() - 60_000,
    });

    expect(getCachedAtlasStatus(cache, 30_000, 'https://example.com/post/1')).toBeNull();
    expect(cache.size).toBe(0);
  });
});
