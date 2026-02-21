// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { resolveMediaAtPoint } from './interactions';

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
});
