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
const MARKER_RAIL_ATTR = 'data-atlas-marker-rail';
const MARKER_BADGE_ATTR = 'data-atlas-marker-badge';
const MARKER_HOST_POSITION_ATTR = 'data-atlas-marker-host-position';
const OPEN_TAB_ICON_PATHS = [
  'M8 7V5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2',
  'M5 8h9a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-9a2 2 0 0 1 2-2Z',
];

type MarkerBorderState = {
  state: string | null;
  reactionType: string | null;
  openTab: boolean;
};

function resolveDecorationHost(node: Element): HTMLElement | null {
  if (node instanceof HTMLImageElement || node instanceof HTMLVideoElement) {
    return node.parentElement instanceof HTMLElement ? node.parentElement : null;
  }

  return node instanceof HTMLElement ? node : null;
}

function ensureHostPositioned(host: HTMLElement): void {
  const computedPosition = window.getComputedStyle(host).position;
  if (computedPosition && computedPosition !== 'static') {
    return;
  }

  host.setAttribute(MARKER_HOST_POSITION_ATTR, '1');
}

function cleanupManagedHostPosition(host: HTMLElement): void {
  if (host.getAttribute(MARKER_HOST_POSITION_ATTR) !== '1') {
    return;
  }

  if (host.querySelector(`[${MARKER_RAIL_ATTR}], [${MARKER_BADGE_ATTR}]`)) {
    return;
  }

  host.removeAttribute(MARKER_HOST_POSITION_ATTR);
}

function removeHostDecorations(host: HTMLElement): void {
  host
    .querySelectorAll(`[${MARKER_RAIL_ATTR}], [${MARKER_BADGE_ATTR}]`)
    .forEach((element) => element.remove());
  cleanupManagedHostPosition(host);
}

function removeDecorationsForNodes(nodes: Iterable<Element>): void {
  const hosts = new Set<HTMLElement>();
  for (const node of nodes) {
    const host = resolveDecorationHost(node);
    if (host) {
      hosts.add(host);
    }
  }

  for (const host of hosts) {
    removeHostDecorations(host);
  }
}

function clearBadges(kind: 'reaction' | 'open-tab'): void {
  document.querySelectorAll(`[${MARKER_BADGE_ATTR}="${kind}"]`).forEach((badge) => {
    const host = badge.parentElement;
    badge.remove();
    if (host instanceof HTMLElement) {
      cleanupManagedHostPosition(host);
    }
  });
}

function cleanupLegacyBadgeLayers(): void {
  document.getElementById(REACTION_BADGE_LAYER_ID)?.remove();
  document.getElementById(OPEN_TAB_BADGE_LAYER_ID)?.remove();
}

function markerBorderColor({ state, reactionType, openTab }: MarkerBorderState): string | null {
  if (state === 'blacklisted') {
    return 'rgba(239, 68, 68, 0.9)';
  }

  if (state === 'reacted') {
    if (reactionType === 'love') {
      return 'rgba(239, 68, 68, 0.9)';
    }
    if (reactionType === 'like') {
      return 'rgba(56, 189, 248, 0.9)';
    }
    if (reactionType === 'funny') {
      return 'rgba(234, 179, 8, 0.95)';
    }
    if (reactionType === 'dislike') {
      return 'rgba(71, 85, 105, 0.95)';
    }
  }

  if (state === 'downloaded') {
    return 'rgba(34, 197, 94, 0.85)';
  }

  if (state === 'exists') {
    return 'rgba(148, 163, 184, 0.5)';
  }

  if (openTab) {
    return 'rgba(16, 185, 129, 0.92)';
  }

  return null;
}

function appendBorderRails(host: HTMLElement, color: string): void {
  ensureHostPositioned(host);
  const sides = ['top', 'right', 'bottom', 'left'] as const;
  for (const side of sides) {
    const rail = document.createElement('span');
    rail.className = `atlas-downloader-marker-rail atlas-downloader-marker-rail-${side}`;
    rail.setAttribute(MARKER_RAIL_ATTR, '1');
    rail.style.backgroundColor = color;
    host.appendChild(rail);
  }
}

function appendBadge(host: HTMLElement, kind: 'reaction' | 'open-tab', className: string, iconPathDs: string[]): void {
  ensureHostPositioned(host);
  const badge = document.createElement('span');
  badge.className = `atlas-downloader-inline-badge ${className}`.trim();
  badge.setAttribute(MARKER_BADGE_ATTR, kind);
  badge.appendChild(createSvgIcon(iconPathDs));
  host.appendChild(badge);
}

export function clearNodeMarkerAttributes(nodes: Iterable<Element>): void {
  const nodeList = Array.from(nodes);

  for (const node of nodeList) {
    node.removeAttribute('data-atlas-marked');
    node.removeAttribute('data-atlas-state');
    node.removeAttribute('data-atlas-reaction');
    node.removeAttribute('data-atlas-open-tab');
  }

  removeDecorationsForNodes(nodeList);
  cleanupLegacyBadgeLayers();
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
    statusByUrl.set(stripHash(url), status);
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
  clearBadges('reaction');
  cleanupLegacyBadgeLayers();

  for (const node of reactedNodes) {
    const reactionType = node.getAttribute('data-atlas-reaction') || '';
    const reaction = REACTIONS.find((entry) => entry.type === reactionType);
    if (!reaction) {
      continue;
    }

    const host = resolveDecorationHost(node);
    if (!host) {
      continue;
    }

    appendBadge(host, 'reaction', `atlas-downloader-reaction-badge ${reaction.className}`, reaction.pathDs);
  }
}

export function syncOpenTabIconBadges(openTabNodes: Element[]): void {
  clearBadges('open-tab');
  cleanupLegacyBadgeLayers();

  for (const node of openTabNodes) {
    if (node.getAttribute('data-atlas-state') === 'reacted') {
      continue;
    }

    const host = resolveDecorationHost(node);
    if (!host) {
      continue;
    }

    appendBadge(host, 'open-tab', 'atlas-downloader-open-tab-badge', OPEN_TAB_ICON_PATHS);
  }
}

export function syncMarkerRails(nodes: Element[]): void {
  document.querySelectorAll(`[${MARKER_RAIL_ATTR}]`).forEach((rail) => {
    const host = rail.parentElement;
    rail.remove();
    if (host instanceof HTMLElement) {
      cleanupManagedHostPosition(host);
    }
  });

  for (const node of nodes) {
    const host = resolveDecorationHost(node);
    if (!host) {
      continue;
    }

    const color = markerBorderColor({
      state: node.getAttribute('data-atlas-state'),
      reactionType: node.getAttribute('data-atlas-reaction'),
      openTab: node.getAttribute('data-atlas-open-tab') === '1',
    });
    if (!color) {
      continue;
    }

    appendBorderRails(host, color);
  }
}
