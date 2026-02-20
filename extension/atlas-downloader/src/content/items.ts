import { resolveAbsoluteUrl } from './media';

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

type NoiseFilterRule =
  | { kind: 'host'; host: string }
  | { kind: 'urlPattern'; regex: RegExp }
  | { kind: 'urlContains'; needle: string };

const DEFAULT_MEDIA_NOISE_FILTERS = [
  'host:st.deviantart.net',
  'url:*wixmp.com*/crop/w_92,h_92*',
  'url:*wixmp.com*/crop/w_150,h_150*',
  'url:*wixmp.com*/fit/w_150,h_150*',
];

const defaultNoiseRules = parseNoiseFilterRules(DEFAULT_MEDIA_NOISE_FILTERS.join('\n'));
let customNoiseRules: NoiseFilterRule[] = [];

export function safeUrl(value: string): string {
  return resolveAbsoluteUrl(value, window.location.href);
}

export function configureMediaNoiseFilters(rawFilters: unknown): void {
  customNoiseRules = parseNoiseFilterRules(rawFilters);
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
    const rawSrc = (img.currentSrc || img.src || img.getAttribute('src') || '').trim();
    const url = safeUrl(rawSrc);
    if (url && isExcludedNoiseMediaUrl(url)) {
      return null;
    }

    const { width, height } = resolveImageDimensions(img, url || rawSrc);
    const minFilterWidth = width ?? toPositiveDimension(img.clientWidth);
    const minFilterHeight = height ?? toPositiveDimension(img.clientHeight);

    if ((minFilterWidth && minFilterWidth < minSize) || (minFilterHeight && minFilterHeight < minSize)) {
      return null;
    }

    if (!url) {
      const fallback = safeUrl(document.referrer) || safeUrl(window.location.href) || '';
      if (!fallback || (!rawSrc.toLowerCase().startsWith('blob:') && !rawSrc.toLowerCase().startsWith('data:'))) {
        return null;
      }
      if (isExcludedNoiseMediaUrl(fallback)) {
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
    const width = video.videoWidth || video.clientWidth || null;
    const height = video.videoHeight || video.clientHeight || null;
    if (width && height && (width < minSize || height < minSize)) {
      return null;
    }

    const url = getVideoUrl(video);
    if (url && isExcludedNoiseMediaUrl(url)) {
      return null;
    }
    if (!url) {
      const rawSrc = (video.currentSrc || video.src || '').trim().toLowerCase();
      if (rawSrc.startsWith('blob:') || rawSrc.startsWith('data:')) {
        const pageUrl = window.location.href;
        return {
          tag_name: 'video',
          url: pageUrl,
          referrer_url: pageUrl,
          preview_url: video.poster || '',
          width,
          height,
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
      width,
      height,
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

function resolveImageDimensions(
  img: HTMLImageElement,
  url: string
): { width: number | null; height: number | null } {
  const naturalWidth = toPositiveDimension(img.naturalWidth);
  const naturalHeight = toPositiveDimension(img.naturalHeight);
  const hinted = extractDimensionsFromUrl(url);
  const attrWidth = parseDimensionAttr(img.getAttribute('width'));
  const attrHeight = parseDimensionAttr(img.getAttribute('height'));
  const shouldPromoteHinted = shouldPreferHintedDimensions(
    naturalWidth,
    naturalHeight,
    hinted.width,
    hinted.height
  );

  return {
    width: pickDimensionValue(naturalWidth, hinted.width, attrWidth, shouldPromoteHinted),
    height: pickDimensionValue(naturalHeight, hinted.height, attrHeight, shouldPromoteHinted),
  };
}

function extractDimensionsFromUrl(rawUrl: string): { width: number | null; height: number | null } {
  const text = (rawUrl || '').trim();
  if (!text) {
    return { width: null, height: null };
  }

  const widthToken = firstDimensionToken(text, [/(?:^|[/,?&_=-])w_(\d{2,5})(?=$|[/,?&_=-])/i]);
  const heightToken = firstDimensionToken(text, [/(?:^|[/,?&_=-])h_(\d{2,5})(?=$|[/,?&_=-])/i]);

  let width = widthToken;
  const height = heightToken;

  // Common CDN suffix: `-414w-2x` means effective width 828.
  const retinaWidthMatch = text.match(/-(\d{2,5})w-(\d)x(?=$|[./?&_-])/i);
  if (retinaWidthMatch) {
    const baseWidth = parseInt(retinaWidthMatch[1], 10);
    const scale = parseInt(retinaWidthMatch[2], 10);
    const scaledWidth = toPositiveDimension(baseWidth * scale);
    if (scaledWidth && (!width || scaledWidth > width)) {
      width = scaledWidth;
    }
  }

  return {
    width: width ?? null,
    height: height ?? null,
  };
}

function firstDimensionToken(text: string, patterns: RegExp[]): number | null {
  for (const pattern of patterns) {
    const match = text.match(pattern);
    if (!match || !match[1]) {
      continue;
    }

    const parsed = toPositiveDimension(parseInt(match[1], 10));
    if (parsed) {
      return parsed;
    }
  }

  return null;
}

function parseDimensionAttr(value: string | null): number | null {
  if (!value) {
    return null;
  }

  const trimmed = value.trim();
  if (!trimmed) {
    return null;
  }

  const numericPrefix = trimmed.match(/^\d+/)?.[0] ?? '';
  if (!numericPrefix) {
    return null;
  }

  return toPositiveDimension(parseInt(numericPrefix, 10));
}

function shouldPreferHintedDimensions(
  naturalWidth: number | null,
  naturalHeight: number | null,
  hintedWidth: number | null,
  hintedHeight: number | null
): boolean {
  if (!naturalWidth || !naturalHeight || !hintedWidth || !hintedHeight) {
    return false;
  }

  if (hintedWidth <= naturalWidth || hintedHeight <= naturalHeight) {
    return false;
  }

  const widthRatio = hintedWidth / naturalWidth;
  const heightRatio = hintedHeight / naturalHeight;
  const ratioDelta = Math.abs(widthRatio - heightRatio);

  // Only trust hints when the loaded raster is clearly a low-res preview.
  return (
    naturalWidth <= 480 &&
    naturalHeight <= 800 &&
    hintedWidth >= 600 &&
    hintedHeight >= 900 &&
    widthRatio >= 1.8 &&
    heightRatio >= 1.8 &&
    ratioDelta <= 0.2
  );
}

function pickDimensionValue(
  natural: number | null,
  hinted: number | null,
  attr: number | null,
  preferHinted: boolean
): number | null {
  if (preferHinted && hinted) {
    return hinted;
  }

  if (natural) {
    return natural;
  }

  if (hinted) {
    return hinted;
  }

  return attr;
}

function toPositiveDimension(value: unknown): number | null {
  if (typeof value !== 'number' || !Number.isFinite(value)) {
    return null;
  }

  const rounded = Math.round(value);
  return rounded > 0 ? rounded : null;
}

function isExcludedNoiseMediaUrl(url: string): boolean {
  const normalizedUrl = (url || '').trim().toLowerCase();
  if (!normalizedUrl) {
    return false;
  }

  const rules = [...defaultNoiseRules, ...customNoiseRules];
  const hostname = safeHostname(normalizedUrl);

  for (const rule of rules) {
    if (rule.kind === 'host') {
      if (hostMatches(hostname, rule.host)) {
        return true;
      }
      continue;
    }

    if (rule.kind === 'urlPattern') {
      if (rule.regex.test(normalizedUrl)) {
        return true;
      }
      continue;
    }

    if (normalizedUrl.includes(rule.needle)) {
      return true;
    }
  }

  return false;
}

function safeHostname(url: string): string {
  try {
    return new URL(url).hostname.toLowerCase();
  } catch {
    return '';
  }
}

function hostMatches(currentHost: string, blockedHost: string): boolean {
  const current = (currentHost || '').trim().toLowerCase();
  const blocked = (blockedHost || '').trim().toLowerCase();
  if (!current || !blocked) {
    return false;
  }

  return current === blocked || current.endsWith(`.${blocked}`);
}

function parseNoiseFilterRules(rawFilters: unknown): NoiseFilterRule[] {
  if (!rawFilters || typeof rawFilters !== 'string') {
    return [];
  }

  return rawFilters
    .split(/[\n,]/g)
    .map((entry) => entry.trim())
    .filter((entry) => entry !== '' && !entry.startsWith('#'))
    .map((entry) => toNoiseFilterRule(entry))
    .filter((rule): rule is NoiseFilterRule => rule !== null);
}

function toNoiseFilterRule(rawEntry: string): NoiseFilterRule | null {
  const entry = rawEntry.trim();
  if (!entry) {
    return null;
  }

  const lower = entry.toLowerCase();
  if (lower.startsWith('host:')) {
    const host = resolveHostLike(entry.slice(5));
    return host ? { kind: 'host', host } : null;
  }

  if (lower.startsWith('url:')) {
    return buildUrlNoiseRule(entry.slice(4));
  }

  const host = resolveHostLike(entry);
  if (host) {
    return { kind: 'host', host };
  }

  return buildUrlNoiseRule(entry);
}

function buildUrlNoiseRule(rawPattern: string): NoiseFilterRule | null {
  const value = rawPattern.trim().toLowerCase();
  if (!value) {
    return null;
  }

  if (!value.includes('*')) {
    return { kind: 'urlContains', needle: value };
  }

  const source = wildcardToRegexSource(value);
  try {
    return { kind: 'urlPattern', regex: new RegExp(source, 'i') };
  } catch {
    return null;
  }
}

function wildcardToRegexSource(pattern: string): string {
  return pattern
    .split('*')
    .map((part) => escapeRegex(part))
    .join('.*');
}

function escapeRegex(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function resolveHostLike(value: string): string {
  const trimmed = (value || '').trim();
  if (!trimmed) {
    return '';
  }

  const withoutWildcardPrefix = trimmed.startsWith('*.') ? trimmed.slice(2) : trimmed;
  const withScheme = /^https?:\/\//i.test(withoutWildcardPrefix)
    ? withoutWildcardPrefix
    : `https://${withoutWildcardPrefix}`;

  try {
    return new URL(withScheme).hostname.toLowerCase();
  } catch {
    return '';
  }
}
