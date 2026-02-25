// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import {
  buildStatusMapFromCache,
  clearNodeMarkerAttributes,
  findStatusForLookupKeys,
  mergeSheetItemStatuses,
  syncMarkerRails,
  syncOpenTabIconBadges,
  syncPageVisitedBadge,
  syncReactionIconBadges,
} from './pageMarkers';

function stripHash(value: string): string {
  return (value || '').split('#', 1)[0];
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

  it('keeps #image-N cache entries hash-specific to avoid page-wide marker bleed', () => {
    const now = Date.now();
    const cache = new Map([
      [
        'https://www.deviantart.com/user/art/example-123#image-1',
        {
          exists: true,
          downloaded: false,
          blacklisted: false,
          reactionType: 'like',
          ts: now,
        },
      ],
    ]);

    const statusByUrl = buildStatusMapFromCache(cache, 30_000, stripHash);
    expect(statusByUrl.has('https://www.deviantart.com/user/art/example-123#image-1')).toBe(true);
    expect(statusByUrl.has('https://www.deviantart.com/user/art/example-123')).toBe(false);
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

  it('renders reaction icon badges inside the marker host', () => {
    const host = document.createElement('div');
    const img = document.createElement('img');
    img.setAttribute('data-atlas-reaction', 'like');
    host.appendChild(img);
    document.body.appendChild(host);

    syncReactionIconBadges([img]);

    expect(host.querySelectorAll('[data-atlas-marker-badge="reaction"]')).toHaveLength(1);
    expect(host.querySelector('.atlas-downloader-reaction-badge.like')).not.toBeNull();
    expect(document.getElementById('atlas-downloader-reaction-badge-layer')).toBeNull();
  });

  it('renders open-tab icon badges inside the marker host and clears them when empty', () => {
    const link = document.createElement('a');
    document.body.appendChild(link);

    syncOpenTabIconBadges([link]);

    expect(link.querySelectorAll('[data-atlas-marker-badge="open-tab"]')).toHaveLength(1);
    expect(link.querySelectorAll('.atlas-downloader-open-tab-badge')).toHaveLength(1);
    expect(document.getElementById('atlas-downloader-open-tab-badge-layer')).toBeNull();

    syncOpenTabIconBadges([]);
    expect(link.querySelectorAll('[data-atlas-marker-badge="open-tab"]')).toHaveLength(0);
  });

  it('renders four marker rails on the host and forces host to relative', () => {
    const host = document.createElement('div');
    const img = document.createElement('img');
    img.setAttribute('data-atlas-marked', '1');
    img.setAttribute('data-atlas-state', 'downloaded');
    host.appendChild(img);
    document.body.appendChild(host);

    syncMarkerRails([img]);

    expect(host.style.position).toBe('relative');
    expect(host.querySelectorAll('[data-atlas-marker-rail="1"]')).toHaveLength(4);
    expect(host.querySelectorAll('.atlas-downloader-marker-rail-top')).toHaveLength(1);
    expect(host.querySelectorAll('.atlas-downloader-marker-rail-right')).toHaveLength(1);
    expect(host.querySelectorAll('.atlas-downloader-marker-rail-bottom')).toHaveLength(1);
    expect(host.querySelectorAll('.atlas-downloader-marker-rail-left')).toHaveLength(1);
  });

  it('clears marker attributes and marker decorations', () => {
    const host = document.createElement('div');
    const link = document.createElement('a');
    link.setAttribute('data-atlas-open-tab', '1');
    link.setAttribute('data-atlas-marked', '1');
    link.setAttribute('data-atlas-state', 'exists');
    link.setAttribute('data-atlas-reaction', 'like');
    host.appendChild(link);
    document.body.appendChild(host);

    syncMarkerRails([link]);
    syncReactionIconBadges([link]);
    syncOpenTabIconBadges([link]);

    clearNodeMarkerAttributes([link]);
    expect(link.hasAttribute('data-atlas-open-tab')).toBe(false);
    expect(link.hasAttribute('data-atlas-marked')).toBe(false);
    expect(link.hasAttribute('data-atlas-state')).toBe(false);
    expect(link.hasAttribute('data-atlas-reaction')).toBe(false);
    expect(host.querySelector('[data-atlas-marker-rail="1"]')).toBeNull();
    expect(host.querySelector('[data-atlas-marker-badge]')).toBeNull();
    expect(link.style.position).toBe('');
  });
});
