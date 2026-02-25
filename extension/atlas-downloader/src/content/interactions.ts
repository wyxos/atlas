import { buildItemFromElement } from './items';
import {
  resolveBestDeviantArtPostDownloadUrl,
  resolveDeviantArtPostContext,
  resolveWixAssetKey,
  type DeviantArtPostContext,
} from './deviantartPost';
import { isElementInModal } from './media';
import { BLACKLIST_ACTION, REACTIONS, createSvgIcon } from './reactions';
import type { DialogChooser } from './ui';

const LOCATION_CHANGE_EVENT = 'atlas-location-change';

type AtlasStatus = {
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

type AtlasReactResponse = {
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

type SendMessageSafe = (message: unknown, callback: (response: AtlasReactResponse) => void) => void;

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
  minSize: number;
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

function isOwnUiElement(element: Element, rootId: string): boolean {
  return Boolean(element.closest?.(`#${rootId}`));
}

function normalizeProgress(value: unknown): number | null {
  const parsed = Number(value);
  if (!Number.isFinite(parsed)) {
    return null;
  }

  return clamp(parsed, 0, 100);
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

function parseFileStatusMeta(file: unknown): { downloadProgress: number | null; downloadedAt: string | null } {
  if (!file || typeof file !== 'object') {
    return {
      downloadProgress: null,
      downloadedAt: null,
    };
  }

  const value = file as { download_progress?: unknown; downloaded_at?: unknown; updated_at?: unknown; downloaded?: unknown };
  const downloaded = Boolean(value.downloaded);
  const downloadedAt = normalizeDownloadedAt(value.downloaded_at);
  return {
    downloadProgress: normalizeProgress(value.download_progress),
    downloadedAt: downloadedAt ?? (downloaded ? normalizeDownloadedAt(value.updated_at) : null),
  };
}

type AtlasBatchResult = {
  ok?: boolean;
  error?: string;
  data?: {
    file?: {
      downloaded?: boolean;
      blacklisted_at?: unknown;
    } | null;
  } | null;
};

type AtlasBatchResponse = {
  ok?: boolean;
  error?: string;
  results?: AtlasBatchResult[];
};

function sendMessageSafeAsync(sendMessageSafe: SendMessageSafe, message: unknown): Promise<unknown> {
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

  let best: { media: Element; area: number } | null = null;
  for (const descendant of descendants) {
    const rect = descendant.getBoundingClientRect();
    if (
      rect.width <= 0 ||
      rect.height <= 0 ||
      x < rect.left ||
      x > rect.right ||
      y < rect.top ||
      y > rect.bottom
    ) {
      continue;
    }

    const area = rect.width * rect.height;
    if (!best || area > best.area) {
      best = { media: descendant, area };
    }
  }

  return best;
}

type PointMediaCandidate = {
  media: Element;
  area: number;
  stackIndex: number;
  inModal: boolean;
};

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

  let bestModal: { media: Element; area: number } | null = null;
  let bestAny: { media: Element; area: number } | null = null;

  for (const media of candidates) {
    const area = getElementViewportArea(media);
    if (area <= 0) {
      continue;
    }

    if (!bestAny || area > bestAny.area) {
      bestAny = { media, area };
    }

    if (isElementInModal(media) && (!bestModal || area > bestModal.area)) {
      bestModal = { media, area };
    }
  }

  if (bestModal && bestModal.media !== currentMedia) {
    if (!currentIsModal || bestModal.area > currentArea * 1.1) {
      return bestModal.media;
    }
  }

  if (bestAny && bestAny.media !== currentMedia && bestAny.area > currentArea * 1.6) {
    return bestAny.media;
  }

  return null;
}

export function installHotkeys(options: HotkeysOptions, deps: InteractionDependencies) {
  const enabled = options.enabled ?? true;
  const getHintShown = options.getHintShown ?? (() => false);
  const setHintShown = options.setHintShown ?? (() => {});

  const maybeHint = () => {
    if (getHintShown()) return;
    setHintShown(true);
    options.showToast('Hotkeys: Alt+Left=Like, Alt+Middle=Love, Alt+Right=Dislike');
  };

  const emitShortcutReactionState = (
    media: Element,
    pending: boolean,
    reactionType: string | null,
    url: string | null = null
  ) => {
    window.dispatchEvent(
      new CustomEvent('atlas-shortcut-reaction-state', {
        detail: {
          media,
          pending,
          reactionType,
          url,
        },
      })
    );
  };

  const isOwnUiEvent = (event: MouseEvent) => {
    const path = typeof event.composedPath === 'function' ? event.composedPath() : [];
    return path.some((p) => p instanceof HTMLElement && p.id === deps.rootId);
  };
  const resolveEventMedia = (event: MouseEvent): Element | null => {
    const target = event.target instanceof Element ? event.target : null;
    const byTarget = target?.closest?.('img, video') ?? null;
    if (byTarget instanceof Element && !isOwnUiElement(byTarget, deps.rootId)) {
      return byTarget;
    }

    const byPoint = resolveMediaAtPoint(event.clientX, event.clientY, deps.rootId);
    if (byPoint) {
      return byPoint;
    }

    return null;
  };

  // Some sites (especially video players) trigger actions on click/pointerup even if mousedown is prevented.
  // Swallow the click when we're handling a hotkey so play/pause/seek doesn't fire.
  document.addEventListener(
    'click',
    (e) => {
      if (!enabled) return;
      if (!(e instanceof MouseEvent)) return;
      if (!e.altKey) return;
      if (options.isSheetOpen()) return;
      if (isOwnUiEvent(e)) return;

      const media = resolveEventMedia(e);
      if (!media) return;

      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();
    },
    true
  );

  document.addEventListener(
    'mousedown',
    (e) => {
      if (!enabled) return;
      if (!(e instanceof MouseEvent)) return;
      if (!e.altKey) return;
      if (options.isSheetOpen()) return;
      if (isOwnUiEvent(e)) return;

      const media = resolveEventMedia(e);
      if (!media) return;

      const reactionType = e.button === 0 ? 'like' : e.button === 1 ? 'love' : null;
      if (!reactionType) return;

      maybeHint();

      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();

      const item = buildItemFromElement(media, deps.minSize);
      if (!item) {
        if (media instanceof HTMLVideoElement) {
          const rawSrc = (media.currentSrc || media.src || '').trim().toLowerCase();
          if (rawSrc.startsWith('blob:') || rawSrc.startsWith('data:')) {
            // Fallback: send the page URL and let Atlas resolve/download via yt-dlp.
            const pageUrl = window.location.href;
            const payload = {
              type: reactionType,
              url: pageUrl,
              referrer_url: pageUrl,
              page_title: deps.limitString(document.title, deps.maxMetadataLen),
              tag_name: 'video',
              width: media.videoWidth || media.clientWidth || null,
              height: media.videoHeight || media.clientHeight || null,
              alt: '',
              preview_url: media.poster || '',
              source: deps.sourceFromMediaUrl(pageUrl),
              download_via: 'yt-dlp',
            };

            deps.fetchAtlasStatus(options.sendMessageSafe, payload.url, payload.referrer_url || null, (status) => {
              if (status?.downloaded) {
                options
                  .chooseDialog({
                    title: 'Already downloaded',
                    message: 'Re-download before updating the reaction?',
                    confirmLabel: 'Re-download',
                    cancelLabel: 'Keep existing file',
                    alternateLabel: 'Cancel',
                  })
                  .then((choice) => {
                    if (choice === 'alternate') {
                      options.showToast('Cancelled.');
                      return;
                    }
                    emitShortcutReactionState(media, true, reactionType, payload.url);
                    if (choice === 'confirm') {
                      payload.force_download = true;
                    }

                    options.sendMessageSafe({ type: 'atlas-react', payload }, (response) => {
                      if (!response || !response.ok) {
                        emitShortcutReactionState(media, false, null, payload.url);
                        options.showToast(response?.error || 'Reaction failed.', 'danger');
                        return;
                      }

                      const data = response.data || null;
                      const file = data?.file || null;
                      const newReactionType = data?.reaction?.type
                        ? String(data.reaction.type)
                        : reactionType;
                      const fileMeta = parseFileStatusMeta(file);
                      deps.atlasStatusCache.set(payload.url, {
                        exists: Boolean(file),
                        downloaded: Boolean(file?.downloaded),
                        blacklisted: Boolean(file?.blacklisted_at),
                        reactionType: newReactionType,
                        downloadProgress: fileMeta.downloadProgress,
                        downloadedAt: fileMeta.downloadedAt,
                        ts: Date.now(),
                      });
                      emitShortcutReactionState(media, false, newReactionType, payload.url);

                      options.showToast(`Reacted (${reactionType}). Resolving video in Atlas…`);
                    });
                  });
                return;
              }
              emitShortcutReactionState(media, true, reactionType, payload.url);
              options.sendMessageSafe({ type: 'atlas-react', payload }, (response) => {
                if (!response || !response.ok) {
                  emitShortcutReactionState(media, false, null, payload.url);
                  options.showToast(response?.error || 'Reaction failed.', 'danger');
                  return;
                }

                const data = response.data || null;
                const file = data?.file || null;
                const newReactionType = data?.reaction?.type
                  ? String(data.reaction.type)
                  : reactionType;
                const fileMeta = parseFileStatusMeta(file);
                deps.atlasStatusCache.set(payload.url, {
                  exists: Boolean(file),
                  downloaded: Boolean(file?.downloaded),
                  blacklisted: Boolean(file?.blacklisted_at),
                  reactionType: newReactionType,
                  downloadProgress: fileMeta.downloadProgress,
                  downloadedAt: fileMeta.downloadedAt,
                  ts: Date.now(),
                });
                emitShortcutReactionState(media, false, newReactionType, payload.url);

                options.showToast(`Reacted (${reactionType}). Resolving video in Atlas…`);
              });
            });

            return;
          }

          options.showToast('No direct video URL found.');
          return;
        }

        options.showToast('No valid media URL found.');
        return;
      }

      const payload = {
        type: reactionType,
        url: item.url,
        referrer_url: item.referrer_url || window.location.href,
        page_title: deps.limitString(document.title, deps.maxMetadataLen),
        tag_name: item.tag_name,
        width: item.width,
        height: item.height,
        alt: deps.limitString(item.alt || '', deps.maxMetadataLen),
        preview_url: item.preview_url || '',
        source: deps.sourceFromMediaUrl(item.url),
      };

      deps.fetchAtlasStatus(options.sendMessageSafe, payload.url, payload.referrer_url || null, (status) => {
        if (status?.downloaded) {
          options
            .chooseDialog({
              title: 'Already downloaded',
              message: 'Re-download before updating the reaction?',
              confirmLabel: 'Re-download',
              cancelLabel: 'Keep existing file',
              alternateLabel: 'Cancel',
            })
            .then((choice) => {
              if (choice === 'alternate') {
                options.showToast('Cancelled.');
                return;
              }
              emitShortcutReactionState(media, true, reactionType, payload.url);
              if (choice === 'confirm') {
                payload.force_download = true;
              }

              options.sendMessageSafe({ type: 'atlas-react', payload }, (response) => {
                if (!response || !response.ok) {
                  emitShortcutReactionState(media, false, null, payload.url);
                  options.showToast(response?.error || 'Reaction failed.', 'danger');
                  return;
                }

                const data = response.data || null;
                const file = data?.file || null;
                const newReactionType = data?.reaction?.type ? String(data.reaction.type) : reactionType;
                const fileMeta = parseFileStatusMeta(file);
                deps.atlasStatusCache.set(payload.url, {
                  exists: Boolean(file),
                  downloaded: Boolean(file?.downloaded),
                  blacklisted: Boolean(file?.blacklisted_at),
                  reactionType: newReactionType,
                  downloadProgress: fileMeta.downloadProgress,
                  downloadedAt: fileMeta.downloadedAt,
                  ts: Date.now(),
                });
                emitShortcutReactionState(media, false, newReactionType, payload.url);

                options.showToast(`Reacted (${reactionType}). Queued download in Atlas.`);
              });
            });
          return;
        }
        emitShortcutReactionState(media, true, reactionType, payload.url);
        options.sendMessageSafe({ type: 'atlas-react', payload }, (response) => {
          if (!response || !response.ok) {
            emitShortcutReactionState(media, false, null, payload.url);
            options.showToast(response?.error || 'Reaction failed.', 'danger');
            return;
          }

          const data = response.data || null;
          const file = data?.file || null;
          const newReactionType = data?.reaction?.type ? String(data.reaction.type) : reactionType;
          const fileMeta = parseFileStatusMeta(file);
          deps.atlasStatusCache.set(payload.url, {
            exists: Boolean(file),
            downloaded: Boolean(file?.downloaded),
            blacklisted: Boolean(file?.blacklisted_at),
            reactionType: newReactionType,
            downloadProgress: fileMeta.downloadProgress,
            downloadedAt: fileMeta.downloadedAt,
            ts: Date.now(),
          });
          emitShortcutReactionState(media, false, newReactionType, payload.url);

          options.showToast(`Reacted (${reactionType}). Queued download in Atlas.`);
        });
      });
    },
    true
  );

  document.addEventListener(
    'contextmenu',
    (e) => {
      if (!enabled) return;
      if (!(e instanceof MouseEvent)) return;
      if (!e.altKey) return;
      if (options.isSheetOpen()) return;
      if (isOwnUiEvent(e)) return;

      const media = resolveEventMedia(e);
      if (!media) return;

      maybeHint();

      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();

      const item = buildItemFromElement(media, deps.minSize);
      if (!item) {
        if (media instanceof HTMLVideoElement) {
          const rawSrc = (media.currentSrc || media.src || '').trim().toLowerCase();
          if (rawSrc.startsWith('blob:') || rawSrc.startsWith('data:')) {
            const pageUrl = window.location.href;
            const payload = {
              type: 'dislike',
              url: pageUrl,
              referrer_url: pageUrl,
              page_title: deps.limitString(document.title, deps.maxMetadataLen),
              tag_name: 'video',
              width: media.videoWidth || media.clientWidth || null,
              height: media.videoHeight || media.clientHeight || null,
              alt: '',
              preview_url: media.poster || '',
              source: deps.sourceFromMediaUrl(pageUrl),
              download_via: 'yt-dlp',
            };

            emitShortcutReactionState(media, true, 'dislike', payload.url);
            options.sendMessageSafe({ type: 'atlas-react', payload }, (response) => {
              if (!response || !response.ok) {
                emitShortcutReactionState(media, false, null, payload.url);
                options.showToast(response?.error || 'Reaction failed.', 'danger');
                return;
              }
              emitShortcutReactionState(media, false, 'dislike', payload.url);
              options.showToast('Disliked.');
            });

            return;
          }

          options.showToast('No direct video URL found.');
          return;
        }

        options.showToast('No valid media URL found.');
        return;
      }

      const payload = {
        type: 'dislike',
        url: item.url,
        referrer_url: item.referrer_url || window.location.href,
        page_title: deps.limitString(document.title, deps.maxMetadataLen),
        tag_name: item.tag_name,
        width: item.width,
        height: item.height,
        alt: deps.limitString(item.alt || '', deps.maxMetadataLen),
        preview_url: item.preview_url || '',
        source: deps.sourceFromMediaUrl(item.url),
      };

      emitShortcutReactionState(media, true, 'dislike', payload.url);
      options.sendMessageSafe({ type: 'atlas-react', payload }, (response) => {
        if (!response || !response.ok) {
          emitShortcutReactionState(media, false, null, payload.url);
          options.showToast(response?.error || 'Reaction failed.', 'danger');
          return;
        }
        emitShortcutReactionState(media, false, 'dislike', payload.url);
        options.showToast('Disliked.');
      });
    },
    true
  );

  document.addEventListener(
    'auxclick',
    (e) => {
      if (!enabled) return;
      if (!(e instanceof MouseEvent)) return;
      if (!e.altKey || e.button !== 1) return;
      if (options.isSheetOpen()) return;
      if (isOwnUiEvent(e)) return;

      const media = resolveEventMedia(e);
      if (!media) return;

      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();
    },
    true
  );
}

type OverlayOptions = {
  root: HTMLElement;
  showToast: (message: string, tone?: 'info' | 'danger') => void;
  sendMessageSafe: SendMessageSafe;
  isSheetOpen: () => boolean;
  chooseDialog: DialogChooser;
};

type AtlasWindow = Window & {
  __atlasLocationObserverInstalled?: boolean;
};

function clamp(value: number, min: number, max: number) {
  if (value < min) return min;
  if (value > max) return max;
  return value;
}

function installLocationChangeObserver() {
  const atlasWindow = window as AtlasWindow;
  if (atlasWindow.__atlasLocationObserverInstalled) {
    return;
  }
  atlasWindow.__atlasLocationObserverInstalled = true;

  const emit = () => {
    window.dispatchEvent(new Event(LOCATION_CHANGE_EVENT));
  };

  const wrapHistoryMethod = (method: 'pushState' | 'replaceState') => {
    const original = history[method].bind(history);
    history[method] = ((...args: Parameters<History[typeof method]>) => {
      const result = original(...args);
      emit();
      return result;
    }) as History[typeof method];
  };

  wrapHistoryMethod('pushState');
  wrapHistoryMethod('replaceState');
  window.addEventListener('popstate', emit, true);
  window.addEventListener('hashchange', emit, true);
}

function buildOverlayReactionPayload(
  media: Element,
  reactionType: string,
  deps: InteractionDependencies
) {
  const item = buildItemFromElement(media, deps.minSize);
  if (item) {
    return {
      type: reactionType,
      url: item.url,
      referrer_url: item.referrer_url || window.location.href,
      page_title: deps.limitString(document.title, deps.maxMetadataLen),
      tag_name: item.tag_name,
      width: item.width,
      height: item.height,
      alt: deps.limitString(item.alt || '', deps.maxMetadataLen),
      preview_url: item.preview_url || '',
      source: deps.sourceFromMediaUrl(item.url),
    };
  }

  if (media instanceof HTMLVideoElement) {
    const rawSrc = (media.currentSrc || media.src || '').trim().toLowerCase();
    if (rawSrc.startsWith('blob:') || rawSrc.startsWith('data:')) {
      const pageUrl = window.location.href;

      return {
        type: reactionType,
        url: pageUrl,
        referrer_url: pageUrl,
        page_title: deps.limitString(document.title, deps.maxMetadataLen),
        tag_name: 'video',
        width: media.videoWidth || media.clientWidth || null,
        height: media.videoHeight || media.clientHeight || null,
        alt: '',
        preview_url: media.poster || '',
        source: deps.sourceFromMediaUrl(pageUrl),
        download_via: 'yt-dlp',
      };
    }
  }

  return null;
}

export function installMediaReactionOverlay(options: OverlayOptions, deps: InteractionDependencies) {
  installLocationChangeObserver();

  const toolbar = document.createElement('div');
  toolbar.className = 'atlas-downloader-media-toolbar';
  toolbar.setAttribute('role', 'toolbar');
  toolbar.setAttribute('aria-label', 'Atlas reactions');
  const resolutionMeta = document.createElement('span');
  resolutionMeta.className = 'atlas-downloader-media-resolution';
  resolutionMeta.hidden = true;
  toolbar.appendChild(resolutionMeta);
  const postIndicator = document.createElement('button');
  postIndicator.type = 'button';
  postIndicator.className = 'atlas-downloader-post-indicator';
  postIndicator.textContent = 'POST';
  postIndicator.title = 'DeviantArt post: Alt+Left=Like, Alt+Middle=Love (favorite) queue';
  postIndicator.hidden = true;
  toolbar.appendChild(postIndicator);
  const statusMeta = document.createElement('span');
  statusMeta.className = 'atlas-downloader-media-status';
  statusMeta.hidden = true;
  const progressMeta = document.createElement('div');
  progressMeta.className = 'atlas-downloader-media-progress';
  progressMeta.hidden = true;
  const progressBar = document.createElement('span');
  progressBar.className = 'atlas-downloader-media-progress-bar';
  const progressFill = document.createElement('span');
  progressFill.className = 'atlas-downloader-media-progress-fill';
  progressBar.appendChild(progressFill);
  progressMeta.appendChild(progressBar);

  let activeMedia: Element | null = null;
  let activeKey: string | null = null;
  let activeLookupKeys: string[] = [];
  let hideFrameA: number | null = null;
  let hideFrameB: number | null = null;
  let toolbarBusy = false;
  let toolbarQueuedType: string | null = null;
  let toolbarPendingType: string | null = null;
  let pointerX = -1;
  let pointerY = -1;
  let refreshMediaContextFrame: number | null = null;
  let activeLocationHref = window.location.href;
  let activePostContext: DeviantArtPostContext | null = null;
  let postDownloadBusy = false;

  const buttonsByType = new Map<string, HTMLButtonElement>();
  const buildLookupKeys = (...values: Array<string | null | undefined>): string[] => {
    const keys = new Set<string>();
    for (const value of values) {
      const raw = (value || '').trim();
      if (!raw) {
        continue;
      }

      keys.add(raw);
      const hashlessIndex = raw.indexOf('#');
      if (hashlessIndex > 0) {
        keys.add(raw.slice(0, hashlessIndex));
      }
    }

    return [...keys];
  };
  const getCachedForActiveKeys = (): AtlasStatus | null => {
    for (const key of activeLookupKeys) {
      const cached = deps.getCachedAtlasStatus(key);
      if (cached) {
        return cached;
      }
    }

    return null;
  };
  const formatResolution = (width: number | null | undefined, height: number | null | undefined): string => {
    const normalizedWidth =
      typeof width === 'number' && Number.isFinite(width) && width > 0 ? Math.round(width) : null;
    const normalizedHeight =
      typeof height === 'number' && Number.isFinite(height) && height > 0 ? Math.round(height) : null;

    if (!normalizedWidth || !normalizedHeight) {
      return '';
    }

    return `${normalizedWidth}x${normalizedHeight}`;
  };
  const setToolbarResolution = (width: number | null | undefined, height: number | null | undefined) => {
    const text = formatResolution(width, height);
    if (!text) {
      resolutionMeta.textContent = '';
      resolutionMeta.hidden = true;
      return;
    }

    resolutionMeta.textContent = text;
    resolutionMeta.hidden = false;
  };
  const setToolbarStatusMeta = (status: AtlasStatus | null) => {
    const text = formatOverlayDownloadMeta(status);
    const progress = resolveOverlayProgressPercent(status);

    if (!text) {
      statusMeta.textContent = '';
      statusMeta.hidden = true;
    } else {
      statusMeta.textContent = text;
      statusMeta.hidden = false;
    }

    if (progress === null) {
      progressMeta.hidden = true;
      progressMeta.removeAttribute('data-state');
      progressFill.style.width = '0%';
      return;
    }

    progressMeta.hidden = false;
    progressMeta.dataset.state = progress <= 0 ? 'queued' : progress >= 100 ? 'done' : 'active';
    progressFill.style.width = `${Math.max(0, Math.min(100, progress))}%`;
  };
  const syncPostIndicatorState = () => {
    const count = activePostContext?.entries.length ?? 0;
    const visible = count > 1;
    postIndicator.hidden = !visible;
    postIndicator.disabled = !visible || postDownloadBusy || toolbarBusy;
    postIndicator.classList.toggle('pending', postDownloadBusy);
    if (!visible) {
      postIndicator.textContent = 'POST';
      postIndicator.removeAttribute('data-count');
      return;
    }

    postIndicator.dataset.count = String(count);
    postIndicator.textContent = `POST x${count}`;
  };
  const syncToolbarButtonState = () => {
    const locked = toolbarBusy || toolbarQueuedType !== null;
    for (const [type, button] of buttonsByType.entries()) {
      button.disabled = locked;
      button.classList.toggle('pending', toolbarBusy && toolbarPendingType === type);
    }
    syncPostIndicatorState();
  };
  const setToolbarBusy = (busy: boolean, pendingType: string | null = null) => {
    toolbarBusy = busy;
    toolbarPendingType = busy ? pendingType : null;
    syncToolbarButtonState();
  };
  const setToolbarActive = (reactionType: string | null) => {
    for (const reaction of [...REACTIONS, BLACKLIST_ACTION]) {
      const btn = buttonsByType.get(reaction.type);
      if (!btn) continue;
      const isActive =
        reaction.type === BLACKLIST_ACTION.type
          ? reactionType === 'dislike'
          : reactionType === reaction.type;
      btn.classList.toggle('active', isActive);
    }
  };
  const setToolbarQueued = (reactionType: string | null, downloaded: boolean | null) => {
    toolbarQueuedType = reactionType && downloaded === false && reactionType !== 'dislike' ? reactionType : null;
    for (const reaction of [...REACTIONS, BLACKLIST_ACTION]) {
      const btn = buttonsByType.get(reaction.type);
      if (!btn) continue;
      const isQueued =
        reaction.type === BLACKLIST_ACTION.type
          ? toolbarQueuedType === 'dislike'
          : toolbarQueuedType === reaction.type;
      btn.classList.toggle('queued', isQueued);
    }
    syncToolbarButtonState();
  };
  const emitOverlayReactionState = (pending: boolean, reactionType: string | null, url: string | null) => {
    if (!activeMedia) {
      return;
    }

    window.dispatchEvent(
      new CustomEvent('atlas-shortcut-reaction-state', {
        detail: {
          media: activeMedia,
          pending,
          reactionType,
          url,
        },
      })
    );
  };
  const clearPendingOverlayState = () => {
    if (!toolbarBusy) {
      return;
    }
    emitOverlayReactionState(false, null, activeKey || null);
    setToolbarBusy(false, null);
  };
  const handleLocationChange = () => {
    const currentHref = window.location.href;
    if (currentHref === activeLocationHref) {
      return;
    }
    activeLocationHref = currentHref;
    activePostContext = null;
    postDownloadBusy = false;
    syncPostIndicatorState();
    clearPendingOverlayState();
    hide(true);
  };

  const cancelHide = () => {
    if (hideFrameA !== null) {
      window.cancelAnimationFrame(hideFrameA);
      hideFrameA = null;
    }
    if (hideFrameB !== null) {
      window.cancelAnimationFrame(hideFrameB);
      hideFrameB = null;
    }
  };

  const hide = (forceOrEvent?: boolean | Event) => {
    const force = forceOrEvent === true;
    if (toolbarBusy && !force) {
      return;
    }
    activeMedia = null;
    activeKey = null;
    activeLookupKeys = [];
    activePostContext = null;
    toolbar.classList.remove('open');
    toolbar.style.left = '';
    toolbar.style.top = '';
    setToolbarResolution(null, null);
    setToolbarStatusMeta(null);
    setToolbarActive(null);
    syncPostIndicatorState();
  };

  const scheduleHide = () => {
    cancelHide();
    hideFrameA = window.requestAnimationFrame(() => {
      hideFrameA = null;
      hideFrameB = window.requestAnimationFrame(() => {
        hideFrameB = null;
        if (toolbar.matches(':hover')) return;
        if (activeMedia instanceof Element && activeMedia.matches(':hover')) return;
        hide();
      });
    });
  };

  const swallow = (event: Event) => {
    event.preventDefault();
    event.stopPropagation();
    // @ts-expect-error stopImmediatePropagation exists on MouseEvent/PointerEvent.
    event.stopImmediatePropagation?.();
  };

  const queueActivePostDownloads = async (reactionType: 'like' | 'love' | 'funny' = 'like') => {
    if (postDownloadBusy) {
      return;
    }

    let context = activePostContext;
    if (!context || context.entries.length <= 1) {
      options.showToast('Scanning post media…');
      const refreshedContext = resolveDeviantArtPostContext(window.location.href, activeMedia);
      if (refreshedContext) {
        activePostContext = refreshedContext;
        context = refreshedContext;
        syncPostIndicatorState();
      }
    }

    if (!context || context.entries.length <= 1) {
      options.showToast('No DeviantArt post images found.');
      return;
    }

    const basePageUrl = window.location.href.split('#')[0] || window.location.href;
    const payloads = context.entries.map((entry, index) => {
      const url = resolveBestDeviantArtPostDownloadUrl(entry) || entry.baseUrl;
      const referrerHash = `${basePageUrl}#image-${index + 1}`;
      return {
        url,
        referrer_url: referrerHash,
        page_title: deps.limitString(document.title, deps.maxMetadataLen),
        tag_name: 'img',
        width: entry.maxWidth ?? entry.width ?? null,
        height: entry.maxHeight ?? entry.height ?? null,
        alt: '',
        preview_url: entry.previewUrl || entry.baseUrl || '',
        source: deps.sourceFromMediaUrl(url),
        reaction_type: reactionType,
      };
    });

    if (payloads.length === 0) {
      options.showToast('No downloadable post images found.');
      return;
    }

    postDownloadBusy = true;
    syncPostIndicatorState();

    try {
      const response = (await sendMessageSafeAsync(options.sendMessageSafe, {
        type: 'atlas-download-batch',
        payloads,
      })) as AtlasBatchResponse | null | undefined;

      const rawResults = Array.isArray(response?.results) ? response.results : [];
      const okResults = rawResults.filter((result) => Boolean(result?.ok));
      const successCount = rawResults.length > 0 ? okResults.length : response?.ok ? payloads.length : 0;

      if (successCount <= 0) {
        options.showToast(response?.error || 'Batch download failed.', 'danger');
        return;
      }

      const now = Date.now();
      const cacheCount = rawResults.length > 0 ? Math.min(rawResults.length, payloads.length) : payloads.length;
      for (let index = 0; index < cacheCount; index += 1) {
        const result = rawResults[index];
        if (rawResults.length > 0 && !result?.ok) {
          continue;
        }

        const payload = payloads[index];
        if (!payload?.url) {
          continue;
        }

        const file = result?.data?.file || null;
        const fileMeta = parseFileStatusMeta(file);
        const nextStatus = {
          exists: true,
          downloaded: Boolean(file?.downloaded),
          blacklisted: Boolean(file?.blacklisted_at),
          reactionType: String(payload.reaction_type || 'like'),
          downloadProgress: fileMeta.downloadProgress,
          downloadedAt: fileMeta.downloadedAt,
          ts: now,
        };
        for (const key of buildLookupKeys(payload.url, payload.preview_url, payload.referrer_url)) {
          deps.atlasStatusCache.set(key, nextStatus);
        }
      }

      window.dispatchEvent(new Event('atlas-status-cache-updated'));
      if (activeLookupKeys.length > 0) {
        const cached = getCachedForActiveKeys();
        setToolbarStatusMeta(cached);
        setToolbarQueued(cached?.reactionType ?? null, cached?.downloaded ?? null);
      }

      if (successCount < payloads.length) {
        options.showToast(`Queued ${successCount}/${payloads.length} post image downloads in Atlas.`);
        return;
      }

      options.showToast(`Queued ${successCount} post image download(s) in Atlas.`);
    } finally {
      postDownloadBusy = false;
      syncPostIndicatorState();
    }
  };

  postIndicator.addEventListener(
    'pointerdown',
    (event) => {
      if (!event.altKey || (event.button !== 0 && event.button !== 1)) {
        return;
      }

      swallow(event);
      const postReaction = event.button === 1 ? 'love' : 'like';
      void queueActivePostDownloads(postReaction);
    },
    true
  );
  postIndicator.addEventListener('contextmenu', (event) => {
    if (!event.altKey) {
      return;
    }

    swallow(event);
    void queueActivePostDownloads('like');
  });
  postIndicator.addEventListener('auxclick', (event) => {
    if (!event.altKey || event.button !== 1) {
      return;
    }

    swallow(event);
  });
  postIndicator.addEventListener('click', (event) => {
    swallow(event);
    if (event.altKey) {
      return;
    }

    options.showToast('POST: Alt+Left queues as Like, Alt+Middle queues as Love.');
  });

  for (const reaction of [...REACTIONS, BLACKLIST_ACTION]) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `atlas-downloader-reaction-btn ${reaction.className}`.trim();
    button.setAttribute('aria-label', reaction.label);
    button.title = reaction.label;
    button.replaceChildren(createSvgIcon(reaction.pathDs));
    buttonsByType.set(reaction.type, button);

    button.addEventListener('pointerdown', swallow, true);
    button.addEventListener('mousedown', swallow, true);
    button.addEventListener('click', (event) => {
      swallow(event);
      if (toolbarBusy) {
        return;
      }

      if (!activeMedia) {
        options.showToast('No media selected.');
        return;
      }

      const reactionType = reaction.type === BLACKLIST_ACTION.type ? 'dislike' : reaction.type;
      const payload = buildOverlayReactionPayload(activeMedia, reactionType, deps);
      if (!payload) {
        options.showToast('No valid media URL found.');
        return;
      }

      const checkKey = payload.url || '';

      const submitReaction = (forceDownload = false) => {
        if (forceDownload) {
          payload.force_download = true;
        } else if ('force_download' in payload) {
          delete payload.force_download;
        }

        emitOverlayReactionState(true, reaction.type, checkKey || null);
        setToolbarBusy(true, reaction.type);
        options.sendMessageSafe(
          {
            type: 'atlas-react',
            payload: {
              ...payload,
              ...(reaction.type === BLACKLIST_ACTION.type ? { blacklist: true } : {}),
            },
          },
          (response) => {
            setToolbarBusy(false, null);
            if (!response || !response.ok) {
              emitOverlayReactionState(false, null, checkKey || null);
              options.showToast(response?.error || 'Reaction failed.', 'danger');
              return;
            }

            const data = response.data || null;
            const file = data?.file || null;
            const newReactionType = data?.reaction?.type
              ? String(data.reaction.type)
              : reaction.type === BLACKLIST_ACTION.type
                ? 'dislike'
                : reaction.type;
            const fileMeta = parseFileStatusMeta(file);

            if (checkKey) {
              deps.atlasStatusCache.set(checkKey, {
                exists: Boolean(file),
                downloaded: Boolean(file?.downloaded),
                blacklisted: Boolean(file?.blacklisted_at),
                reactionType: newReactionType,
                downloadProgress: fileMeta.downloadProgress,
                downloadedAt: fileMeta.downloadedAt,
                ts: Date.now(),
              });
            }

            setToolbarActive(newReactionType);
            setToolbarQueued(newReactionType, Boolean(file?.downloaded));
            setToolbarStatusMeta({
              exists: Boolean(file),
              downloaded: Boolean(file?.downloaded),
              blacklisted: Boolean(file?.blacklisted_at),
              reactionType: newReactionType,
              downloadProgress: fileMeta.downloadProgress,
              downloadedAt: fileMeta.downloadedAt,
            });
            emitOverlayReactionState(false, newReactionType, checkKey || null);

            if (payload.download_via === 'yt-dlp') {
              options.showToast(`Reacted (${reaction.label}). Resolving video in Atlas…`);
              return;
            }

            options.showToast(`Reacted (${reaction.label}). Queued download in Atlas.`);
          }
        );
      };

      deps.fetchAtlasStatus(options.sendMessageSafe, checkKey, payload.referrer_url || null, (status) => {
        if (reactionType !== 'dislike' && status?.downloaded) {
          options
            .chooseDialog({
              title: 'Already downloaded',
              message: 'Re-download before updating the reaction?',
              confirmLabel: 'Re-download',
              cancelLabel: 'Keep existing file',
              alternateLabel: 'Cancel',
            })
            .then((choice) => {
              if (choice === 'alternate') {
                options.showToast('Cancelled.');
                return;
              }
              submitReaction(choice === 'confirm');
            });
          return;
        }
        submitReaction(false);
      });
    });

    toolbar.appendChild(button);
  }
  toolbar.appendChild(statusMeta);
  toolbar.appendChild(progressMeta);

  options.root.appendChild(toolbar);

  const isOwnUiEvent = (event: Event) => {
    const composedPath =
      typeof (event as Event & { composedPath?: () => unknown[] }).composedPath === 'function'
        ? (event as Event & { composedPath: () => unknown[] }).composedPath()
        : [];
    return composedPath.some((p) => p instanceof HTMLElement && p.id === deps.rootId);
  };

  const updatePosition = () => {
    if (!activeMedia) return;

    const rect = activeMedia.getBoundingClientRect();
    if (!Number.isFinite(rect.left) || rect.width <= 0 || rect.height <= 0) {
      clearPendingOverlayState();
      hide(true);
      return;
    }

    const top = clamp(rect.top + 8, 8, window.innerHeight - 8);
    const left = clamp(rect.right - 8, 8, window.innerWidth - 8);
    toolbar.style.top = `${top}px`;
    toolbar.style.left = `${left}px`;
  };

  const findMediaAtPoint = (x: number, y: number): Element | null => resolveMediaAtPoint(x, y, deps.rootId);

  const showFor = (media: Element) => {
    if (options.isSheetOpen()) {
      hide();
      return;
    }

    handleLocationChange();

    // Validate this media has a usable URL (or is a supported video fallback) before showing.
    const previewPayload = buildOverlayReactionPayload(media, 'like', deps);
    if (!previewPayload) {
      hide();
      return;
    }

    const nextKey = previewPayload.url || null;
    const switchedContext =
      Boolean(activeMedia && activeMedia !== media) || Boolean(activeKey && nextKey && activeKey !== nextKey);
    if (switchedContext) {
      clearPendingOverlayState();
    }

    activeMedia = media;
    activeLookupKeys = buildLookupKeys(nextKey, previewPayload.referrer_url || window.location.href);
    activeKey = activeLookupKeys[0] || null;
    setToolbarResolution(previewPayload.width, previewPayload.height);
    const activeAssetKey = resolveWixAssetKey(previewPayload.url || '');
    const postContext = resolveDeviantArtPostContext(window.location.href, media);
    if (postContext && activeAssetKey && postContext.entryByAssetKey.has(activeAssetKey)) {
      activePostContext = postContext;
    } else {
      activePostContext = null;
    }
    syncPostIndicatorState();
    toolbar.classList.add('open');
    updatePosition();

    setToolbarActive(null);
    setToolbarQueued(null, null);
    setToolbarStatusMeta(null);
    if (activeLookupKeys.length > 0) {
      const cached = getCachedForActiveKeys();
      if (cached) {
        setToolbarActive(cached.reactionType);
        setToolbarQueued(cached.reactionType, cached.downloaded);
        setToolbarStatusMeta(cached);
      } else {
        const requestUrl = nextKey || '';
        const requestReferrer = previewPayload.referrer_url || window.location.href;
        const requestKeys = [...activeLookupKeys];
        deps.fetchAtlasStatus(options.sendMessageSafe, requestUrl, requestReferrer, (status) => {
          if (!status) return;
          if (requestKeys.length !== activeLookupKeys.length) return;
          if (requestKeys.some((key, index) => key !== activeLookupKeys[index])) return;
          setToolbarActive(status.reactionType);
          setToolbarQueued(status.reactionType, status.downloaded);
          setToolbarStatusMeta(status);
        });
      }
    }
  };

  const toToolbarReactionType = (reactionType: string | null) => {
    if (!reactionType) return null;
    return reactionType === 'blacklist' ? 'blacklist' : reactionType;
  };

  const refreshMediaContext = () => {
    if (options.isSheetOpen()) {
      return;
    }

    if (pointerX >= 0 && pointerY >= 0) {
      const resolved = findMediaAtPoint(pointerX, pointerY);
      if (resolved && !resolved.closest?.(`#${deps.rootId}`)) {
        cancelHide();
        showFor(resolved);
        return;
      }
    }

    const promoted = choosePromotedMediaCandidate(activeMedia, deps.rootId);
    if (!promoted) {
      return;
    }

    cancelHide();
    showFor(promoted);
  };
  const scheduleMediaContextRefresh = () => {
    if (refreshMediaContextFrame !== null) {
      return;
    }

    refreshMediaContextFrame = window.requestAnimationFrame(() => {
      refreshMediaContextFrame = null;
      refreshMediaContext();
    });
  };

  document.addEventListener(
    'pointerover',
    (event) => {
      if (options.isSheetOpen()) return;
      if (!(event.target instanceof Element)) return;
      if (isOwnUiEvent(event)) return;

      const media = findMediaAtPoint(event.clientX, event.clientY);
      if (!media) return;

      cancelHide();
      showFor(media);
    },
    true
  );
  window.addEventListener(
    'atlas-shortcut-reaction-state',
    (event: Event) => {
      const custom = event as CustomEvent<{
        media?: Element;
        pending?: boolean;
        reactionType?: string | null;
      }>;
      const media = custom.detail?.media;
      if (!(media instanceof Element)) {
        return;
      }

      cancelHide();
      showFor(media);

      const pending = Boolean(custom.detail?.pending);
      const reactionType = toToolbarReactionType(custom.detail?.reactionType ?? null);

      if (pending) {
        setToolbarBusy(true, reactionType);
        return;
      }

      setToolbarBusy(false, null);
      if (reactionType) {
        const nextType = reactionType === 'blacklist' ? 'dislike' : reactionType;
        setToolbarActive(nextType);
        const cached = getCachedForActiveKeys();
        setToolbarQueued(nextType, cached?.downloaded ?? null);
        setToolbarStatusMeta(cached);
      }
    },
    true
  );
  window.addEventListener(
    'atlas-status-cache-updated',
    () => {
      if (activeLookupKeys.length === 0) {
        return;
      }

      const cached = getCachedForActiveKeys();
      if (!cached) {
        return;
      }

      setToolbarActive(cached.reactionType);
      setToolbarQueued(cached.reactionType, cached.downloaded);
      setToolbarStatusMeta(cached);
    },
    true
  );
  document.addEventListener(
    'pointermove',
    (event) => {
      handleLocationChange();
      pointerX = event.clientX;
      pointerY = event.clientY;
    },
    true
  );

  document.addEventListener(
    'click',
    (event) => {
      if (!(event instanceof MouseEvent)) return;
      if (options.isSheetOpen()) return;
      if (isOwnUiEvent(event)) return;

      scheduleMediaContextRefresh();
    },
    true
  );

  document.addEventListener(
    'pointerout',
    (event) => {
      if (!activeMedia) return;
      if (options.isSheetOpen()) return;
      if (!(event.target instanceof Element)) return;
      if (isOwnUiEvent(event)) return;

      const leavingMedia = event.target.closest?.('img, video') ?? null;
      if (!leavingMedia) return;

      scheduleHide();
    },
    true
  );

  toolbar.addEventListener('pointerenter', cancelHide, true);
  toolbar.addEventListener('pointerleave', scheduleHide, true);

  window.addEventListener('scroll', updatePosition, true);
  window.addEventListener('resize', updatePosition);
  window.addEventListener(LOCATION_CHANGE_EVENT, handleLocationChange, true);
  window.addEventListener('blur', hide);
  window.addEventListener('focus', scheduleMediaContextRefresh, true);
  const observer = new MutationObserver(() => {
    handleLocationChange();
    scheduleMediaContextRefresh();
  });
  observer.observe(document.documentElement, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ['src', 'srcset', 'style', 'class'],
  });
}
