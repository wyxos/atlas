// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { buildItemFromElement, collectLookupKeysForNode, configureMediaNoiseFilters } from './items';

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
  afterEach(() => {
    configureMediaNoiseFilters('');
  });

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

  it('filters small raster images including gifs', () => {
    setLocation('https://example.com/page');
    const small = document.createElement('img');
    small.src = 'https://example.com/foo.png';
    setImageSize(small, 200, 200);

    expect(buildItemFromElement(small, 450)).toBeNull();

    const gif = document.createElement('img');
    gif.src = 'https://example.com/foo.gif';
    setImageSize(gif, 200, 200);

    expect(buildItemFromElement(gif, 450)).toBeNull();
  });

  it('uses wixmp size hints when natural dimensions are larger than rendered size', () => {
    setLocation('https://www.deviantart.com/chrisis-ai/art/Orange-hair-squad-go-1300543999#image-1');

    const img = document.createElement('img');
    img.src = 'https://images-wixmp-ed30a86b8c4ca887773594c2.wixmp.com/f/8eee0575-fbad-44dc-aafe-d4ebbaa3ce43/dlib667-5de41a77-dec1-4f4a-bd6a-4f6bd2ed7912.png/v1/fit/w_828,h_1212,q_70,strp/orange_hair_squad__go__by_chrisis_ai_dlib667-414w-2x.jpg?token=abc';
    Object.defineProperty(img, 'naturalWidth', { value: 328, configurable: true });
    Object.defineProperty(img, 'naturalHeight', { value: 481, configurable: true });
    Object.defineProperty(img, 'clientWidth', { value: 328, configurable: true });
    Object.defineProperty(img, 'clientHeight', { value: 481, configurable: true });

    const item = buildItemFromElement(img, 200);
    expect(item).not.toBeNull();
    expect(item?.width).toBe(828);
    expect(item?.height).toBe(1212);
  });

  it('excludes built-in deviantart noise hosts', () => {
    setLocation('https://www.deviantart.com/chrisis-ai/art/Orange-hair-squad-go-1300543999#image-1');
    const img = document.createElement('img');
    img.src = 'https://st.deviantart.net/eclipse/popups/hover-component/2024/deviation-2x.png';
    setImageSize(img, 700, 700);

    expect(buildItemFromElement(img, 200)).toBeNull();
  });

  it('filters images below 200px when min size is 200', () => {
    setLocation('https://example.com/page');
    const img = document.createElement('img');
    img.src = 'https://example.com/tiny.png';
    setImageSize(img, 199, 400);

    expect(buildItemFromElement(img, 200)).toBeNull();
  });

  it('does not bypass minimum size for modal-contained thumbnails', () => {
    setLocation('https://www.deviantart.com/chrisis-ai/art/Orange-hair-squad-go-1300543999#image-1');

    const modal = document.createElement('div');
    modal.className = 'lightbox-modal';
    const img = document.createElement('img');
    img.src = 'https://images-wixmp-ed30a86b8c4ca887773594c2.wixmp.com/f/example/v1/fit/w_150,h_150,q_70,strp/thumb.jpg';
    setImageSize(img, 150, 150);
    modal.appendChild(img);
    document.body.appendChild(modal);

    expect(buildItemFromElement(img, 200)).toBeNull();
  });

  it('applies custom media noise filters', () => {
    setLocation('https://www.deviantart.com/chrisis-ai/art/Orange-hair-squad-go-1300543999#image-1');
    configureMediaNoiseFilters('url:*orange_hair_squad__go*');

    const img = document.createElement('img');
    img.src = 'https://images-wixmp-ed30a86b8c4ca887773594c2.wixmp.com/f/8eee0575-fbad-44dc-aafe-d4ebbaa3ce43/dlib667-5de41a77-dec1-4f4a-bd6a-4f6bd2ed7912.png/v1/fit/w_828,h_1212,q_70,strp/orange_hair_squad__go__by_chrisis_ai_dlib667-414w-2x.jpg?token=abc';
    setImageSize(img, 828, 1212);

    expect(buildItemFromElement(img, 200)).toBeNull();
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
