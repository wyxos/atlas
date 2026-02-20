export function resolveAbsoluteUrl(value: string, baseUrl: string): string {
  if (!value || typeof value !== 'string') {
    return '';
  }

  const trimmed = value.trim();
  if (!trimmed) {
    return '';
  }

  const lowered = trimmed.toLowerCase();
  if (
    lowered.startsWith('blob:') ||
    lowered.startsWith('data:') ||
    lowered.startsWith('chrome-extension:') ||
    lowered.startsWith('moz-extension:') ||
    lowered.startsWith('safari-extension:')
  ) {
    return '';
  }

  try {
    const resolved = new URL(trimmed, baseUrl);
    if (resolved.protocol !== 'http:' && resolved.protocol !== 'https:') {
      return '';
    }
    return resolved.toString();
  } catch {
    return '';
  }
}

export function isMinSizeExemptImageUrl(url: string): boolean {
  return /\.(gif|webp)(\?|#|$)/i.test(url || '');
}

export function isElementInModal(element: Element): boolean {
  let node: Element | null = element;
  let depth = 0;

  while (node && depth < 24) {
    const role = node.getAttribute?.('role');
    if (role === 'dialog' || role === 'alertdialog') {
      return true;
    }

    const ariaModal = node.getAttribute?.('aria-modal');
    if (ariaModal === 'true') {
      return true;
    }

    const className = (node.getAttribute?.('class') || '').toLowerCase();
    const id = (node.getAttribute?.('id') || '').toLowerCase();
    const hint = `${className} ${id}`.trim();
    if (hint && /(modal|lightbox|overlay|dialog)/.test(hint)) {
      return true;
    }

    const style = window.getComputedStyle(node);
    if ((style.position === 'fixed' || style.position === 'sticky') && isViewportSized(node)) {
      return true;
    }

    node = node.parentElement;
    depth += 1;
  }

  return false;
}

export function shouldBypassMinSize(img: HTMLImageElement, url: string): boolean {
  void url;

  if (isElementInModal(img)) {
    return true;
  }

  return false;
}

function isViewportSized(node: Element): boolean {
  const rect = node.getBoundingClientRect();
  if (rect.width > 0 && rect.height > 0) {
    const minViewportWidth = Math.max(window.innerWidth * 0.4, 320);
    const minViewportHeight = Math.max(window.innerHeight * 0.4, 240);

    return rect.width >= minViewportWidth && rect.height >= minViewportHeight;
  }

  // jsdom (tests) doesn't compute layout; fall back to explicit overlay-style hints.
  if (node instanceof HTMLElement) {
    const style = node.style;
    const hasInsetZero =
      style.inset === '0' ||
      style.inset === '0px' ||
      ((style.top === '0' || style.top === '0px') &&
        (style.left === '0' || style.left === '0px') &&
        (style.right === '0' || style.right === '0px') &&
        (style.bottom === '0' || style.bottom === '0px'));
    const hasFullSizeHint =
      /^(100vw|100%)$/i.test(style.width || '') || /^(100vh|100%)$/i.test(style.height || '');

    return hasInsetZero || hasFullSizeHint;
  }

  return false;
}
