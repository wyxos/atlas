// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { isElementInModal, resolveAbsoluteUrl, shouldBypassMinSize } from './media';

function setLocation(url: string) {
  window.history.pushState({}, '', url);
}

describe('media utils', () => {
  it('resolves absolute urls relative to the page', () => {
    setLocation('https://www.deviantart.com/havenpoint/art/Adoptable-123');
    expect(resolveAbsoluteUrl('/art/foo', window.location.href)).toBe(
      'https://www.deviantart.com/art/foo'
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
