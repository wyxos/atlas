// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { isElementInModal, resolveAbsoluteUrl, shouldBypassMinSize } from './media';

function setLocation(url: string) {
  const next = new URL(url, window.location.origin);
  window.history.pushState({}, '', `${next.pathname}${next.search}${next.hash}`);
}

describe('media utils', () => {
  it('resolves absolute urls relative to the page', () => {
    setLocation('/havenpoint/art/Adoptable-123');
    expect(resolveAbsoluteUrl('/art/foo', window.location.href)).toBe(
      `${window.location.origin}/art/foo`
    );
  });

  it('detects modal containers', () => {
    const modal = document.createElement('div');
    modal.setAttribute('role', 'dialog');
    const img = document.createElement('img');
    modal.appendChild(img);
    document.body.appendChild(modal);

    expect(isElementInModal(img)).toBe(true);
  });

  it('detects deep fixed overlay containers', () => {
    const overlay = document.createElement('div');
    overlay.style.position = 'fixed';
    overlay.style.inset = '0';
    overlay.style.width = '100vw';
    overlay.style.height = '100vh';

    let parent: Element = overlay;
    for (let i = 0; i < 12; i += 1) {
      const wrapper = document.createElement('div');
      parent.appendChild(wrapper);
      parent = wrapper;
    }

    const img = document.createElement('img');
    parent.appendChild(img);
    document.body.appendChild(overlay);

    expect(isElementInModal(img)).toBe(true);
  });

  it('bypasses min size for gif/webp and modal images', () => {
    const img = document.createElement('img');
    img.src = 'https://example.com/foo.gif';
    expect(shouldBypassMinSize(img, img.src)).toBe(true);

    const modal = document.createElement('div');
    modal.className = 'lightbox-modal';
    const modalImg = document.createElement('img');
    modalImg.src = 'https://example.com/foo.jpg';
    modal.appendChild(modalImg);
    document.body.appendChild(modal);

    expect(shouldBypassMinSize(modalImg, modalImg.src)).toBe(true);
  });
});
