import { describe, expect, it } from 'vitest';
import { buildReactionBroadcastEvent } from './reactionEvent';

describe('buildReactionBroadcastEvent', () => {
  it('uses file lookup metadata from response when available', () => {
    const event = buildReactionBroadcastEvent(
      {
        url: 'https://example.com/media/file.jpg',
        referrer_url: 'https://example.com/fallback-referrer',
        preview_url: 'https://example.com/fallback-preview.jpg',
        type: 'like',
      },
      {
        data: {
          file: {
            downloaded: true,
            blacklisted_at: null,
            download_progress: 100,
            downloaded_at: '2026-02-25T10:20:00+00:00',
            referrer_url: 'https://example.com/art/page',
            preview_url: 'https://example.com/previews/file-thumb.jpg',
          },
          reaction: {
            type: 'love',
          },
        },
      }
    );

    expect(event).toEqual({
      url: 'https://example.com/media/file.jpg',
      referrerUrl: 'https://example.com/art/page',
      previewUrl: 'https://example.com/previews/file-thumb.jpg',
      reactionType: 'love',
      downloaded: true,
      blacklisted: false,
      downloadProgress: 100,
      downloadedAt: '2026-02-25T10:20:00+00:00',
    });
  });

  it('falls back to request payload metadata when file metadata is unavailable', () => {
    const event = buildReactionBroadcastEvent(
      {
        url: 'https://example.com/media/file.jpg',
        referrer_url: 'https://example.com/art/page',
        preview_url: 'https://example.com/previews/file-thumb.jpg',
        type: 'funny',
      },
      {
        data: {
          file: null,
          reaction: null,
        },
      }
    );

    expect(event?.referrerUrl).toBe('https://example.com/art/page');
    expect(event?.previewUrl).toBe('https://example.com/previews/file-thumb.jpg');
    expect(event?.reactionType).toBe('funny');
    expect(event?.downloaded).toBe(false);
  });

  it('returns null when request payload does not include a URL', () => {
    expect(buildReactionBroadcastEvent({ type: 'love' }, {})).toBeNull();
    expect(buildReactionBroadcastEvent(null, null)).toBeNull();
  });
});
