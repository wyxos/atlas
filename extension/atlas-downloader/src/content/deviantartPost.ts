import { resolveAbsoluteUrl } from './media';

const DEV_HOST_RE = /(^|\.)deviantart\.com$/i;
const WIX_ASSET_RE =
  /^https?:\/\/[^/]*wixmp\.com\/f\/([^/]+)\/([a-z0-9]+-[^/?#]+?)\.(?:jpg|jpeg|png|webp|gif|bmp|avif)(?:[/?#]|$)/i;
const WIX_IMAGE_SELECTOR = 'img[src*="wixmp.com/f/"], img[srcset*="wixmp.com/f/"]';
const KNOWN_GALLERY_ROOT_SELECTOR = '.IUfj2J.qeNdP5.bOFPMd, .IUfj2J.E95sX1.qeNdP5.bOFPMd';
const KNOWN_GALLERY_ITEM_SELECTOR = '.NpoINo';
const MAX_SCOPED_IMAGE_COUNT = 12;

type WixUrlInfo = {
  normalizedUrl: string;
  collectionKey: string;
  assetKey: string;
  assetPrefix: string;
  width: number | null;
  height: number | null;
  maxWidth: number | null;
  maxHeight: number | null;
  rank: number;
  score: number;
};

type WixCandidate = WixUrlInfo & {
  order: number;
};

export type DeviantArtPostEntry = {
  assetKey: string;
  previewUrl: string;
  baseUrl: string;
  width: number | null;
  height: number | null;
  maxWidth: number | null;
  maxHeight: number | null;
};

export type DeviantArtPostContext = {
  groupKey: string;
  entries: DeviantArtPostEntry[];
  entryByAssetKey: Map<string, DeviantArtPostEntry>;
};

export function resolveWixAssetKey(url: string): string {
  const normalized = normalizeHttpUrl(url, window.location.href);
  if (!normalized) {
    return '';
  }

  const match = normalized.match(WIX_ASSET_RE);
  return match?.[2] || '';
}

function resolveWixCollectionKey(url: string): string {
  const normalized = normalizeHttpUrl(url, window.location.href);
  if (!normalized) {
    return '';
  }

  const match = normalized.match(WIX_ASSET_RE);
  return (match?.[1] || '').toLowerCase();
}

export function resolveWixAssetPrefix(url: string): string {
  const assetKey = resolveWixAssetKey(url);
  if (!assetKey) {
    return '';
  }

  return assetKey.split('-')[0] || '';
}

export function resolveBestDeviantArtPostDownloadUrl(entry: DeviantArtPostEntry): string {
  const baseUrl = entry.baseUrl || '';
  const maxWidth = entry.maxWidth;
  const maxHeight = entry.maxHeight;

  if (!baseUrl || !maxWidth || !maxHeight) {
    return baseUrl;
  }

  const baseArea = (entry.width || 0) * (entry.height || 0);
  const maxArea = maxWidth * maxHeight;
  if (baseArea > 0 && maxArea <= baseArea) {
    return baseUrl;
  }

  const candidate = deriveLargestWixmpUrl(baseUrl, maxWidth, maxHeight);
  if (!candidate || candidate === baseUrl) {
    return baseUrl;
  }

  return candidate;
}

export function deriveLargestWixmpUrl(url: string, maxWidth: number, maxHeight: number): string {
  const normalized = normalizeHttpUrl(url, window.location.href);
  if (!normalized || !maxWidth || !maxHeight) {
    return normalized;
  }

  const replacement = `/v1/fit/w_${maxWidth},h_${maxHeight},q_70,strp/`;
  if (/\/v1\/(?:fit|fill|crop)\/[^/]+\/strp\//i.test(normalized)) {
    return normalized.replace(/\/v1\/(?:fit|fill|crop)\/[^/]+\/strp\//i, replacement);
  }

  if (/\/v1\/(?:fit|fill|crop)\/[^/]+\//i.test(normalized)) {
    return normalized.replace(/\/v1\/(?:fit|fill|crop)\/[^/]+\//i, replacement);
  }

  return normalized;
}

export function resolveDeviantArtPostContext(
  locationHref: string = window.location.href,
  activeMedia: Element | null = null
): DeviantArtPostContext | null {
  if (!isDeviantArtDeviationUrl(locationHref)) {
    return null;
  }

  const collected = collectWixCandidates(locationHref, activeMedia);
  if (collected.length === 0) {
    return null;
  }

  const groupKey = resolvePrimaryCollectionKey(collected, locationHref);
  if (!groupKey) {
    return null;
  }

  const grouped = new Map<string, WixCandidate[]>();
  for (const candidate of collected) {
    if (candidate.collectionKey !== groupKey) {
      continue;
    }

    const existing = grouped.get(candidate.assetKey) || [];
    existing.push(candidate);
    grouped.set(candidate.assetKey, existing);
  }

  if (grouped.size <= 1) {
    return null;
  }

  const entries: DeviantArtPostEntry[] = [];
  for (const [assetKey, candidates] of grouped.entries()) {
    const sorted = [...candidates].sort((left, right) => {
      if (right.score !== left.score) {
        return right.score - left.score;
      }
      return left.order - right.order;
    });
    const best = sorted[0];
    if (!best) {
      continue;
    }

    const maxWidth = pickMaxNumber(candidates.map((candidate) => candidate.maxWidth));
    const maxHeight = pickMaxNumber(candidates.map((candidate) => candidate.maxHeight));
    entries.push({
      assetKey,
      previewUrl: best.normalizedUrl,
      baseUrl: best.normalizedUrl,
      width: best.width,
      height: best.height,
      maxWidth: maxWidth ?? best.width,
      maxHeight: maxHeight ?? best.height,
    });
  }

  if (entries.length <= 1) {
    return null;
  }

  entries.sort((left, right) => {
    const leftOrder = grouped.get(left.assetKey)?.[0]?.order ?? 0;
    const rightOrder = grouped.get(right.assetKey)?.[0]?.order ?? 0;
    return leftOrder - rightOrder;
  });

  const entryByAssetKey = new Map(entries.map((entry) => [entry.assetKey, entry]));
  return {
    groupKey,
    entries,
    entryByAssetKey,
  };
}

function isDeviantArtDeviationUrl(locationHref: string): boolean {
  try {
    const parsed = new URL(locationHref);
    if (!DEV_HOST_RE.test(parsed.hostname)) {
      return false;
    }

    return /\/art\//i.test(parsed.pathname);
  } catch {
    return false;
  }
}

function resolvePrimaryCollectionKey(candidates: WixCandidate[], locationHref: string): string {
  const ogTag = document.querySelector('meta[property="og:image"]');
  const ogGroupKey = resolveWixCollectionKey(ogTag?.getAttribute('content') || '');
  if (ogGroupKey) {
    return ogGroupKey;
  }

  const canonical = document.querySelector('link[rel="canonical"]')?.getAttribute('href') || locationHref;
  const canonicalUrl = normalizeHttpUrl(canonical, locationHref);
  const pathHint = canonicalUrl ? canonicalUrl.split('/').pop() || '' : '';
  const deviationId = pathHint.match(/-(\d{6,})$/)?.[1] || '';

  if (deviationId) {
    const matching = candidates.find((candidate) => candidate.normalizedUrl.includes(deviationId));
    if (matching) {
      return matching.collectionKey;
    }
  }

  const counts = new Map<string, number>();
  for (const candidate of candidates) {
    counts.set(candidate.collectionKey, (counts.get(candidate.collectionKey) || 0) + 1);
  }

  let bestGroupKey = '';
  let bestCount = 0;
  for (const [groupKey, count] of counts.entries()) {
    if (count > bestCount) {
      bestGroupKey = groupKey;
      bestCount = count;
    }
  }

  return bestGroupKey;
}

function collectWixCandidates(locationHref: string, activeMedia: Element | null = null): WixCandidate[] {
  const scopedRoot = resolveScopedGalleryRoot(activeMedia);
  const rawUrls = scopedRoot
    ? collectRawWixUrlsFromRoot(scopedRoot)
    : activeMedia ? [] : collectRawWixUrlsFromDocument();

  if (!activeMedia && scopedRoot && rawUrls.length <= 1) {
    rawUrls.push(...collectRawWixUrlsFromDocument());
  }

  const uniqueUrls = new Set<string>();
  const candidates: WixCandidate[] = [];
  for (const [order, rawUrl] of rawUrls.entries()) {
    const info = parseWixUrlInfo(rawUrl, locationHref);
    if (!info) {
      continue;
    }

    if (uniqueUrls.has(info.normalizedUrl)) {
      continue;
    }
    uniqueUrls.add(info.normalizedUrl);
    candidates.push({
      ...info,
      order,
    });
  }

  return candidates;
}

function resolveScopedGalleryRoot(activeMedia: Element | null): Element | null {
  const knownRoots = Array.from(document.querySelectorAll(KNOWN_GALLERY_ROOT_SELECTOR));
  const viableKnownRoots = knownRoots.filter((root) => countWixImages(root) > 1);
  if (viableKnownRoots.length > 0) {
    const fromKnown = pickNearestRoot(viableKnownRoots, activeMedia);
    if (fromKnown) {
      return fromKnown;
    }
  }

  if (!activeMedia) {
    return null;
  }

  const nearbyRoot = resolveNearbyGalleryRoot(activeMedia);
  if (nearbyRoot) {
    return nearbyRoot;
  }

  let current: Element | null = activeMedia.parentElement;
  while (current && current !== document.body && current !== document.documentElement) {
    const count = countWixImages(current);
    if (count >= 2 && count <= MAX_SCOPED_IMAGE_COUNT) {
      return current;
    }
    current = current.parentElement;
  }

  return null;
}

function resolveNearbyGalleryRoot(activeMedia: Element): Element | null {
  const candidateRoots = new Set<Element>();
  const wixCountCache = new Map<Element, number>();
  const getWixCount = (root: Element): number => {
    if (wixCountCache.has(root)) {
      return wixCountCache.get(root) || 0;
    }

    const count = countWixImages(root);
    wixCountCache.set(root, count);
    return count;
  };

  const wixImages = Array.from(document.querySelectorAll(WIX_IMAGE_SELECTOR));
  for (const image of wixImages) {
    if (!(image instanceof Element)) {
      continue;
    }

    if (image === activeMedia || activeMedia.contains(image) || image.contains(activeMedia)) {
      continue;
    }

    let current: Element | null = image.parentElement;
    let depth = 0;
    while (current && current !== document.body && current !== document.documentElement && depth < 5) {
      const wixCount = getWixCount(current);
      const galleryItemCount = current.querySelectorAll(KNOWN_GALLERY_ITEM_SELECTOR).length;
      if ((wixCount >= 2 && wixCount <= MAX_SCOPED_IMAGE_COUNT) || galleryItemCount > 1) {
        candidateRoots.add(current);
      }
      current = current.parentElement;
      depth += 1;
    }
  }

  const roots = [...candidateRoots].filter((root) => !root.contains(activeMedia) && getWixCount(root) > 1);
  return pickNearestRoot(roots, activeMedia);
}

function pickNearestRoot(roots: Element[], activeMedia: Element | null): Element | null {
  if (roots.length === 0) {
    return null;
  }

  if (!activeMedia) {
    return roots[0] || null;
  }

  for (const root of roots) {
    if (root.contains(activeMedia)) {
      return root;
    }
  }

  const mediaRect = activeMedia.getBoundingClientRect();
  let best: { root: Element; distance: number } | null = null;
  for (const root of roots) {
    const rect = root.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) {
      continue;
    }

    const verticalDistance =
      rect.top >= mediaRect.bottom ? rect.top - mediaRect.bottom : Math.abs(rect.top - mediaRect.top) + 200;
    const horizontalDistance = Math.abs(rect.left - mediaRect.left);
    const distance = verticalDistance * 10 + horizontalDistance;
    if (!best || distance < best.distance) {
      best = { root, distance };
    }
  }

  return best?.root || roots[0] || null;
}

function countWixImages(root: Element): number {
  return root.querySelectorAll(WIX_IMAGE_SELECTOR).length;
}

function collectRawWixUrlsFromDocument(): string[] {
  const rawUrls: string[] = [];

  const addSrcset = (value: string | null) => {
    const srcset = (value || '').trim();
    if (!srcset) {
      return;
    }

    for (const part of srcset.split(',')) {
      const candidate = part.trim().split(/\s+/)[0] || '';
      if (candidate) {
        rawUrls.push(candidate);
      }
    }
  };

  const preloadLinks = document.querySelectorAll('head link[rel="preload"][as="image"]');
  for (const link of preloadLinks) {
    rawUrls.push((link.getAttribute('href') || '').trim());
    addSrcset(link.getAttribute('imagesrcset'));
    addSrcset(link.getAttribute('imageSrcSet'));
  }

  const mediaImages = document.querySelectorAll(WIX_IMAGE_SELECTOR);
  for (const image of mediaImages) {
    rawUrls.push((image.getAttribute('src') || '').trim());
    addSrcset(image.getAttribute('srcset'));
  }

  return rawUrls;
}

function collectRawWixUrlsFromRoot(root: Element): string[] {
  const rawUrls: string[] = [];

  const addSrcset = (value: string | null) => {
    const srcset = (value || '').trim();
    if (!srcset) {
      return;
    }

    for (const part of srcset.split(',')) {
      const candidate = part.trim().split(/\s+/)[0] || '';
      if (candidate) {
        rawUrls.push(candidate);
      }
    }
  };

  const itemRoots = root.querySelectorAll(KNOWN_GALLERY_ITEM_SELECTOR);
  const rootsToScan = itemRoots.length > 1 ? Array.from(itemRoots) : [root];

  for (const scope of rootsToScan) {
    const images = scope.querySelectorAll(WIX_IMAGE_SELECTOR);
    for (const image of images) {
      rawUrls.push((image.getAttribute('src') || '').trim());
      addSrcset(image.getAttribute('srcset'));
    }
  }

  return rawUrls;
}

function parseWixUrlInfo(rawUrl: string, locationHref: string): WixUrlInfo | null {
  const normalizedUrl = normalizeHttpUrl(rawUrl, locationHref);
  if (!normalizedUrl) {
    return null;
  }

  const assetMatch = normalizedUrl.match(WIX_ASSET_RE);
  if (!assetMatch || !assetMatch[1] || !assetMatch[2]) {
    return null;
  }

  const collectionKey = assetMatch[1].toLowerCase();
  const assetKey = assetMatch[2];
  const assetPrefix = assetKey.split('-')[0] || '';
  if (!collectionKey || !assetPrefix) {
    return null;
  }

  const width = readDimensionToken(normalizedUrl, 'w');
  const height = readDimensionToken(normalizedUrl, 'h');
  const transformation = normalizedUrl.match(/\/v1\/(fit|fill|crop)\//i)?.[1]?.toLowerCase() || '';
  const rank = transformation === 'fit' ? 3 : transformation === 'fill' ? 2 : transformation === 'crop' ? 1 : 0;

  const maxSize = readMaxSizeFromToken(normalizedUrl);
  const area = (width || 0) * (height || 0);
  const score = rank * 10_000_000 + area;

  return {
    normalizedUrl,
    collectionKey,
    assetKey,
    assetPrefix,
    width,
    height,
    maxWidth: maxSize.width,
    maxHeight: maxSize.height,
    rank,
    score,
  };
}

function normalizeHttpUrl(value: string, baseUrl: string): string {
  return resolveAbsoluteUrl(value, baseUrl);
}

function readDimensionToken(url: string, token: 'w' | 'h'): number | null {
  const pattern = new RegExp(`${token}_(\\d{2,5})`, 'i');
  const match = url.match(pattern);
  if (!match?.[1]) {
    return null;
  }

  const parsed = parseInt(match[1], 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
}

function readMaxSizeFromToken(url: string): { width: number | null; height: number | null } {
  try {
    const parsed = new URL(url);
    const token = (parsed.searchParams.get('token') || '').trim();
    if (!token) {
      return { width: null, height: null };
    }

    const payloadPart = token.split('.')[1] || '';
    if (!payloadPart) {
      return { width: null, height: null };
    }

    const normalizedPayload = payloadPart.replace(/-/g, '+').replace(/_/g, '/');
    const paddedPayload = normalizedPayload.padEnd(Math.ceil(normalizedPayload.length / 4) * 4, '=');
    const decoded = atob(paddedPayload);
    const parsedPayload = JSON.parse(decoded) as unknown;

    const width = readMaxDimensionFromTokenValue(parsedPayload, 'width', 0);
    const height = readMaxDimensionFromTokenValue(parsedPayload, 'height', 0);
    return {
      width: width ?? null,
      height: height ?? null,
    };
  } catch {
    return { width: null, height: null };
  }
}

function readMaxDimensionFromTokenValue(
  value: unknown,
  dimension: 'width' | 'height',
  depth: number
): number | null {
  if (depth > 6 || value === null || value === undefined) {
    return null;
  }

  if (Array.isArray(value)) {
    for (const entry of value) {
      const found = readMaxDimensionFromTokenValue(entry, dimension, depth + 1);
      if (found) {
        return found;
      }
    }
    return null;
  }

  if (typeof value === 'object') {
    const record = value as Record<string, unknown>;
    const current = record[dimension];
    if (typeof current === 'number' && Number.isFinite(current) && current > 0) {
      return Math.round(current);
    }
    if (typeof current === 'string') {
      const match = current.match(/(\d{2,5})/);
      if (match?.[1]) {
        const parsed = parseInt(match[1], 10);
        if (Number.isFinite(parsed) && parsed > 0) {
          return parsed;
        }
      }
    }

    for (const next of Object.values(record)) {
      const found = readMaxDimensionFromTokenValue(next, dimension, depth + 1);
      if (found) {
        return found;
      }
    }
  }

  return null;
}

function pickMaxNumber(values: Array<number | null>): number | null {
  let max: number | null = null;
  for (const value of values) {
    if (!value || !Number.isFinite(value) || value <= 0) {
      continue;
    }
    if (max === null || value > max) {
      max = value;
    }
  }
  return max;
}
