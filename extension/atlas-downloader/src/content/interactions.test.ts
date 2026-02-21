// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { choosePromotedMediaCandidate, installHotkeys, resolveMediaAtPoint } from './interactions';

function rect(left: number, top: number, width: number, height: number): DOMRect {
  const right = left + width;
  const bottom = top + height;
  return {
    x: left,
    y: top,
    left,
    top,
    right,
    bottom,
    width,
    height,
    toJSON: () => ({}),
  } as DOMRect;
}

function setRect(element: Element, value: DOMRect) {
  Object.defineProperty(element, 'getBoundingClientRect', {
    value: () => value,
    configurable: true,
  });
}

const originalElementsFromPoint = document.elementsFromPoint;
const originalElementFromPoint = document.elementFromPoint;

afterEach(() => {
  document.body.innerHTML = '';
  Object.defineProperty(document, 'elementsFromPoint', {
    value: originalElementsFromPoint,
    configurable: true,
  });
  Object.defineProperty(document, 'elementFromPoint', {
    value: originalElementFromPoint,
    configurable: true,
  });
});

describe('resolveMediaAtPoint', () => {
  it('returns direct media from pointer stack', () => {
    const img = document.createElement('img');
    document.body.appendChild(img);

    Object.defineProperty(document, 'elementsFromPoint', {
      value: () => [img],
      configurable: true,
    });

    const resolved = resolveMediaAtPoint(100, 100, 'atlas-downloader-root');
    expect(resolved).toBe(img);
  });

  it('picks largest matching nested media when only wrapper is in stack', () => {
    const wrapper = document.createElement('div');
    const small = document.createElement('img');
    const large = document.createElement('img');
    wrapper.append(small, large);
    document.body.appendChild(wrapper);

    setRect(small, rect(0, 0, 140, 140));
    setRect(large, rect(0, 0, 520, 820));

    Object.defineProperty(document, 'elementsFromPoint', {
      value: () => [wrapper],
      configurable: true,
    });

    const resolved = resolveMediaAtPoint(120, 120, 'atlas-downloader-root');
    expect(resolved).toBe(large);
  });

  it('skips media that belongs to extension UI root', () => {
    const ownRoot = document.createElement('div');
    ownRoot.id = 'atlas-downloader-root';
    const ownImg = document.createElement('img');
    ownRoot.appendChild(ownImg);
    document.body.appendChild(ownRoot);

    const realImg = document.createElement('img');
    document.body.appendChild(realImg);

    Object.defineProperty(document, 'elementsFromPoint', {
      value: () => [ownImg, realImg],
      configurable: true,
    });

    const resolved = resolveMediaAtPoint(100, 100, 'atlas-downloader-root');
    expect(resolved).toBe(realImg);
  });

  it('prefers modal media over non-modal media when both are stacked at pointer', () => {
    const small = document.createElement('img');
    document.body.appendChild(small);
    setRect(small, rect(0, 0, 400, 600));

    const modal = document.createElement('div');
    modal.className = 'lightbox-modal';
    const large = document.createElement('img');
    modal.appendChild(large);
    document.body.appendChild(modal);
    setRect(large, rect(0, 0, 360, 520));

    Object.defineProperty(document, 'elementsFromPoint', {
      value: () => [small, large],
      configurable: true,
    });

    const resolved = resolveMediaAtPoint(100, 100, 'atlas-downloader-root');
    expect(resolved).toBe(large);
  });
});

describe('choosePromotedMediaCandidate', () => {
  it('promotes larger modal media when current media is a smaller thumbnail', () => {
    const thumb = document.createElement('img');
    document.body.appendChild(thumb);
    setRect(thumb, rect(20, 20, 180, 180));

    const modal = document.createElement('div');
    modal.className = 'lightbox-modal';
    const large = document.createElement('img');
    modal.appendChild(large);
    document.body.appendChild(modal);
    setRect(large, rect(300, 40, 620, 980));

    const promoted = choosePromotedMediaCandidate(thumb, 'atlas-downloader-root');
    expect(promoted).toBe(large);
  });

  it('does not promote when no significantly larger candidate exists', () => {
    const current = document.createElement('img');
    const nearby = document.createElement('img');
    document.body.append(current, nearby);

    setRect(current, rect(10, 10, 320, 320));
    setRect(nearby, rect(360, 10, 330, 330));

    const promoted = choosePromotedMediaCandidate(current, 'atlas-downloader-root');
    expect(promoted).toBeNull();
  });
});

describe('installHotkeys', () => {
  it('uses the event target media over pointer stack fallback for reactions', () => {
    const small = document.createElement('img');
    small.src = 'https://example.com/small.jpg';
    document.body.appendChild(small);
    setRect(small, rect(0, 0, 500, 700));
    Object.defineProperty(small, 'naturalWidth', { value: 500, configurable: true });
    Object.defineProperty(small, 'naturalHeight', { value: 700, configurable: true });

    const modal = document.createElement('div');
    modal.className = 'lightbox-modal';
    const large = document.createElement('img');
    large.src = 'https://example.com/large.jpg';
    modal.appendChild(large);
    document.body.appendChild(modal);
    setRect(large, rect(0, 0, 400, 560));
    Object.defineProperty(large, 'naturalWidth', { value: 400, configurable: true });
    Object.defineProperty(large, 'naturalHeight', { value: 560, configurable: true });

    Object.defineProperty(document, 'elementsFromPoint', {
      value: () => [small],
      configurable: true,
    });

    let reactedUrl: string | null = null;
    const sendMessageSafe = (message: unknown, callback: (response: { ok?: boolean; data?: unknown }) => void) => {
      const payload = (message as { payload?: { url?: string } }).payload;
      reactedUrl = payload?.url ?? null;
      callback({ ok: true, data: {} });
    };

    installHotkeys(
      {
        showToast: () => {},
        sendMessageSafe,
        isSheetOpen: () => false,
        chooseDialog: async () => 'cancel',
      },
      {
        rootId: 'atlas-downloader-root',
        minSize: 200,
        maxMetadataLen: 255,
        limitString: (value) => String(value ?? ''),
        sourceFromMediaUrl: () => 'web',
        fetchAtlasStatus: (_send, _url, _referrer, callback) => callback(null),
        atlasStatusCache: new Map(),
        getCachedAtlasStatus: () => null,
      }
    );

    large.dispatchEvent(
      new MouseEvent('mousedown', {
        bubbles: true,
        cancelable: true,
        altKey: true,
        button: 1,
        clientX: 12,
        clientY: 14,
      })
    );

    expect(reactedUrl).toBe('https://example.com/large.jpg');
  });
});
