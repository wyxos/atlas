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

  while (node && depth < 8) {
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

    node = node.parentElement;
    depth += 1;
  }

  return false;
}

export function shouldBypassMinSize(img: HTMLImageElement, url: string): boolean {
  if (isMinSizeExemptImageUrl(url)) {
    return true;
  }

  if (isElementInModal(img)) {
    return true;
  }

  return false;
}
