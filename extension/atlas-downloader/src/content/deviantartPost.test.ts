// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import {
  deriveLargestWixmpUrl,
  resolveBestDeviantArtPostDownloadUrl,
  resolveDeviantArtPostContext,
  resolveWixAssetKey,
} from './deviantartPost';

function encodeTokenPayload(payload: unknown): string {
  return btoa(JSON.stringify(payload))
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/g, '');
}

function makeToken(maxWidth: number, maxHeight: number): string {
  const header = encodeTokenPayload({ typ: 'JWT', alg: 'HS256' });
  const payload = encodeTokenPayload({
    obj: [[{ width: `<=${maxWidth}`, height: `<=${maxHeight}` }]],
  });
  return `${header}.${payload}.sig`;
}

function makeWixUrl(assetKey: string, size: string, token: string): string {
  return `https://images-wixmp-ed30a86b8c4ca887773594c2.wixmp.com/f/abc123/${assetKey}.jpg/v1/fit/${size},q_70,strp/${assetKey}.jpg?token=${token}`;
}

function appendPreload(href: string) {
  const link = document.createElement('link');
  link.setAttribute('rel', 'preload');
  link.setAttribute('as', 'image');
  link.setAttribute('href', href);
  document.head.appendChild(link);
}

afterEach(() => {
  document.head.innerHTML = '';
  document.body.innerHTML = '';
});

describe('resolveWixAssetKey', () => {
  it('extracts the wix asset key from media urls', () => {
    const token = makeToken(1280, 1920);
    const url = makeWixUrl('dl6jv53-125a81b6-c349-4e76-838d-34c2ed528219', 'w_150,h_150', token);
    expect(resolveWixAssetKey(url)).toBe('dl6jv53-125a81b6-c349-4e76-838d-34c2ed528219');
  });
});

describe('resolveDeviantArtPostContext', () => {
  it('returns a grouped context for multi-image posts', () => {
    const token = makeToken(1280, 1920);
    const primary = makeWixUrl('dl6jv53-1111', 'w_150,h_150', token);
    const child = makeWixUrl('dl6jv53-2222', 'w_150,h_150', token);
    const unrelated = makeWixUrl('dk8ebq8-3333', 'w_150,h_150', token);

    const ogImage = document.createElement('meta');
    ogImage.setAttribute('property', 'og:image');
    ogImage.setAttribute('content', primary);
    document.head.appendChild(ogImage);

    appendPreload(primary);
    appendPreload(child);
    appendPreload(unrelated);

    const context = resolveDeviantArtPostContext('https://www.deviantart.com/user/art/example-123456789');
    expect(context).not.toBeNull();
    expect(context?.entries).toHaveLength(2);
    expect(context?.entryByAssetKey.has('dl6jv53-1111')).toBe(true);
    expect(context?.entryByAssetKey.has('dl6jv53-2222')).toBe(true);
  });

  it('returns null outside deviantart deviation pages', () => {
    const token = makeToken(1280, 1920);
    appendPreload(makeWixUrl('dl6jv53-1111', 'w_150,h_150', token));
    appendPreload(makeWixUrl('dl6jv53-2222', 'w_150,h_150', token));

    expect(resolveDeviantArtPostContext('https://example.com/not-deviantart')).toBeNull();
  });
});

describe('resolveBestDeviantArtPostDownloadUrl', () => {
  it('upgrades to a larger wixmp fit url when token max size is larger', () => {
    const token = makeToken(1280, 1920);
    const baseUrl = makeWixUrl('dl6jv53-1111', 'w_150,h_150', token);

    const resolved = resolveBestDeviantArtPostDownloadUrl({
      assetKey: 'dl6jv53-1111',
      previewUrl: baseUrl,
      baseUrl,
      width: 150,
      height: 150,
      maxWidth: 1280,
      maxHeight: 1920,
    });

    expect(resolved).toContain('/v1/fit/w_1280,h_1920,q_70,strp/');
  });

  it('keeps the base url when no larger dimensions are available', () => {
    const token = makeToken(150, 150);
    const baseUrl = makeWixUrl('dl6jv53-1111', 'w_150,h_150', token);

    const resolved = resolveBestDeviantArtPostDownloadUrl({
      assetKey: 'dl6jv53-1111',
      previewUrl: baseUrl,
      baseUrl,
      width: 150,
      height: 150,
      maxWidth: 150,
      maxHeight: 150,
    });

    expect(resolved).toBe(baseUrl);
  });
});

describe('deriveLargestWixmpUrl', () => {
  it('rewrites fit/crop segments to the requested max size', () => {
    const token = makeToken(1280, 1920);
    const url = makeWixUrl('dl6jv53-1111', 'w_150,h_150', token);
    expect(deriveLargestWixmpUrl(url, 1280, 1920)).toContain('/v1/fit/w_1280,h_1920,q_70,strp/');
  });
});
