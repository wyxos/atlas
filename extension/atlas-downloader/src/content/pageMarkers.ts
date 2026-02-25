import { REACTIONS, createSvgIcon } from './reactions';

export type MarkerStatus = {
  exists: boolean;
  downloaded: boolean;
  blacklisted: boolean;
  reactionType: string | null;
};

type CacheEntry = MarkerStatus & {
  ts: number;
};

type SheetItem = {
  url?: string;
  atlas?: {
    exists?: boolean;
    downloaded?: boolean;
    blacklisted?: boolean;
    reaction?: {
      type?: unknown;
    } | null;
  } | null;
};

const PAGE_VISITED_BADGE_ID = 'atlas-downloader-page-visited-badge';
const REACTION_BADGE_LAYER_ID = 'atlas-downloader-reaction-badge-layer';
const OPEN_TAB_BADGE_LAYER_ID = 'atlas-downloader-open-tab-badge-layer';
const OPEN_TAB_ICON_PATHS = [
  'M8 7V5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2',
  'M5 8h9a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-9a2 2 0 0 1 2-2Z',
];

function isHashSpecificReferrerLookupKey(value: string): boolean {
  const trimmed = (value || '').trim();
  const hashIndex = trimmed.indexOf('#');
  if (hashIndex < 0) {
    return false;
  }

  const fragment = trimmed.slice(hashIndex + 1).toLowerCase();
  return /^image-\d+$/.test(fragment);
}

export function clearNodeMarkerAttributes(nodes: Iterable<Element>): void {
  for (const node of nodes) {
    node.removeAttribute('data-atlas-marked');
    node.removeAttribute('data-atlas-state');
    node.removeAttribute('data-atlas-reaction');
    node.removeAttribute('data-atlas-open-tab');
  }
}

export function buildStatusMapFromCache<T extends CacheEntry>(
  atlasStatusCache: Map<string, T>,
  ttlMs: number,
  stripHash: (value: string) => string
): Map<string, MarkerStatus> {
  const statusByUrl = new Map<string, MarkerStatus>();

  for (const [url, cached] of atlasStatusCache.entries()) {
    if (Date.now() - cached.ts > ttlMs) {
      atlasStatusCache.delete(url);
      continue;
    }

    const status: MarkerStatus = {
      exists: Boolean(cached.exists),
      downloaded: Boolean(cached.downloaded),
      blacklisted: Boolean(cached.blacklisted),
      reactionType: cached.reactionType ? String(cached.reactionType) : null,
    };

    statusByUrl.set(url, status);
    if (!isHashSpecificReferrerLookupKey(url)) {
      statusByUrl.set(stripHash(url), status);
    }
  }

  return statusByUrl;
}

export function mergeSheetItemStatuses(
  statusByUrl: Map<string, MarkerStatus>,
  sheetItems: SheetItem[],
  stripHash: (value: string) => string
): void {
  for (const item of sheetItems) {
    if (!item?.url || !item?.atlas) {
      continue;
    }

    const reactionType = item.atlas.reaction?.type ? String(item.atlas.reaction.type) : null;
    const status: MarkerStatus = {
      exists: Boolean(item.atlas.exists),
      downloaded: Boolean(item.atlas.downloaded),
      blacklisted: Boolean(item.atlas.blacklisted),
      reactionType,
    };

    statusByUrl.set(item.url, status);
    statusByUrl.set(stripHash(item.url), status);
  }
}

export function findStatusForLookupKeys(
  lookupKeys: string[],
  statusByUrl: Map<string, MarkerStatus>,
  stripHash: (value: string) => string
): MarkerStatus | null {
  for (const key of lookupKeys) {
    const match = statusByUrl.get(key) || statusByUrl.get(stripHash(key));
    if (!match) {
      continue;
    }

    if (match.exists || match.downloaded || match.blacklisted || match.reactionType) {
      return match;
    }
  }

  return null;
}

export function syncPageVisitedBadge(
  pageUrl: string,
  statusByUrl: Map<string, MarkerStatus>,
  stripHash: (value: string) => string
): void {
  const key = (pageUrl || '').trim();
  const normalizedKey = stripHash(key);
  const status = statusByUrl.get(key) || statusByUrl.get(normalizedKey) || null;
  const visited = Boolean(status && (status.exists || status.downloaded || status.blacklisted || status.reactionType));
  const existing = document.getElementById(PAGE_VISITED_BADGE_ID);

  if (!visited) {
    existing?.remove();
    return;
  }

  if (existing) {
    return;
  }

  const badge = document.createElement('div');
  badge.id = PAGE_VISITED_BADGE_ID;
  badge.textContent = 'Atlas: previously visited';
  (document.body || document.documentElement).appendChild(badge);
}

export function syncReactionIconBadges(reactedNodes: Element[]): void {
  const existingLayer = document.getElementById(REACTION_BADGE_LAYER_ID);
  if (reactedNodes.length === 0) {
    existingLayer?.remove();
    return;
  }

  const layer =
    existingLayer
    || (() => {
      const next = document.createElement('div');
      next.id = REACTION_BADGE_LAYER_ID;
      (document.body || document.documentElement).appendChild(next);
      return next;
    })();

  layer.replaceChildren();

  for (const node of reactedNodes) {
    const reactionType = node.getAttribute('data-atlas-reaction') || '';
    const reaction = REACTIONS.find((entry) => entry.type === reactionType);
    if (!reaction) {
      continue;
    }

    const rect = node.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) {
      continue;
    }

    const badge = document.createElement('span');
    badge.className = `atlas-downloader-reaction-badge ${reaction.className}`.trim();
    badge.style.left = `${Math.round(rect.left + window.scrollX + rect.width - 21)}px`;
    badge.style.top = `${Math.round(rect.top + window.scrollY + rect.height - 21)}px`;
    badge.appendChild(createSvgIcon(reaction.pathDs));
    layer.appendChild(badge);
  }

  if (!layer.hasChildNodes()) {
    layer.remove();
  }
}

export function syncOpenTabIconBadges(openTabNodes: Element[]): void {
  const existingLayer = document.getElementById(OPEN_TAB_BADGE_LAYER_ID);
  if (openTabNodes.length === 0) {
    existingLayer?.remove();
    return;
  }

  const layer =
    existingLayer
    || (() => {
      const next = document.createElement('div');
      next.id = OPEN_TAB_BADGE_LAYER_ID;
      (document.body || document.documentElement).appendChild(next);
      return next;
    })();

  layer.replaceChildren();

  for (const node of openTabNodes) {
    if (node.getAttribute('data-atlas-state') === 'reacted') {
      continue;
    }

    const rect = node.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) {
      continue;
    }

    const badge = document.createElement('span');
    badge.className = 'atlas-downloader-open-tab-badge';
    badge.style.left = `${Math.round(rect.left + window.scrollX + rect.width - 21)}px`;
    badge.style.top = `${Math.round(rect.top + window.scrollY + rect.height - 21)}px`;
    badge.appendChild(createSvgIcon(OPEN_TAB_ICON_PATHS));
    layer.appendChild(badge);
  }

  if (!layer.hasChildNodes()) {
    layer.remove();
  }
}
