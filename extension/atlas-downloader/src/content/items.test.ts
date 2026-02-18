// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { buildItemFromElement, collectLookupKeysForNode } from './items';

function setLocation(url: string) {
  window.history.pushState({}, '', url);
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
    setLocation('https://www.deviantart.com/havenpoint/art/Adoptable-123#atlas');
    const img = document.createElement('img');
    img.src = '/images/foo.png';
    setImageSize(img, 1200, 1200);

    const item = buildItemFromElement(img, 450);
    expect(item).not.toBeNull();
    expect(item?.url).toBe('https://www.deviantart.com/images/foo.png');
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

  it('collects lookup keys from media, anchor, and page', () => {
    setLocation('https://www.deviantart.com/artist/art/Example-1#hash');

    const anchor = document.createElement('a');
    anchor.href = '/artist/art/Example-1';
    const img = document.createElement('img');
    img.src = 'https://images.example.com/foo.jpg';
    setImageSize(img, 1200, 1200);
    anchor.appendChild(img);
    document.body.appendChild(anchor);

    const keys = collectLookupKeysForNode(img);
    expect(keys).toContain('https://images.example.com/foo.jpg');
    expect(keys).toContain('https://www.deviantart.com/artist/art/Example-1');
    expect(keys).toContain('https://www.deviantart.com/artist/art/Example-1#hash');
  });
});
