// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { buildItemFromElement, collectLookupKeysForNode } from './items';

function setLocation(url: string) {
  const next = new URL(url, window.location.origin);
  window.history.pushState({}, '', `${next.pathname}${next.search}${next.hash}`);
}

function setImageSize(img: HTMLImageElement, width: number, height: number) {
  Object.defineProperty(img, 'naturalWidth', { value: width, configurable: true });
  Object.defineProperty(img, 'naturalHeight', { value: height, configurable: true });
}

function setVideoSize(video: HTMLVideoElement, width: number, height: number) {
  Object.defineProperty(video, 'videoWidth', { value: width, configurable: true });
  Object.defineProperty(video, 'videoHeight', { value: height, configurable: true });
}

describe('items', () => {
  it('builds image items with page referrer and absolute urls', () => {
    setLocation('/havenpoint/art/Adoptable-123#atlas');
    const img = document.createElement('img');
    img.src = '/images/foo.png';
    setImageSize(img, 1200, 1200);

    const item = buildItemFromElement(img, 450);
    expect(item).not.toBeNull();
    expect(item?.url).toBe(`${window.location.origin}/images/foo.png`);
    expect(item?.referrer_url).toBe(window.location.href);
  });

  it('filters small raster images but keeps gifs', () => {
    setLocation('https://example.com/page');
    const small = document.createElement('img');
    small.src = 'https://example.com/foo.png';
    setImageSize(small, 200, 200);

    expect(buildItemFromElement(small, 450)).toBeNull();

    const gif = document.createElement('img');
    gif.src = 'https://example.com/foo.gif';
    setImageSize(gif, 200, 200);

    const item = buildItemFromElement(gif, 450);
    expect(item).not.toBeNull();
    expect(item?.tag_name).toBe('img');
  });

  it('builds video items', () => {
    setLocation('https://example.com/page');
    const video = document.createElement('video');
    video.src = 'https://cdn.example.com/foo.mp4';
    setVideoSize(video, 1280, 720);

    const item = buildItemFromElement(video, 450);
    expect(item).not.toBeNull();
    expect(item?.tag_name).toBe('video');
    expect(item?.url).toBe('https://cdn.example.com/foo.mp4');
  });

  it('filters small videos', () => {
    setLocation('https://example.com/page');
    const video = document.createElement('video');
    video.src = 'https://cdn.example.com/small.mp4';
    setVideoSize(video, 320, 320);

    expect(buildItemFromElement(video, 400)).toBeNull();
  });

  it('collects lookup keys from media and meaningful anchor href', () => {
    setLocation('/artist/art/Example-1#hash');

    const anchor = document.createElement('a');
    anchor.href = '/artist/art/Example-1';
    const img = document.createElement('img');
    img.src = 'https://images.example.com/foo.jpg';
    setImageSize(img, 1200, 1200);
    anchor.appendChild(img);
    document.body.appendChild(anchor);

    const keys = collectLookupKeysForNode(img);
    expect(keys).toContain('https://images.example.com/foo.jpg');
    expect(keys).toContain(`${window.location.origin}/artist/art/Example-1`);
    expect(keys).not.toContain(`${window.location.origin}/artist/art/Example-1#hash`);
  });

  it('ignores hash-only anchor href values for lookup keys', () => {
    setLocation('/artist/art/Example-1#hash');

    const anchor = document.createElement('a');
    anchor.setAttribute('href', '#');
    const img = document.createElement('img');
    img.src = 'https://images.example.com/foo.jpg';
    setImageSize(img, 1200, 1200);
    anchor.appendChild(img);
    document.body.appendChild(anchor);

    const keys = collectLookupKeysForNode(img);
    expect(keys).toContain('https://images.example.com/foo.jpg');
    expect(keys).not.toContain(window.location.href);
    expect(keys).not.toContain(`${window.location.origin}/artist/art/Example-1`);
  });

  it('adds page url as lookup key for blob media nodes', () => {
    setLocation('/artist/art/Example-1#hash');

    const img = document.createElement('img');
    img.src = 'blob:https://example.com/abc-123';
    setImageSize(img, 1200, 1200);
    document.body.appendChild(img);

    const keys = collectLookupKeysForNode(img);
    expect(keys).toContain(window.location.href);
  });
});
