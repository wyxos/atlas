import { buildItemFromElement, collectLookupKeysForNode } from '../items';
import { createApp, h, reactive } from 'vue';
import {
  resolveBestDeviantArtPostDownloadUrl,
  resolveDeviantArtPostContext,
  resolveWixAssetKey,
  type DeviantArtPostContext,
} from '../deviantartPost';
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
  sendMessageSafeAsync,
  type AtlasBatchResponse,
  type AtlasStatus,
  type InteractionDependencies,
  type OverlayOptions,
} from './shared';

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

function buildOverlayReactionPayload(
  media: Element,
  reactionType: string,
  deps: InteractionDependencies
) {
  const item = buildItemFromElement(media, deps.minWidth);
  if (item) {
    const sourceLookupUrl = item.referrer_url || window.location.href || item.url;
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
      source: deps.sourceFromMediaUrl(sourceLookupUrl),
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

  const toolbarMount = document.createElement('div');
  options.root.appendChild(toolbarMount);

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
    postVisible: false,
    postDisabled: true,
    postPending: false,
    postCount: 0,
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
  const syncPostIndicatorState = () => {
    const count = activePostContext?.entries.length ?? 0;
    const visible = count > 1;
    overlayState.postVisible = visible;
    overlayState.postDisabled = !visible || postDownloadBusy || toolbarBusy;
    overlayState.postPending = postDownloadBusy;
    overlayState.postCount = visible ? count : 0;
  };
  const syncToolbarButtonState = () => {
    const locked = toolbarBusy || toolbarQueuedType !== null;
    for (const [type, button] of buttonsByType.entries()) {
      button.disabled = locked;
      button.pending = toolbarBusy && toolbarPendingType === type;
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
    toolbarHovered = false;
    overlayState.open = false;
    overlayState.left = null;
    overlayState.top = null;
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
        if (toolbarHovered) return;
        if (activeMedia instanceof Element && activeMedia.matches(':hover')) return;
        hide();
      });
    });
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
        source: deps.sourceFromMediaUrl(referrerHash || basePageUrl || url),
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
    const payload = buildOverlayReactionPayload(activeMedia, atlasReactionType, deps);
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
          postVisible: overlayState.postVisible,
          postDisabled: overlayState.postDisabled,
          postPending: overlayState.postPending,
          postCount: overlayState.postCount,
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
          onPostQueue: (reactionType: 'like' | 'love' | 'funny') => {
            void queueActivePostDownloads(reactionType);
          },
          onPostHint: () => {
            options.showToast('POST: Alt+Left queues as Like, Alt+Middle queues as Love.');
          },
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

    const top = clamp(rect.top + 8, 8, window.innerHeight - 8);
    const left = clamp(rect.right - 8, 8, window.innerWidth - 8);
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
    const activeAssetKey = resolveWixAssetKey(previewPayload.url || '');
    const postContext = resolveDeviantArtPostContext(window.location.href, media);
    if (postContext && activeAssetKey && postContext.entryByAssetKey.has(activeAssetKey)) {
      activePostContext = postContext;
    } else {
      activePostContext = null;
    }
    syncPostIndicatorState();
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
