// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import {
  buildStatusMapFromCache,
  clearNodeMarkerAttributes,
  findStatusForLookupKeys,
  mergeSheetItemStatuses,
  syncOpenTabIconBadges,
  syncPageVisitedBadge,
  syncReactionIconBadges,
} from './pageMarkers';

function stripHash(value: string): string {
  return (value || '').split('#', 1)[0];
}

function setRect(element: Element, left: number, top: number, width: number, height: number) {
  Object.defineProperty(element, 'getBoundingClientRect', {
    value: () =>
      ({
        left,
        top,
        width,
        height,
        right: left + width,
        bottom: top + height,
        x: left,
        y: top,
        toJSON: () => ({}),
      }) as DOMRect,
    configurable: true,
  });
}

describe('page markers', () => {
  it('builds status map from cache and prunes stale entries', () => {
    const now = Date.now();
    const cache = new Map([
      [
        'https://example.com/a#x',
        {
          exists: true,
          downloaded: false,
          blacklisted: false,
          reactionType: 'like',
          ts: now,
        },
      ],
      [
        'https://example.com/stale',
        {
          exists: true,
          downloaded: false,
          blacklisted: false,
          reactionType: null,
          ts: now - 40_000,
        },
      ],
    ]);

    const statusByUrl = buildStatusMapFromCache(cache, 30_000, stripHash);
    expect(statusByUrl.has('https://example.com/a#x')).toBe(true);
    expect(statusByUrl.has('https://example.com/a')).toBe(true);
    expect(cache.has('https://example.com/stale')).toBe(false);
  });

  it('merges sheet statuses and resolves lookups', () => {
    const statusByUrl = new Map();
    mergeSheetItemStatuses(
      statusByUrl,
      [
        {
          url: 'https://example.com/media.jpg#hash',
          atlas: {
            exists: true,
            downloaded: false,
            blacklisted: false,
            reaction: { type: 'funny' },
          },
        },
      ],
      stripHash
    );

    const status = findStatusForLookupKeys(['https://example.com/media.jpg'], statusByUrl, stripHash);
    expect(status?.reactionType).toBe('funny');
  });

  it('shows and hides page visited badge based on current page status', () => {
    window.history.pushState({}, '', '/visited/path');

    const statusByUrl = new Map([
      [
        window.location.href,
        {
          exists: true,
          downloaded: false,
          blacklisted: false,
          reactionType: null,
        },
      ],
    ]);

    syncPageVisitedBadge(window.location.href, statusByUrl, stripHash);
    expect(document.getElementById('atlas-downloader-page-visited-badge')).not.toBeNull();

    syncPageVisitedBadge(window.location.href, new Map(), stripHash);
    expect(document.getElementById('atlas-downloader-page-visited-badge')).toBeNull();
  });

  it('renders reaction icon badges at bottom right for reacted nodes', () => {
    const img = document.createElement('img');
    img.setAttribute('data-atlas-reaction', 'like');
    setRect(img, 10, 20, 120, 90);
    document.body.appendChild(img);

    syncReactionIconBadges([img]);

    const layer = document.getElementById('atlas-downloader-reaction-badge-layer');
    expect(layer).not.toBeNull();
    expect(layer?.querySelectorAll('.atlas-downloader-reaction-badge').length).toBe(1);
    expect(layer?.querySelector('.atlas-downloader-reaction-badge.like')).not.toBeNull();
  });

  it('renders open-tab icon badges and removes layer when empty', () => {
    const link = document.createElement('a');
    setRect(link, 30, 40, 100, 80);
    document.body.appendChild(link);

    syncOpenTabIconBadges([link]);

    const layer = document.getElementById('atlas-downloader-open-tab-badge-layer');
    expect(layer).not.toBeNull();
    expect(layer?.querySelectorAll('.atlas-downloader-open-tab-badge').length).toBe(1);

    syncOpenTabIconBadges([]);
    expect(document.getElementById('atlas-downloader-open-tab-badge-layer')).toBeNull();
  });

  it('clears open-tab marker attributes', () => {
    const link = document.createElement('a');
    link.setAttribute('data-atlas-open-tab', '1');
    link.setAttribute('data-atlas-marked', '1');
    link.setAttribute('data-atlas-state', 'exists');
    link.setAttribute('data-atlas-reaction', 'like');

    clearNodeMarkerAttributes([link]);
    expect(link.hasAttribute('data-atlas-open-tab')).toBe(false);
    expect(link.hasAttribute('data-atlas-marked')).toBe(false);
    expect(link.hasAttribute('data-atlas-state')).toBe(false);
    expect(link.hasAttribute('data-atlas-reaction')).toBe(false);
  });
});
