import { resolveAbsoluteUrl } from './media';
import { normalizeDomain, parseDomainIncludeRules } from '../shared/domainIncludeRules';

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

type DomainIncludeRule = {
  domain: string;
  regexes: RegExp[];
};

let domainIncludeRules: DomainIncludeRule[] = [];
let activePageRule: DomainIncludeRule | null = null;

export function safeUrl(value: string): string {
  return resolveAbsoluteUrl(value, window.location.href);
}

export function configureDomainIncludeRules(rawRules: unknown): void {
  domainIncludeRules = toCompiledDomainIncludeRules(rawRules);
  activePageRule = resolveActiveRule(window.location.href, domainIncludeRules);
}

export function filterEligibleLookupUrls(rawUrls: unknown): string[] {
  if (!Array.isArray(rawUrls)) {
    return [];
  }

  const seen = new Set<string>();
  const eligible: string[] = [];
  for (const value of rawUrls) {
    if (typeof value !== 'string') {
      continue;
    }

    const url = value.trim();
    if (!url || seen.has(url)) {
      continue;
    }

    seen.add(url);
    if (isEligibleCandidateUrl(url)) {
      eligible.push(url);
    }
  }

  return eligible;
}

export function isEligibleCandidateUrl(value: string): boolean {
  const raw = (value || '').trim();
  if (!raw) {
    return false;
  }

  const parsed = safeHttpUrl(raw);
  if (!parsed) {
    return false;
  }

  const rule = activePageRule;
  if (rule && rule.regexes.length > 0) {
    return rule.regexes.some((regex) => regex.test(raw));
  }

  return !isBareDomainRootUrl(parsed);
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

export function buildItemFromElement(element: Element, minWidth: number): MediaItem | null {
  if (!(element instanceof Element)) {
    return null;
  }

  if (element.tagName === 'IMG') {
    const img = element as HTMLImageElement;
    const rawSrc = (img.currentSrc || img.src || img.getAttribute('src') || '').trim();
    const url = safeUrl(rawSrc);
    if (url && !isEligibleCandidateUrl(url)) {
      return null;
    }

    const { width, height } = resolveImageDimensions(img, url || rawSrc);
    const minFilterWidth = width ?? toPositiveDimension(img.clientWidth);
    if (minFilterWidth && minFilterWidth < minWidth) {
      return null;
    }

    if (!url) {
      const fallback = safeUrl(document.referrer) || safeUrl(window.location.href) || '';
      if (!fallback || (!rawSrc.toLowerCase().startsWith('blob:') && !rawSrc.toLowerCase().startsWith('data:'))) {
        return null;
      }
      if (!isEligibleCandidateUrl(fallback)) {
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
    if (width && width < minWidth) {
      return null;
    }

    const url = getVideoUrl(video);
    if (url && !isEligibleCandidateUrl(url)) {
      return null;
    }
    if (!url) {
      const rawSrc = (video.currentSrc || video.src || '').trim().toLowerCase();
      if (rawSrc.startsWith('blob:') || rawSrc.startsWith('data:')) {
        const pageUrl = window.location.href;
        if (!isEligibleCandidateUrl(pageUrl)) {
          return null;
        }

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
    if (!isEligibleCandidateUrl(locationUrl)) {
      return null;
    }

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
    if (!fallback || !isEligibleCandidateUrl(fallback)) {
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

type CollectLookupKeyOptions = {
  includeAnchor?: boolean;
  includePageFallback?: boolean;
};

export function collectLookupKeysForNode(node: Element, options: CollectLookupKeyOptions = {}): string[] {
  const includeAnchor = options.includeAnchor ?? true;
  const includePageFallback = options.includePageFallback ?? true;
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
      if (!includeAnchor) {
        return '';
      }

      return resolveAnchorLookupUrl(node.getAttribute('href') || '') || '';
    }
    return '';
  })();
  if (mediaUrl) {
    keys.add(mediaUrl);
  }

  if (includeAnchor) {
    const anchorHref = node.closest('a[href]')?.getAttribute('href') ?? '';
    const anchorUrl = resolveAnchorLookupUrl(anchorHref);
    if (anchorUrl) {
      keys.add(anchorUrl);
    }
  }

  if (includePageFallback && pageUrl && isBlobOrDataMediaNode(node)) {
    keys.add(pageUrl);
  }

  return filterEligibleLookupUrls([...keys]);
}

function resolveAnchorLookupUrl(rawHref: string): string {
  const href = (rawHref || '').trim();
  if (!href) {
    return '';
  }

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
  const renderedWidth = toPositiveDimension(img.clientWidth);
  const renderedHeight = toPositiveDimension(img.clientHeight);
  const hinted = extractDimensionsFromUrl(url);
  const attrWidth = parseDimensionAttr(img.getAttribute('width'));
  const attrHeight = parseDimensionAttr(img.getAttribute('height'));

  return {
    width: pickDimensionValue(naturalWidth, renderedWidth, hinted.width, attrWidth),
    height: pickDimensionValue(naturalHeight, renderedHeight, hinted.height, attrHeight),
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

function pickDimensionValue(
  natural: number | null,
  rendered: number | null,
  hinted: number | null,
  attr: number | null
): number | null {
  if (natural) {
    return natural;
  }

  if (rendered) {
    return rendered;
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

function toCompiledDomainIncludeRules(rawRules: unknown): DomainIncludeRule[] {
  const parsedRules = parseDomainIncludeRules(rawRules);
  return parsedRules.map((rule) => ({
    domain: rule.domain,
    regexes: rule.patterns
      .map((pattern) => {
        try {
          return new RegExp(pattern, 'i');
        } catch {
          return null;
        }
      })
      .filter((regex): regex is RegExp => regex !== null),
  }));
}

function resolveActiveRule(pageUrl: string, rules: DomainIncludeRule[]): DomainIncludeRule | null {
  const pageHost = safeHostname(pageUrl);
  if (!pageHost) {
    return null;
  }

  let best: DomainIncludeRule | null = null;
  for (const rule of rules) {
    if (!domainMatchesHost(rule.domain, pageHost)) {
      continue;
    }

    if (!best || rule.domain.length > best.domain.length) {
      best = rule;
    }
  }

  return best;
}

function safeHostname(url: string): string {
  try {
    return new URL(url).hostname.toLowerCase();
  } catch {
    return '';
  }
}

function domainMatchesHost(domain: string, host: string): boolean {
  const normalizedDomain = normalizeDomain(domain);
  const normalizedHost = normalizeDomain(host);
  if (!normalizedDomain || !normalizedHost) {
    return false;
  }

  return normalizedHost === normalizedDomain || normalizedHost.endsWith(`.${normalizedDomain}`);
}

function safeHttpUrl(url: string): URL | null {
  try {
    const parsed = new URL(url);
    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
      return null;
    }

    return parsed;
  } catch {
    return null;
  }
}

function isBareDomainRootUrl(url: URL): boolean {
  return (url.pathname === '/' || url.pathname === '') && url.search === '';
}
