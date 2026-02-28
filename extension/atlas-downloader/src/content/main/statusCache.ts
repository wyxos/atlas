import type { AtlasStatusCacheEntry } from '../interactions';
import { buildLookupKeys } from '../lookupKeys';
import { stripHash } from '../network';
import { normalizeDownloadedAt, normalizeProgress } from '../statusMeta';
import { filterEligibleLookupUrls } from '../items';

type SendMessageSafe = (message: unknown, callback: (response: unknown) => void) => void;

type AtlasStatusCallback = (
  status: {
    exists: boolean;
    downloaded: boolean;
    failed?: boolean;
    blacklisted: boolean;
    reactionType: string | null;
    downloadProgress?: number | null;
    downloadedAt?: string | null;
  } | null
) => void;

type AtlasCheckResponseResult = {
  url?: unknown;
  exists?: unknown;
  downloaded?: unknown;
  failed?: unknown;
  blacklisted?: unknown;
  reaction?: { type?: unknown } | null;
  download_progress?: unknown;
  downloaded_at?: unknown;
};

export function getCachedAtlasStatus(
  atlasStatusCache: Map<string, AtlasStatusCacheEntry>,
  ttlMs: number,
  url: string
): AtlasStatusCacheEntry | null {
  const rawKey = (url || '').trim();
  const fallbackKey = stripHash(rawKey);
  const cached = atlasStatusCache.get(rawKey) ?? atlasStatusCache.get(fallbackKey);
  if (!cached) {
    return null;
  }

  if (Date.now() - cached.ts > ttlMs) {
    atlasStatusCache.delete(rawKey);
    atlasStatusCache.delete(fallbackKey);
    return null;
  }

  return cached;
}

export function fetchAtlasStatus(
  atlasStatusCache: Map<string, AtlasStatusCacheEntry>,
  ttlMs: number,
  sendMessageSafe: SendMessageSafe,
  url: string,
  referrerUrl: string | null,
  callback: AtlasStatusCallback
): void {
  const lookupCandidates = filterEligibleLookupUrls(buildLookupKeys(url, referrerUrl || ''));
  if (lookupCandidates.length === 0) {
    callback(null);
    return;
  }

  for (const lookup of lookupCandidates) {
    const cached = getCachedAtlasStatus(atlasStatusCache, ttlMs, lookup);
    if (cached) {
      callback(cached);
      return;
    }
  }

  sendMessageSafe({ type: 'atlas-check-batch', urls: lookupCandidates }, (response) => {
    if (!response || !(response as { ok?: unknown }).ok) {
      callback(null);
      return;
    }

    const results = Array.isArray((response as { data?: { results?: unknown[] } }).data?.results)
      ? ((response as { data?: { results?: unknown[] } }).data?.results as unknown[])
      : [];

    const byUrl = new Map<string, AtlasCheckResponseResult>();
    for (const rawResult of results) {
      const result = (rawResult || {}) as AtlasCheckResponseResult;
      const resultUrl = typeof result.url === 'string' ? String(result.url) : '';
      if (!resultUrl) {
        continue;
      }

      for (const key of filterEligibleLookupUrls(buildLookupKeys(resultUrl))) {
        if (!byUrl.has(key)) {
          byUrl.set(key, result);
        }
      }
    }

    const match = lookupCandidates
      .map((lookup) => byUrl.get(lookup))
      .find((value) => Boolean(value));

    if (!match) {
      callback(null);
      return;
    }

    const status: AtlasStatusCacheEntry = {
      exists: Boolean(match.exists),
      downloaded: Boolean(match.downloaded),
      failed: Boolean(match.failed),
      blacklisted: Boolean(match.blacklisted),
      reactionType: match.reaction?.type ? String(match.reaction.type) : null,
      downloadProgress: normalizeProgress(match.download_progress),
      downloadedAt: normalizeDownloadedAt(match.downloaded_at),
      ts: Date.now(),
    };

    for (const lookup of lookupCandidates) {
      atlasStatusCache.set(lookup, status);
    }

    callback(status);
  });
}
