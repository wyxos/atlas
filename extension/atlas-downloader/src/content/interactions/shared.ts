import { isElementInModal } from '../media';
import { normalizeDownloadedAt, normalizeProgress, parseFileStatusMeta } from '../statusMeta';
import type { DialogChooser } from '../ui';

export const LOCATION_CHANGE_EVENT = 'atlas-location-change';

export type AtlasStatus = {
  exists: boolean;
  downloaded: boolean;
  blacklisted: boolean;
  reactionType: string | null;
  downloadProgress?: number | null;
  downloadedAt?: string | null;
};

export type AtlasStatusCacheEntry = AtlasStatus & {
  ts: number;
};

export type AtlasReactResponse = {
  ok?: boolean;
  error?: string;
  data?: {
    file?: {
      downloaded?: boolean;
      blacklisted_at?: unknown;
    } | null;
    reaction?: {
      type?: unknown;
    } | null;
  } | null;
} | null | undefined;

export type SendMessageSafe = (message: unknown, callback: (response: AtlasReactResponse) => void) => void;

type FetchAtlasStatus = (
  sendMessageSafe: SendMessageSafe,
  url: string,
  referrerUrl: string | null,
  callback: (status: AtlasStatus | null) => void
) => void;

type LimitString = (value: unknown, max: number) => string;
type SourceFromMediaUrl = (url: string) => string;

export type InteractionDependencies = {
  rootId: string;
  minWidth: number;
  maxMetadataLen: number;
  limitString: LimitString;
  sourceFromMediaUrl: SourceFromMediaUrl;
  fetchAtlasStatus: FetchAtlasStatus;
  atlasStatusCache: Map<string, AtlasStatusCacheEntry>;
  getCachedAtlasStatus: (url: string) => AtlasStatus | null;
};

export type HotkeysOptions = {
  showToast: (message: string, tone?: 'info' | 'danger') => void;
  sendMessageSafe: SendMessageSafe;
  isSheetOpen: () => boolean;
  chooseDialog: DialogChooser;
  enabled?: boolean;
  setHintShown?: (value: boolean) => void;
  getHintShown?: () => boolean;
};

export type OverlayOptions = {
  root: HTMLElement;
  showToast: (message: string, tone?: 'info' | 'danger') => void;
  sendMessageSafe: SendMessageSafe;
  isSheetOpen: () => boolean;
  chooseDialog: DialogChooser;
};

export type AtlasBatchResult = {
  ok?: boolean;
  error?: string;
  data?: {
    file?: {
      downloaded?: boolean;
      blacklisted_at?: unknown;
    } | null;
  } | null;
};

export type AtlasBatchResponse = {
  ok?: boolean;
  error?: string;
  results?: AtlasBatchResult[];
};

export function isOwnUiElement(element: Element, rootId: string): boolean {
  return Boolean(element.closest?.(`#${rootId}`));
}

export function clamp(value: number, min: number, max: number) {
  if (value < min) return min;
  if (value > max) return max;
  return value;
}
export { normalizeDownloadedAt, normalizeProgress, parseFileStatusMeta };

export function sendMessageSafeAsync(sendMessageSafe: SendMessageSafe, message: unknown): Promise<unknown> {
  return new Promise((resolve) => {
    sendMessageSafe(message, (response) => resolve(response));
  });
}

export function formatDownloadedAtUtc(downloadedAt: string | null | undefined): string {
  if (!downloadedAt) {
    return '';
  }

  const date = new Date(downloadedAt);
  if (Number.isNaN(date.getTime())) {
    return '';
  }

  const pad = (value: number) => String(value).padStart(2, '0');
  const year = date.getUTCFullYear();
  const month = pad(date.getUTCMonth() + 1);
  const day = pad(date.getUTCDate());
  const hour = pad(date.getUTCHours());
  const minute = pad(date.getUTCMinutes());
  return `${year}-${month}-${day} ${hour}:${minute} UTC`;
}

export function formatOverlayDownloadMeta(status: AtlasStatus | null): string {
  if (!status) {
    return '';
  }

  if (status.downloaded) {
    const downloadedAt = formatDownloadedAtUtc(status.downloadedAt ?? null);
    return downloadedAt ? `Downloaded ${downloadedAt}` : 'Downloaded';
  }

  const progress = normalizeProgress(status.downloadProgress);
  if (progress !== null && progress > 0 && progress < 100) {
    return `Downloading ${Math.round(progress)}%`;
  }

  if (status.exists && status.reactionType && status.reactionType !== 'dislike') {
    return 'Queued';
  }

  return '';
}

export function resolveOverlayProgressPercent(status: AtlasStatus | null): number | null {
  if (!status) {
    return null;
  }

  if (status.downloaded) {
    return 100;
  }

  if (status.blacklisted || status.reactionType === 'dislike') {
    return null;
  }

  if (!status.exists || !status.reactionType) {
    return null;
  }

  return normalizeProgress(status.downloadProgress) ?? 0;
}

function pickLargestMediaDescendantAtPoint(
  candidate: Element,
  x: number,
  y: number
): { media: Element; area: number } | null {
  const descendants = candidate.querySelectorAll?.('img, video');
  if (!descendants || descendants.length === 0) {
    return null;
  }

  let best: { media: Element; area: number; stackIndex: number; inModal: boolean } | null = null;
  for (const [stackIndex, descendant] of Array.from(descendants).entries()) {
    const rect = descendant.getBoundingClientRect();
    if (
      rect.width <= 0
      || rect.height <= 0
      || x < rect.left
      || x > rect.right
      || y < rect.top
      || y > rect.bottom
    ) {
      continue;
    }

    const candidateAtPoint = {
      media: descendant,
      area: rect.width * rect.height,
      stackIndex,
      inModal: isElementInModal(descendant),
    };

    best = selectPreferredPointCandidate(best, candidateAtPoint);
    if (!best) {
      best = candidateAtPoint;
    }
  }

  return best ? { media: best.media, area: best.area } : null;
}

type PointMediaCandidate = {
  media: Element;
  area: number;
  stackIndex: number;
  inModal: boolean;
};

const VIDEO_PREFERENCE_AREA_RATIO = 0.2;

function selectPreferredMediaTypeCandidate<T extends { media: Element; area: number }>(current: T, next: T): T | null {
  const currentIsVideo = current.media.tagName === 'VIDEO';
  const nextIsVideo = next.media.tagName === 'VIDEO';
  if (currentIsVideo === nextIsVideo) {
    return null;
  }

  const videoCandidate = currentIsVideo ? current : next;
  const imageCandidate = currentIsVideo ? next : current;
  if (videoCandidate.area >= imageCandidate.area * VIDEO_PREFERENCE_AREA_RATIO) {
    return videoCandidate;
  }

  return null;
}

function selectPreferredPointCandidate(
  current: PointMediaCandidate | null,
  next: PointMediaCandidate
): PointMediaCandidate {
  if (!current) {
    return next;
  }

  if (current.inModal !== next.inModal) {
    return next.inModal ? next : current;
  }

  const typePreferred = selectPreferredMediaTypeCandidate(current, next);
  if (typePreferred) {
    return typePreferred;
  }

  if (next.area !== current.area) {
    return next.area > current.area ? next : current;
  }

  return next.stackIndex < current.stackIndex ? next : current;
}

export function resolveMediaAtPoint(x: number, y: number, rootId: string): Element | null {
  const rawStack = document.elementsFromPoint?.(x, y) ?? [];
  const stack = rawStack.filter((node): node is Element => node instanceof Element);
  if (stack.length === 0) {
    const fallback = document.elementFromPoint?.(x, y);
    if (fallback instanceof Element) {
      stack.push(fallback);
    }
  }

  let best: PointMediaCandidate | null = null;

  for (const [stackIndex, node] of stack.entries()) {
    if (isOwnUiElement(node, rootId)) {
      continue;
    }

    if (node.matches('img, video')) {
      const rect = node.getBoundingClientRect();
      best = selectPreferredPointCandidate(best, {
        media: node,
        area: Math.max(rect.width * rect.height, 1),
        stackIndex,
        inModal: isElementInModal(node),
      });
    }
  }

  for (const [stackIndex, node] of stack.entries()) {
    if (isOwnUiElement(node, rootId)) {
      continue;
    }

    const nested = pickLargestMediaDescendantAtPoint(node, x, y);
    if (!nested) {
      continue;
    }

    best = selectPreferredPointCandidate(best, {
      media: nested.media,
      area: nested.area,
      stackIndex,
      inModal: isElementInModal(nested.media),
    });
  }

  return best ? best.media : null;
}

function getElementViewportArea(element: Element): number {
  const rect = element.getBoundingClientRect();
  if (rect.width <= 0 || rect.height <= 0) {
    return 0;
  }

  const left = Math.max(rect.left, 0);
  const top = Math.max(rect.top, 0);
  const right = Math.min(rect.right, window.innerWidth);
  const bottom = Math.min(rect.bottom, window.innerHeight);
  const width = right - left;
  const height = bottom - top;
  if (width <= 0 || height <= 0) {
    return 0;
  }

  return width * height;
}

export function choosePromotedMediaCandidate(currentMedia: Element | null, rootId: string): Element | null {
  const candidates = Array.from(document.querySelectorAll('img, video')).filter((media) => !isOwnUiElement(media, rootId));
  if (candidates.length === 0) {
    return null;
  }

  const currentArea = currentMedia ? getElementViewportArea(currentMedia) : 0;
  const currentIsModal = currentMedia ? isElementInModal(currentMedia) : false;
  const currentIsVideo = currentMedia?.tagName === 'VIDEO';

  let bestModal: { media: Element; area: number } | null = null;
  let bestModalVideo: { media: Element; area: number } | null = null;
  let bestAny: { media: Element; area: number } | null = null;
  let bestVideo: { media: Element; area: number } | null = null;

  for (const media of candidates) {
    const area = getElementViewportArea(media);
    if (area <= 0) {
      continue;
    }

    if (!bestAny || area > bestAny.area) {
      bestAny = { media, area };
    }

    if (media.tagName === 'VIDEO' && (!bestVideo || area > bestVideo.area)) {
      bestVideo = { media, area };
    }

    if (isElementInModal(media) && (!bestModal || area > bestModal.area)) {
      bestModal = { media, area };
    }

    if (media.tagName === 'VIDEO' && isElementInModal(media) && (!bestModalVideo || area > bestModalVideo.area)) {
      bestModalVideo = { media, area };
    }
  }

  if (currentIsVideo) {
    if (bestModalVideo && bestModalVideo.media !== currentMedia) {
      if (!currentIsModal || bestModalVideo.area > currentArea * 1.05) {
        return bestModalVideo.media;
      }
    }

    if (bestVideo && bestVideo.media !== currentMedia && bestVideo.area > currentArea * 1.2) {
      return bestVideo.media;
    }

    return null;
  }

  if (bestModalVideo && bestModalVideo.media !== currentMedia) {
    if (!currentIsModal || bestModalVideo.area > currentArea * 1.05) {
      return bestModalVideo.media;
    }
  }

  if (bestModal && bestModal.media !== currentMedia) {
    if (!currentIsModal || bestModal.area > currentArea * 1.1) {
      return bestModal.media;
    }
  }

  if (bestVideo && bestVideo.media !== currentMedia && bestVideo.area > currentArea * 1.1) {
    return bestVideo.media;
  }

  if (bestAny && bestAny.media !== currentMedia && bestAny.area > currentArea * 1.6) {
    return bestAny.media;
  }

  return null;
}
