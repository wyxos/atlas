import { collectLookupKeysForNode } from '../items';
import { buildLookupKeys } from '../lookupKeys';
import { createApp, h, reactive } from 'vue';
import { BLACKLIST_ACTION, REACTIONS } from '../reactions';
import OverlayToolbar from '../ui-vue/OverlayToolbar.vue';
import {
  LOCATION_CHANGE_EVENT,
  choosePromotedMediaCandidate,
  clamp,
  formatOverlayDownloadMeta,
  parseFileStatusMeta,
  resolveMediaAtPoint,
  resolveOverlayProgressPercent,
  type AtlasStatus,
  type InteractionDependencies,
  type OverlayOptions,
} from './shared';
import { buildReactionPayloadFromMedia } from './reactionPayload';

type AtlasWindow = Window & {
  __atlasLocationObserverInstalled?: boolean;
};

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

export function installMediaReactionOverlay(options: OverlayOptions, deps: InteractionDependencies) {
  installLocationChangeObserver();

  const toolbarMount = document.createElement('div');
  const styledRoot = options.root.querySelector('.atlas-shadow-root');
  if (styledRoot instanceof HTMLElement) {
    styledRoot.appendChild(toolbarMount);
  } else {
    options.root.appendChild(toolbarMount);
  }

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
  let toolbarHovered = false;

  const overlayState = reactive({
    open: false,
    left: null as number | null,
    top: null as number | null,
    resolutionText: '',
    statusText: '',
    progressVisible: false,
    progressPercent: 0,
    progressState: null as 'queued' | 'active' | 'done' | null,
    buttons: [...REACTIONS, BLACKLIST_ACTION].map((reaction) => ({
      type: reaction.type,
      className: reaction.className,
      label: reaction.label,
      pathDs: reaction.pathDs,
      active: false,
      pending: false,
      queued: false,
      disabled: false,
    })),
  });

  const buttonsByType = new Map<string, (typeof overlayState.buttons)[number]>();
  for (const button of overlayState.buttons) {
    buttonsByType.set(button.type, button);
  }
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
    overlayState.resolutionText = text;
  };
  const setToolbarStatusMeta = (status: AtlasStatus | null) => {
    const text = formatOverlayDownloadMeta(status);
    const progress = resolveOverlayProgressPercent(status);
    overlayState.statusText = text || '';

    if (progress === null) {
      overlayState.progressVisible = false;
      overlayState.progressState = null;
      overlayState.progressPercent = 0;
      return;
    }

    overlayState.progressVisible = true;
    overlayState.progressState = progress <= 0 ? 'queued' : progress >= 100 ? 'done' : 'active';
    overlayState.progressPercent = Math.max(0, Math.min(100, progress));
  };
  const syncToolbarButtonState = () => {
    const locked = toolbarBusy || toolbarQueuedType !== null;
    for (const [type, button] of buttonsByType.entries()) {
      button.disabled = locked;
      button.pending = toolbarBusy && toolbarPendingType === type;
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
      btn.active = isActive;
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
      btn.queued = isQueued;
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
    toolbarHovered = false;
    overlayState.open = false;
    overlayState.left = null;
    overlayState.top = null;
    setToolbarResolution(null, null);
    setToolbarStatusMeta(null);
    setToolbarActive(null);
  };

  const scheduleHide = () => {
    cancelHide();
    hideFrameA = window.requestAnimationFrame(() => {
      hideFrameA = null;
      hideFrameB = window.requestAnimationFrame(() => {
        hideFrameB = null;
        if (toolbarHovered) return;
        if (activeMedia instanceof Element && activeMedia.matches(':hover')) return;
        hide();
      });
    });
  };

  const handleToolbarReaction = (reactionType: string) => {
    if (toolbarBusy) {
      return;
    }

    if (!activeMedia) {
      options.showToast('No media selected.');
      return;
    }

    const reaction = [...REACTIONS, BLACKLIST_ACTION].find((entry) => entry.type === reactionType);
    if (!reaction) {
      return;
    }

    const atlasReactionType = reaction.type === BLACKLIST_ACTION.type ? 'dislike' : reaction.type;
    const payload = buildReactionPayloadFromMedia(activeMedia, atlasReactionType, deps);
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

          const nextStatus = {
            exists: Boolean(file),
            downloaded: Boolean(file?.downloaded),
            blacklisted: Boolean(file?.blacklisted_at),
            reactionType: newReactionType,
            downloadProgress: fileMeta.downloadProgress,
            downloadedAt: fileMeta.downloadedAt,
            ts: Date.now(),
          };
          for (const lookupKey of buildLookupKeys(payload.url, payload.preview_url, payload.referrer_url)) {
            deps.atlasStatusCache.set(lookupKey, nextStatus);
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
      if (atlasReactionType !== 'dislike' && status?.downloaded) {
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
  };

  createApp({
    setup() {
      return () =>
        h(OverlayToolbar, {
          open: overlayState.open,
          left: overlayState.left,
          top: overlayState.top,
          resolutionText: overlayState.resolutionText,
          statusText: overlayState.statusText,
          progressVisible: overlayState.progressVisible,
          progressPercent: overlayState.progressPercent,
          progressState: overlayState.progressState,
          buttons: overlayState.buttons,
          onPointerEnter: () => {
            toolbarHovered = true;
            cancelHide();
          },
          onPointerLeave: () => {
            toolbarHovered = false;
            scheduleHide();
          },
          onReaction: handleToolbarReaction,
        });
    },
  }).mount(toolbarMount);

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

    const left = clamp(rect.left + (rect.width / 2), 8, window.innerWidth - 8);
    const top = clamp(rect.bottom - 8, 8, window.innerHeight - 8);
    overlayState.top = top;
    overlayState.left = left;
  };

  const findMediaAtPoint = (x: number, y: number): Element | null => resolveMediaAtPoint(x, y, deps.rootId);

  const showFor = (media: Element) => {
    if (options.isSheetOpen()) {
      hide();
      return;
    }

    handleLocationChange();

    // Validate this media has a usable URL (or is a supported video fallback) before showing.
    const previewPayload = buildReactionPayloadFromMedia(media, 'like', deps);
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

    const mediaLookupKeys = collectLookupKeysForNode(media, {
      includeAnchor: true,
      includePageFallback: true,
    });
    const primaryLookupKey = nextKey || mediaLookupKeys[0] || null;
    const secondaryLookupKey =
      mediaLookupKeys.find((lookupKey) => lookupKey !== primaryLookupKey) || previewPayload.referrer_url || null;

    activeMedia = media;
    activeLookupKeys = buildLookupKeys(primaryLookupKey, secondaryLookupKey);
    activeKey = activeLookupKeys[0] || null;
    setToolbarResolution(previewPayload.width, previewPayload.height);
    overlayState.open = true;
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
        const requestUrl = primaryLookupKey || '';
        const requestReferrer = secondaryLookupKey || window.location.href;
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
      const target = event.target;
      const directMedia = target.closest?.('img, video');
      if (!directMedia) {
        const anchor = target.closest?.('a[href]');
        if (!anchor || !anchor.querySelector('img, video')) {
          return;
        }
      }

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

  window.addEventListener('scroll', updatePosition, true);
  window.addEventListener('resize', updatePosition);
  window.addEventListener(LOCATION_CHANGE_EVENT, handleLocationChange, true);
  window.addEventListener('blur', hide);
  window.addEventListener('focus', scheduleMediaContextRefresh, true);
  const mutationTouchesOverlayTargets = (mutations: MutationRecord[]): boolean => {
    const nodeTouchesOverlayTargets = (node: Node): boolean => {
      if (!(node instanceof Element)) {
        return false;
      }

      if (node.matches('img, video, source')) {
        return true;
      }

      return node.querySelector('img, video, source') !== null;
    };

    for (const mutation of mutations) {
      if (mutation.type === 'attributes') {
        const target = mutation.target;
        if (!(target instanceof Element)) {
          continue;
        }

        if (target.matches('img, video, source') || target.closest('img, video')) {
          return true;
        }

        continue;
      }

      if (mutation.type === 'childList') {
        for (const node of mutation.addedNodes) {
          if (nodeTouchesOverlayTargets(node)) {
            return true;
          }
        }
        for (const node of mutation.removedNodes) {
          if (nodeTouchesOverlayTargets(node)) {
            return true;
          }
        }
      }
    }

    return false;
  };

  const observer = new MutationObserver((mutations) => {
    if (!mutationTouchesOverlayTargets(mutations)) {
      return;
    }

    handleLocationChange();
    scheduleMediaContextRefresh();
  });
  observer.observe(document.documentElement, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ['src', 'srcset'],
  });
}
