import { resolveAbsoluteUrl, shouldBypassMinSize } from './media';

type MediaItem = {
  tag_name: 'img' | 'video';
  url: string;
  referrer_url: string;
  preview_url: string;
  width: number | null;
  height: number | null;
  alt: string;
  download_via?: string;
};

export function safeUrl(value: string): string {
  return resolveAbsoluteUrl(value, window.location.href);
}

export function getVideoUrl(video: HTMLVideoElement): string {
  const direct = safeUrl(video.currentSrc) || safeUrl(video.src) || safeUrl(video.getAttribute('src') || '');
  if (direct) {
    return direct;
  }

  const source = video.querySelector('source[src]');
  const sourceUrl = source ? safeUrl(source.src || source.getAttribute('src') || '') : '';
  if (sourceUrl) {
    return sourceUrl;
  }

  const dataStoreUrl = resolveDataStoreUrl(video);
  if (dataStoreUrl) {
    return dataStoreUrl;
  }

  return resolveMetaVideoUrl();
}

export function buildItemFromElement(element: Element, minSize: number): MediaItem | null {
  if (!(element instanceof Element)) {
    return null;
  }

  if (element.tagName === 'IMG') {
    const img = element as HTMLImageElement;
    const width = img.naturalWidth || img.width || img.clientWidth || null;
    const height = img.naturalHeight || img.height || img.clientHeight || null;
    const rawSrc = (img.currentSrc || img.src || img.getAttribute('src') || '').trim();
    const url = safeUrl(rawSrc);

    if (!shouldBypassMinSize(img, url || rawSrc)) {
      if (width && height && (width < minSize || height < minSize)) {
        return null;
      }
    }

    if (!url) {
      const fallback = safeUrl(document.referrer) || safeUrl(window.location.href) || '';
      if (!fallback || (!rawSrc.toLowerCase().startsWith('blob:') && !rawSrc.toLowerCase().startsWith('data:'))) {
        return null;
      }

      return {
        tag_name: 'img',
        url: fallback,
        referrer_url: window.location.href,
        preview_url: '',
        width,
        height,
        alt: img.alt || '',
      };
    }

    return {
      tag_name: 'img',
      url,
      referrer_url: window.location.href,
      preview_url: url,
      width,
      height,
      alt: img.alt || '',
    };
  }

  if (element.tagName === 'VIDEO') {
    const video = element as HTMLVideoElement;
    const url = getVideoUrl(video);
    if (!url) {
      const rawSrc = (video.currentSrc || video.src || '').trim().toLowerCase();
      if (rawSrc.startsWith('blob:') || rawSrc.startsWith('data:')) {
        const pageUrl = window.location.href;
        return {
          tag_name: 'video',
          url: pageUrl,
          referrer_url: pageUrl,
          preview_url: video.poster || '',
          width: video.videoWidth || video.clientWidth || null,
          height: video.videoHeight || video.clientHeight || null,
          alt: '',
          download_via: 'yt-dlp',
        };
      }
      return null;
    }

    return {
      tag_name: 'video',
      url,
      referrer_url: window.location.href,
      preview_url: video.poster || '',
      width: video.videoWidth || video.clientWidth || null,
      height: video.videoHeight || video.clientHeight || null,
      alt: '',
    };
  }

  return null;
}

export function buildDirectPageCandidate(): MediaItem | null {
  const locationUrl = (window.location.href || '').trim();
  if (!locationUrl) {
    return null;
  }

  const lowerLocation = locationUrl.toLowerCase();
  const mediaExtMatch = /\.(jpg|jpeg|png|gif|webp|bmp|svg|mp4|webm|mov|m4v|mkv)(\?|#|$)/i.test(
    lowerLocation
  );
  const mimeHint =
    (document.contentType || '').startsWith('image/') || (document.contentType || '').startsWith('video/');

  if (!mediaExtMatch && !mimeHint) {
    return null;
  }

  if (lowerLocation.startsWith('http://') || lowerLocation.startsWith('https://')) {
    return {
      tag_name: lowerLocation.match(/\.(mp4|webm|mov|m4v|mkv)(\?|#|$)/i) ? 'video' : 'img',
      url: locationUrl,
      referrer_url: locationUrl,
      preview_url: locationUrl,
      width: null,
      height: null,
      alt: '',
    };
  }

  if (lowerLocation.startsWith('blob:') || lowerLocation.startsWith('data:')) {
    const fallback = safeUrl(document.referrer) || '';
    if (!fallback) {
      return null;
    }

    return {
      tag_name: 'img',
      url: fallback,
      referrer_url: locationUrl,
      preview_url: '',
      width: null,
      height: null,
      alt: '',
    };
  }

  return null;
}

export function collectLookupKeysForNode(node: Element): string[] {
  const keys = new Set<string>();
  const pageUrl = safeUrl(window.location.href);

  const mediaUrl = (() => {
    if (node instanceof HTMLImageElement) {
      return safeUrl(node.currentSrc) || safeUrl(node.src) || '';
    }
    if (node instanceof HTMLVideoElement) {
      return getVideoUrl(node) || '';
    }
    if (node instanceof HTMLAnchorElement) {
      return resolveAnchorLookupUrl(node.getAttribute('href') || '') || '';
    }
    return '';
  })();
  if (mediaUrl) {
    keys.add(mediaUrl);
  }

  const anchorHref = node.closest('a[href]')?.getAttribute('href') ?? '';
  const anchorUrl = resolveAnchorLookupUrl(anchorHref);
  if (anchorUrl) {
    keys.add(anchorUrl);
  }

  if (pageUrl && isBlobOrDataMediaNode(node)) {
    keys.add(pageUrl);
  }

  return [...keys];
}

function resolveAnchorLookupUrl(rawHref: string): string {
  const href = (rawHref || '').trim();
  if (!href) {
    return '';
  }

  // Hash-only links ("#...") are page controls, not media/page provenance.
  if (href.startsWith('#')) {
    return '';
  }

  if (href.toLowerCase().startsWith('javascript:')) {
    return '';
  }

  return safeUrl(href);
}

function isBlobOrDataMediaNode(node: Element): boolean {
  if (node instanceof HTMLImageElement) {
    const raw = (node.currentSrc || node.src || node.getAttribute('src') || '').trim().toLowerCase();
    return raw.startsWith('blob:') || raw.startsWith('data:');
  }

  if (node instanceof HTMLVideoElement) {
    const raw = (node.currentSrc || node.src || '').trim().toLowerCase();
    return raw.startsWith('blob:') || raw.startsWith('data:');
  }

  return false;
}

function resolveMetaVideoUrl(): string {
  const selectors = [
    'meta[property="og:video"]',
    'meta[property="og:video:url"]',
    'meta[property="og:video:secure_url"]',
    'meta[name="twitter:player:stream"]',
    'meta[name="twitter:player:stream:url"]',
  ];

  for (const selector of selectors) {
    const tag = document.querySelector(selector);
    const content = tag?.getAttribute('content') || '';
    const url = safeUrl(content);
    if (url) {
      return url;
    }
  }

  return '';
}

function resolveDataStoreUrl(element: Element): string {
  let node: Element | null = element;
  let depth = 0;

  while (node && depth < 8) {
    const dataStore = node.getAttribute?.('data-store');
    if (dataStore) {
      const parsed = parseMaybeJson(dataStore);
      const url = findPlayableUrl(parsed, 0);
      if (url) {
        return url;
      }
    }

    node = node.parentElement;
    depth += 1;
  }

  return '';
}

function parseMaybeJson(value: string): unknown {
  if (!value) {
    return null;
  }

  const decoded = decodeHtmlEntities(value);
  try {
    return JSON.parse(decoded);
  } catch {
    return null;
  }
}

function decodeHtmlEntities(value: string): string {
  if (!value || typeof value !== 'string') {
    return '';
  }

  return value
    .replace(/&quot;/g, '"')
    .replace(/&#34;/g, '"')
    .replace(/&amp;/g, '&')
    .replace(/&#38;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>');
}

function findPlayableUrl(value: unknown, depth: number): string {
  if (!value || depth > 4) {
    return '';
  }

  if (typeof value === 'string') {
    return value.startsWith('http') ? value : '';
  }

  if (Array.isArray(value)) {
    for (const entry of value) {
      const found = findPlayableUrl(entry, depth + 1);
      if (found) return found;
    }
    return '';
  }

  if (typeof value === 'object') {
    for (const key of Object.keys(value as Record<string, unknown>)) {
      const candidate = (value as Record<string, unknown>)[key];
      const found = findPlayableUrl(candidate, depth + 1);
      if (found) return found;
    }
  }

  return '';
}
