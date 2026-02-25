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

function makeWixUrl(assetKey: string, size: string, token: string, folderId = 'abc123'): string {
  return `https://images-wixmp-ed30a86b8c4ca887773594c2.wixmp.com/f/${folderId}/${assetKey}.jpg/v1/fit/${size},q_70,strp/${assetKey}.jpg?token=${token}`;
}

function appendPreload(href: string) {
  const link = document.createElement('link');
  link.setAttribute('rel', 'preload');
  link.setAttribute('as', 'image');
  link.setAttribute('href', href);
  document.head.appendChild(link);
}

function setRect(element: Element, left: number, top: number, width: number, height: number) {
  Object.defineProperty(element, 'getBoundingClientRect', {
    value: () => ({
      x: left,
      y: top,
      left,
      top,
      width,
      height,
      right: left + width,
      bottom: top + height,
      toJSON: () => ({}),
    }),
    configurable: true,
  });
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
    const unrelated = makeWixUrl('dk8ebq8-3333', 'w_150,h_150', token, 'other123');

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

  it('groups child images that share wix collection id even when prefixes differ', () => {
    const token = makeToken(1280, 1920);
    const primary = makeWixUrl('dl6jv53-1111', 'w_150,h_150', token);
    const childDifferentPrefix = makeWixUrl('dljlgm8-2222', 'w_150,h_150', token);
    const unrelated = makeWixUrl('dk8ebq8-3333', 'w_150,h_150', token, 'other123');

    const ogImage = document.createElement('meta');
    ogImage.setAttribute('property', 'og:image');
    ogImage.setAttribute('content', primary);
    document.head.appendChild(ogImage);

    appendPreload(primary);
    appendPreload(childDifferentPrefix);
    appendPreload(unrelated);

    const context = resolveDeviantArtPostContext('https://www.deviantart.com/user/art/example-123456789');
    expect(context).not.toBeNull();
    expect(context?.entries).toHaveLength(2);
    expect(context?.entryByAssetKey.has('dl6jv53-1111')).toBe(true);
    expect(context?.entryByAssetKey.has('dljlgm8-2222')).toBe(true);
  });

  it('returns null outside deviantart deviation pages', () => {
    const token = makeToken(1280, 1920);
    appendPreload(makeWixUrl('dl6jv53-1111', 'w_150,h_150', token));
    appendPreload(makeWixUrl('dl6jv53-2222', 'w_150,h_150', token));

    expect(resolveDeviantArtPostContext('https://example.com/not-deviantart')).toBeNull();
  });

  it('prefers scoped gallery roots and excludes unrelated same-collection images', () => {
    const token = makeToken(1280, 1920);
    const primary = makeWixUrl('dl6jv53-1111', 'w_300,h_300', token);
    const child = makeWixUrl('dl6jv53-2222', 'w_300,h_300', token);
    const unrelated = makeWixUrl('dl6jv53-9999', 'w_300,h_300', token);

    const ogImage = document.createElement('meta');
    ogImage.setAttribute('property', 'og:image');
    ogImage.setAttribute('content', primary);
    document.head.appendChild(ogImage);

    const mainImage = document.createElement('img');
    mainImage.setAttribute('src', primary);
    document.body.appendChild(mainImage);

    const galleryRoot = document.createElement('div');
    galleryRoot.className = 'IUfj2J qeNdP5 bOFPMd';
    const itemA = document.createElement('div');
    itemA.className = 'NpoINo';
    const itemAImage = document.createElement('img');
    itemAImage.setAttribute('src', primary);
    itemA.appendChild(itemAImage);
    const itemB = document.createElement('div');
    itemB.className = 'NpoINo';
    const itemBImage = document.createElement('img');
    itemBImage.setAttribute('src', child);
    itemB.appendChild(itemBImage);
    galleryRoot.append(itemA, itemB);
    document.body.appendChild(galleryRoot);

    const unrelatedBlock = document.createElement('div');
    const unrelatedImage = document.createElement('img');
    unrelatedImage.setAttribute('src', unrelated);
    unrelatedBlock.appendChild(unrelatedImage);
    document.body.appendChild(unrelatedBlock);

    const context = resolveDeviantArtPostContext(
      'https://www.deviantart.com/user/art/example-123456789',
      mainImage
    );
    expect(context).not.toBeNull();
    expect(context?.entries).toHaveLength(2);
    expect(context?.entryByAssetKey.has('dl6jv53-1111')).toBe(true);
    expect(context?.entryByAssetKey.has('dl6jv53-2222')).toBe(true);
    expect(context?.entryByAssetKey.has('dl6jv53-9999')).toBe(false);
  });

  it('prefers nearby thumbnail rail when broad ancestors include unrelated wix images', () => {
    const token = makeToken(1280, 1920);
    const primary = makeWixUrl('dl6jv53-1111', 'w_300,h_300', token);
    const childA = makeWixUrl('dl6jv53-2222', 'w_300,h_300', token);
    const childB = makeWixUrl('dl6jv53-3333', 'w_300,h_300', token);
    const childC = makeWixUrl('dl6jv53-4444', 'w_300,h_300', token);
    const unrelated = Array.from({ length: 11 }, (_, index) =>
      makeWixUrl(`dl6jv53-unrelated-${index + 1}`, 'w_300,h_300', token)
    );

    const ogImage = document.createElement('meta');
    ogImage.setAttribute('property', 'og:image');
    ogImage.setAttribute('content', primary);
    document.head.appendChild(ogImage);

    const pageRoot = document.createElement('div');
    document.body.appendChild(pageRoot);

    const mainSection = document.createElement('div');
    const mainImage = document.createElement('img');
    mainImage.setAttribute('src', primary);
    mainSection.appendChild(mainImage);
    pageRoot.appendChild(mainSection);

    const thumbRail = document.createElement('div');
    for (const url of [primary, childA, childB, childC]) {
      const img = document.createElement('img');
      img.setAttribute('src', url);
      thumbRail.appendChild(img);
    }
    pageRoot.appendChild(thumbRail);

    const unrelatedBlock = document.createElement('div');
    for (const url of unrelated) {
      const img = document.createElement('img');
      img.setAttribute('src', url);
      unrelatedBlock.appendChild(img);
    }
    pageRoot.appendChild(unrelatedBlock);

    setRect(mainImage, 80, 100, 640, 640);
    setRect(thumbRail, 80, 760, 640, 140);
    setRect(unrelatedBlock, 80, 1040, 640, 500);

    const context = resolveDeviantArtPostContext(
      'https://www.deviantart.com/user/art/example-123456789',
      mainImage
    );
    expect(context).not.toBeNull();
    expect(context?.entries).toHaveLength(4);
    expect(context?.entryByAssetKey.has('dl6jv53-1111')).toBe(true);
    expect(context?.entryByAssetKey.has('dl6jv53-2222')).toBe(true);
    expect(context?.entryByAssetKey.has('dl6jv53-3333')).toBe(true);
    expect(context?.entryByAssetKey.has('dl6jv53-4444')).toBe(true);
    expect(context?.entryByAssetKey.has('dl6jv53-unrelated-1')).toBe(false);
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
