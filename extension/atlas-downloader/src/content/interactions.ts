import { buildItemFromElement } from './items';
import { BLACKLIST_ACTION, REACTIONS, createSvgIcon } from './reactions';
import type { DialogChooser } from './ui';

const LOCATION_CHANGE_EVENT = 'atlas-location-change';

type AtlasStatus = {
  exists: boolean;
  downloaded: boolean;
  blacklisted: boolean;
  reactionType: string | null;
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

export function resolveMediaAtPoint(x: number, y: number, rootId: string): Element | null {
  const rawStack = document.elementsFromPoint?.(x, y) ?? [];
  const stack = rawStack.filter((node): node is Element => node instanceof Element);
  if (stack.length === 0) {
    const fallback = document.elementFromPoint?.(x, y);
    if (fallback instanceof Element) {
      stack.push(fallback);
    }
  }

  for (const node of stack) {
    if (isOwnUiElement(node, rootId)) {
      continue;
    }

    if (node.matches('img, video')) {
      return node;
    }

    const closest = node.closest?.('img, video');
    if (closest instanceof Element && !isOwnUiElement(closest, rootId)) {
      return closest;
    }
  }

  let best: { media: Element; area: number } | null = null;
  for (const node of stack) {
    if (isOwnUiElement(node, rootId)) {
      continue;
    }

    const nested = pickLargestMediaDescendantAtPoint(node, x, y);
    if (nested && (!best || nested.area > best.area)) {
      best = nested;
    }
  }

  return best?.media ?? null;
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
    const direct = target?.closest?.('img, video') ?? null;
    if (direct instanceof Element) {
      return direct;
    }

    return resolveMediaAtPoint(event.clientX, event.clientY, deps.rootId);
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
                      deps.atlasStatusCache.set(payload.url, {
                        exists: Boolean(file),
                        downloaded: Boolean(file?.downloaded),
                        blacklisted: Boolean(file?.blacklisted_at),
                        reactionType: newReactionType,
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
                deps.atlasStatusCache.set(payload.url, {
                  exists: Boolean(file),
                  downloaded: Boolean(file?.downloaded),
                  blacklisted: Boolean(file?.blacklisted_at),
                  reactionType: newReactionType,
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
                deps.atlasStatusCache.set(payload.url, {
                  exists: Boolean(file),
                  downloaded: Boolean(file?.downloaded),
                  blacklisted: Boolean(file?.blacklisted_at),
                  reactionType: newReactionType,
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
          deps.atlasStatusCache.set(payload.url, {
            exists: Boolean(file),
            downloaded: Boolean(file?.downloaded),
            blacklisted: Boolean(file?.blacklisted_at),
            reactionType: newReactionType,
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

  let activeMedia: Element | null = null;
  let activeKey: string | null = null;
  let hideTimer: number | null = null;
  let toolbarBusy = false;
  let toolbarQueuedType: string | null = null;
  let toolbarPendingType: string | null = null;
  let pointerX = -1;
  let pointerY = -1;
  let hoverDetectTimer: number | null = null;
  let activeLocationHref = window.location.href;

  const buttonsByType = new Map<string, HTMLButtonElement>();
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
  const syncToolbarButtonState = () => {
    const locked = toolbarBusy || toolbarQueuedType !== null;
    for (const [type, button] of buttonsByType.entries()) {
      button.disabled = locked;
      button.classList.toggle('pending', toolbarBusy && toolbarPendingType === type);
    }
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
    clearPendingOverlayState();
    hide(true);
  };

  const cancelHide = () => {
    if (hideTimer) {
      window.clearTimeout(hideTimer);
      hideTimer = null;
    }
  };

  const hide = (forceOrEvent?: boolean | Event) => {
    const force = forceOrEvent === true;
    if (toolbarBusy && !force) {
      return;
    }
    activeMedia = null;
    activeKey = null;
    toolbar.classList.remove('open');
    toolbar.style.left = '';
    toolbar.style.top = '';
    setToolbarResolution(null, null);
    setToolbarActive(null);
  };

  const scheduleHide = () => {
    cancelHide();
    hideTimer = window.setTimeout(() => {
      if (toolbar.matches(':hover')) return;
      hide();
    }, 140);
  };

  const swallow = (event: Event) => {
    event.preventDefault();
    event.stopPropagation();
    // @ts-expect-error stopImmediatePropagation exists on MouseEvent/PointerEvent.
    event.stopImmediatePropagation?.();
  };

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

            if (checkKey) {
              deps.atlasStatusCache.set(checkKey, {
                exists: Boolean(file),
                downloaded: Boolean(file?.downloaded),
                blacklisted: Boolean(file?.blacklisted_at),
                reactionType: newReactionType,
                ts: Date.now(),
              });
            }

            setToolbarActive(newReactionType);
            setToolbarQueued(newReactionType, Boolean(file?.downloaded));
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
    activeKey = nextKey;
    setToolbarResolution(previewPayload.width, previewPayload.height);
    toolbar.classList.add('open');
    updatePosition();

    setToolbarActive(null);
    setToolbarQueued(null, null);
    if (activeKey) {
      const cached = deps.getCachedAtlasStatus(activeKey);
      if (cached) {
        setToolbarActive(cached.reactionType);
        setToolbarQueued(cached.reactionType, cached.downloaded);
      } else {
        const keyAtRequest = activeKey;
        deps.fetchAtlasStatus(options.sendMessageSafe, keyAtRequest, window.location.href, (status) => {
          if (!status) return;
          if (activeKey !== keyAtRequest) return;
          setToolbarActive(status.reactionType);
          setToolbarQueued(status.reactionType, status.downloaded);
        });
      }
    }
  };

  const toToolbarReactionType = (reactionType: string | null) => {
    if (!reactionType) return null;
    return reactionType === 'blacklist' ? 'blacklist' : reactionType;
  };

  const detectMediaUnderPointer = () => {
    if (pointerX < 0 || pointerY < 0 || options.isSheetOpen()) {
      return;
    }
    const resolved = findMediaAtPoint(pointerX, pointerY);
    if (!resolved) {
      return;
    }
    if (resolved.closest?.(`#${deps.rootId}`)) {
      return;
    }
    cancelHide();
    showFor(resolved);
  };

  const scheduleDetectMediaUnderPointer = (delayMs = 80) => {
    if (hoverDetectTimer !== null) {
      window.clearTimeout(hoverDetectTimer);
    }
    hoverDetectTimer = window.setTimeout(() => {
      hoverDetectTimer = null;
      detectMediaUnderPointer();
    }, delayMs);
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
        const cached = activeKey ? deps.getCachedAtlasStatus(activeKey) : null;
        setToolbarQueued(nextType, cached?.downloaded ?? null);
      }
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

      scheduleDetectMediaUnderPointer(40);
      window.setTimeout(() => scheduleDetectMediaUnderPointer(220), 220);
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
  window.addEventListener('focus', () => scheduleDetectMediaUnderPointer(40), true);
  const observer = new MutationObserver(() => {
    handleLocationChange();
    scheduleDetectMediaUnderPointer(60);
  });
  observer.observe(document.documentElement, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ['src', 'srcset', 'style', 'class'],
  });
}
