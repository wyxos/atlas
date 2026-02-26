import { buildLookupKeys } from './lookupKeys';
import { normalizeDownloadedAt, normalizeProgress } from './statusMeta';

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

export function collectReactionStatusLookupKeys(payload: ReactionStatusPayload): string[] {
  return buildLookupKeys(
    typeof payload.url === 'string' ? payload.url : '',
    typeof payload.referrerUrl === 'string' ? payload.referrerUrl : '',
    typeof payload.previewUrl === 'string' ? payload.previewUrl : ''
  );
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
