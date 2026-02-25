import { stripHash } from './network';

export type AtlasStatusCacheEntry = {
  exists: boolean;
  downloaded: boolean;
  blacklisted: boolean;
  reactionType: string | null;
  downloadProgress: number | null;
  downloadedAt: string | null;
  ts: number;
};

type ReactionStatusPayload = {
  url?: unknown;
  referrerUrl?: unknown;
  previewUrl?: unknown;
  reactionType?: unknown;
  downloaded?: unknown;
  blacklisted?: unknown;
  downloadProgress?: unknown;
  downloadedAt?: unknown;
};

function normalizeProgress(value: unknown): number | null {
  const parsed = Number(value);
  if (!Number.isFinite(parsed)) {
    return null;
  }

  return Math.max(0, Math.min(100, parsed));
}

function normalizeDownloadedAt(value: unknown): string | null {
  if (typeof value !== 'string') {
    return null;
  }

  const trimmed = value.trim();
  if (!trimmed) {
    return null;
  }

  const date = new Date(trimmed);
  if (Number.isNaN(date.getTime())) {
    return null;
  }

  return trimmed;
}

export function isHashSpecificReferrerLookupKey(value: string): boolean {
  const trimmed = (value || '').trim();
  const hashIndex = trimmed.indexOf('#');
  if (hashIndex < 0) {
    return false;
  }

  const fragment = trimmed.slice(hashIndex + 1).toLowerCase();
  return /^image-\d+$/.test(fragment);
}

function addLookupKey(lookupKeys: Set<string>, rawValue: string): void {
  const key = rawValue.trim();
  if (!key) {
    return;
  }

  lookupKeys.add(key);
  if (!isHashSpecificReferrerLookupKey(key)) {
    lookupKeys.add(stripHash(key));
  }
}

export function collectReactionStatusLookupKeys(payload: ReactionStatusPayload): string[] {
  const lookupKeys = new Set<string>();
  addLookupKey(lookupKeys, typeof payload.url === 'string' ? payload.url : '');
  addLookupKey(lookupKeys, typeof payload.referrerUrl === 'string' ? payload.referrerUrl : '');
  addLookupKey(lookupKeys, typeof payload.previewUrl === 'string' ? payload.previewUrl : '');

  return [...lookupKeys].filter(Boolean);
}

export function applyReactionStatusUpdateFromPayload(
  payload: unknown,
  atlasStatusCache: Map<string, AtlasStatusCacheEntry>
): boolean {
  if (!payload || typeof payload !== 'object') {
    return false;
  }

  const value = payload as ReactionStatusPayload;
  const lookupKeys = collectReactionStatusLookupKeys(value);
  if (lookupKeys.length === 0) {
    return false;
  }

  const reactionTypeRaw = value.reactionType;
  const reactionType =
    typeof reactionTypeRaw === 'string' && reactionTypeRaw.trim() !== ''
      ? reactionTypeRaw.trim()
      : null;

  const nextStatus: AtlasStatusCacheEntry = {
    exists: true,
    downloaded: Boolean(value.downloaded),
    blacklisted: Boolean(value.blacklisted),
    reactionType,
    downloadProgress: normalizeProgress(value.downloadProgress),
    downloadedAt: normalizeDownloadedAt(value.downloadedAt),
    ts: Date.now(),
  };

  for (const key of lookupKeys) {
    atlasStatusCache.set(key, nextStatus);
  }

  return true;
}
