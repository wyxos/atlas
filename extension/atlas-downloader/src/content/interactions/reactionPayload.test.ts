// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { buildReactionPayloadFromMedia } from './reactionPayload';
import type { InteractionDependencies } from './shared';

function makeDeps(minWidth: number): InteractionDependencies {
  return {
    rootId: 'atlas-downloader-root',
    minWidth,
    maxMetadataLen: 255,
    limitString: (value) => String(value ?? ''),
    sourceFromMediaUrl: () => 'web',
    fetchAtlasStatus: (_send, _url, _referrerUrl, callback) => callback(null),
    atlasStatusCache: new Map(),
    getCachedAtlasStatus: () => null,
  };
}

describe('buildReactionPayloadFromMedia', () => {
  it('returns null for blob video media below the configured min width', () => {
    const video = document.createElement('video');
    video.src = 'blob:https://atlas.test/small';
    Object.defineProperty(video, 'videoWidth', { value: 240, configurable: true });
    Object.defineProperty(video, 'videoHeight', { value: 135, configurable: true });

    const payload = buildReactionPayloadFromMedia(video, 'like', makeDeps(320));
    expect(payload).toBeNull();
  });

  it('keeps blob video fallback when width meets configured min width', () => {
    const video = document.createElement('video');
    video.src = 'blob:https://atlas.test/large';
    Object.defineProperty(video, 'videoWidth', { value: 640, configurable: true });
    Object.defineProperty(video, 'videoHeight', { value: 360, configurable: true });

    const payload = buildReactionPayloadFromMedia(video, 'like', makeDeps(320));
    expect(payload).not.toBeNull();
    expect(payload?.width).toBe(640);
  });

  it('falls back to page-url yt-dlp mode when video has a non-http source', () => {
    const video = document.createElement('video');
    video.src = 'mediastream:facebook-reel';
    Object.defineProperty(video, 'videoWidth', { value: 720, configurable: true });
    Object.defineProperty(video, 'videoHeight', { value: 1280, configurable: true });

    const payload = buildReactionPayloadFromMedia(video, 'like', makeDeps(320));
    expect(payload).not.toBeNull();
    expect(payload?.url).toBe(window.location.href);
    expect(payload?.download_via).toBe('yt-dlp');
  });
});
