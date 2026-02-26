import './content.css';
import { createApp, h, reactive } from 'vue';
import { registrableDomainFromUrl } from '../shared/domain';
import { DEFAULT_MEDIA_NOISE_FILTERS_TEXT } from '../shared/settingsDefaults';
import {
  buildDirectPageCandidate,
  buildItemFromElement,
  collectLookupKeysForNode,
  configureMediaNoiseFilters,
} from './items';
import { installHotkeys, installMediaReactionOverlay, type AtlasStatusCacheEntry } from './interactions';
import { buildLookupKeys } from './lookupKeys';
import { createSendMessageSafe } from './messaging';
import { stripHash } from './network';
import { isOpenTabHighlightEligibleUrl, normalizeOpenTabUrl } from './openTabUrl';
import {
  buildStatusMapFromCache,
  clearNodeMarkerAttributes,
  findStatusForLookupKeys,
  mergeSheetItemStatuses,
  syncPageVisitedBadge,
  syncMarkerRails,
  syncReactionIconBadges,
  syncOpenTabIconBadges,
} from './pageMarkers';
import { applyReactionStatusUpdateFromPayload } from './statusUpdates';
import { normalizeDownloadedAt, normalizeProgress } from './statusMeta';
import { shouldIgnoreMutationBatch } from './mutationGuard';
import { BLACKLIST_ACTION, REACTIONS } from './reactions';
import {
  createDialogChooser,
  createToastFn,
  ensurePageMarkerStyles,
  showDuplicateTabBlockedModal,
} from './ui';
import { mountHotkeysOnly as mountHotkeysOnlyModule } from './main/mountHotkeysOnly';
import { collectCandidates as collectCandidatesModule } from './main/collectCandidates';
import { resolveSheetReactionPrompt } from './main/reactionPrompt';
import SheetModal from './ui-vue/SheetModal.vue';

type ContentSettings = {
  atlasMediaNoiseFilters?: string;
  atlasMinMediaWidth?: unknown;
};

type ChromeStorageSync = {
  get: (keys: string[], callback: (data: ContentSettings) => void) => void;
};

type ChromeStorageChange = {
  oldValue?: unknown;
  newValue?: unknown;
};

type ChromeRuntime = {
  getURL: (path: string) => string;
  sendMessage: (message: unknown, callback?: (response: unknown) => void) => void;
  onMessage: {
    addListener: (callback: (message: unknown) => void) => void;
  };
  getManifest: () => { version?: string };
};

type ChromeApi = {
  storage: {
    sync: ChromeStorageSync;
    onChanged?: {
      addListener: (callback: (changes: Record<string, ChromeStorageChange>, areaName: string) => void) => void;
    };
  };
  runtime: ChromeRuntime;
};

declare const chrome: ChromeApi;
type AtlasTestWindow = Window & {
  __ATLAS_TEST_SHADOW_MODE?: unknown;
};

function resolveNoiseFiltersSetting(value: unknown): string {
  return typeof value === 'string' ? value : DEFAULT_MEDIA_NOISE_FILTERS_TEXT;
}

export function runContentScript() {
  const DEFAULT_MIN_MEDIA_WIDTH = 0;
  const CONTENT_SETTINGS_KEYS = ['atlasMediaNoiseFilters', 'atlasMinMediaWidth'];
  // Keep extension metadata short; some Atlas deployments validate at 500 chars.
  const MAX_METADATA_LEN = 500;
  const ROOT_ID = 'atlas-downloader-root';
  const IS_TOP_WINDOW = (() => {
    try {
      return window.top === window.self;
    } catch {
      // Cross-origin frames can throw; treat as NOT top so we don't inject the sheet UI into iframes.
      return false;
    }
  })();
  const EXT_VERSION = (() => {
    try {
      return chrome.runtime.getManifest?.().version ?? '';
    } catch {
      return '';
    }
  })();
  const SHADOW_MODE = (() => {
    const override = (window as AtlasTestWindow).__ATLAS_TEST_SHADOW_MODE;
    if (override === 'open' || override === 'closed') {
      return override;
    }

    return document.documentElement.getAttribute('data-atlas-shadow-mode') === 'open'
      ? 'open'
      : 'closed';
  })();

  let openSheet: (() => void) | null = null;
  let handleRealtimeDownloadEvent: ((payload: unknown) => void) | null = null;
  let syncOpenTabMarkers: (() => void) | null = null;
  const STATUS_CACHE_UPDATED_EVENT = 'atlas-status-cache-updated';
  const OPEN_TABS_UPDATED_EVENT = 'atlas-open-tabs-updated';
  const REACTION_UPDATED_EVENT = 'atlas-reaction-updated';

  const ATLAS_STATUS_TTL_MS = 30_000;
  const atlasStatusCache = new Map<string, AtlasStatusCacheEntry>();
  const openTabUrlSet = new Set<string>();
  let minMediaWidth = DEFAULT_MIN_MEDIA_WIDTH;

  function limitString(value, max) {
    const v = typeof value === 'string' ? value : '';
    if (v.length <= max) return v;
    return v.slice(0, max);
  }

  function sourceFromMediaUrl(url) {
    return registrableDomainFromUrl(url) || 'Extension';
  }

  function normalizeMinMediaWidth(value: unknown): number {
    const parsed = Number(value);
    if (!Number.isFinite(parsed)) {
      return DEFAULT_MIN_MEDIA_WIDTH;
    }

    return Math.max(0, Math.floor(parsed));
  }

  function applyOpenTabUrls(urls: unknown): boolean {
    const next = new Set<string>();
    const entries = Array.isArray(urls) ? urls : [];
    for (const entry of entries) {
      const normalized = normalizeOpenTabUrl(entry);
      if (!normalized || !isOpenTabHighlightEligibleUrl(normalized)) {
        continue;
      }

      next.add(normalized);
      next.add(stripHash(normalized));
    }

    const changed =
      next.size !== openTabUrlSet.size
      || [...next].some((url) => !openTabUrlSet.has(url));
    if (!changed) {
      return false;
    }

    openTabUrlSet.clear();
    for (const url of next) {
      openTabUrlSet.add(url);
    }

    return true;
  }

  function getCachedAtlasStatus(url: string) {
    const rawKey = (url || '').trim();
    const fallbackKey = stripHash(rawKey);
    const cached = atlasStatusCache.get(rawKey) ?? atlasStatusCache.get(fallbackKey);
    if (!cached) return null;
    if (Date.now() - cached.ts > ATLAS_STATUS_TTL_MS) {
      atlasStatusCache.delete(rawKey);
      atlasStatusCache.delete(fallbackKey);
      return null;
    }
    return cached;
  }

  function fetchAtlasStatus(
    sendMessageSafe: (message: unknown, callback: (response: unknown) => void) => void,
    url: string,
    referrerUrl: string | null,
    callback: (
      status: {
        exists: boolean;
        downloaded: boolean;
        blacklisted: boolean;
        reactionType: string | null;
        downloadProgress?: number | null;
        downloadedAt?: string | null;
      } | null
    ) => void
  ) {
    const lookupCandidates = buildLookupKeys(url, referrerUrl || '');
    if (lookupCandidates.length === 0) {
      callback(null);
      return;
    }

    for (const lookup of lookupCandidates) {
      const cached = getCachedAtlasStatus(lookup);
      if (cached) {
        callback(cached);
        return;
      }
    }

    sendMessageSafe({ type: 'atlas-check-batch', urls: lookupCandidates }, (response) => {
      if (!response || !response.ok) {
        callback(null);
        return;
      }

      const results = Array.isArray(response.data?.results) ? response.data.results : [];
      const byUrl = new Map<string, unknown>();
      for (const result of results) {
        const resultUrl = typeof result?.url === 'string' ? String(result.url) : '';
        if (!resultUrl) {
          continue;
        }
        for (const key of buildLookupKeys(resultUrl)) {
          if (!byUrl.has(key)) {
            byUrl.set(key, result);
          }
        }
      }

      const match = lookupCandidates
        .map((lookup) => byUrl.get(lookup))
        .find((value) => Boolean(value)) as
        | {
          exists?: unknown;
          downloaded?: unknown;
          blacklisted?: unknown;
          reaction?: { type?: unknown } | null;
          download_progress?: unknown;
          downloaded_at?: unknown;
        }
        | null;
      if (!match) {
        callback(null);
        return;
      }

      const status = {
        exists: Boolean(match.exists),
        downloaded: Boolean(match.downloaded),
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

  // Allow background-triggered actions (toolbar click / command shortcut) to toggle the sheet.
  chrome.runtime.onMessage.addListener((message: unknown) => {
    if (!message || typeof message !== 'object') {
      return;
    }

    const msg = message as { type?: unknown; payload?: unknown; url?: unknown; urls?: unknown };
    if (msg.type === 'atlas-download-event') {
      handleRealtimeDownloadEvent?.(msg.payload);
      return;
    }

    if (msg.type === REACTION_UPDATED_EVENT) {
      if (applyReactionStatusUpdateFromPayload(msg.payload, atlasStatusCache)) {
        syncOpenTabMarkers?.();
        window.dispatchEvent(new Event(STATUS_CACHE_UPDATED_EVENT));
      }
      return;
    }

    if (msg.type === OPEN_TABS_UPDATED_EVENT) {
      if (applyOpenTabUrls(msg.urls)) {
        syncOpenTabMarkers?.();
      }
      return;
    }

    if (msg.type === 'atlas-duplicate-tab-blocked') {
      if (!IS_TOP_WINDOW) {
        return;
      }

      const duplicateUrl = typeof msg.url === 'string' ? msg.url : '';
      showDuplicateTabBlockedModal(duplicateUrl);
      return;
    }

    if (msg.type !== 'atlas-open-sheet') {
      return;
    }

    if (!IS_TOP_WINDOW) {
      return;
    }

    try {
      chrome.storage.sync.get(CONTENT_SETTINGS_KEYS, (data) => {
        configureMediaNoiseFilters(resolveNoiseFiltersSetting(data.atlasMediaNoiseFilters));
        minMediaWidth = normalizeMinMediaWidth(data.atlasMinMediaWidth);

        mountUi();
        openSheet?.();
      });
    } catch {
      // Happens if the extension was reloaded while this tab stayed open.
    }
  });

  chrome.storage.onChanged?.addListener((changes, areaName) => {
    if (areaName !== 'sync') {
      return;
    }

    if ('atlasMediaNoiseFilters' in changes) {
      configureMediaNoiseFilters(resolveNoiseFiltersSetting(changes.atlasMediaNoiseFilters?.newValue));
    }

    if ('atlasMinMediaWidth' in changes) {
      minMediaWidth = normalizeMinMediaWidth(changes.atlasMinMediaWidth?.newValue);
    }
  });

  chrome.storage.sync.get(CONTENT_SETTINGS_KEYS, (data) => {
    configureMediaNoiseFilters(resolveNoiseFiltersSetting(data.atlasMediaNoiseFilters));
    minMediaWidth = normalizeMinMediaWidth(data.atlasMinMediaWidth);

    if (IS_TOP_WINDOW) {
      mountUi();
    } else {
      mountHotkeysOnly();
    }
  });

  function mountHotkeysOnly() {
    mountHotkeysOnlyModule({
      rootId: ROOT_ID,
      maxMetadataLen: MAX_METADATA_LEN,
      getMinMediaWidth: () => minMediaWidth,
      getStyleUrl: (path) => chrome.runtime.getURL(path),
      sendMessage: (message, callback) => chrome.runtime.sendMessage(message, callback),
      limitString,
      sourceFromMediaUrl,
      fetchAtlasStatus,
      atlasStatusCache,
      getCachedAtlasStatus,
    });
  }

  function mountUi() {
    if (document.getElementById(ROOT_ID)) {
      return;
    }

    const host = document.createElement('div');
    host.id = ROOT_ID;

    const shadow = host.attachShadow({ mode: SHADOW_MODE });

    // Inject styles into shadow DOM
    const style = document.createElement('link');
    style.rel = 'stylesheet';
    style.href = chrome.runtime.getURL('dist/content.css');
    shadow.appendChild(style);

    const root = document.createElement('div');
    const appMount = document.createElement('div');
    root.appendChild(appMount);

    let showToast = createToastFn(root);
    let chooseDialog = createDialogChooser(root);
    const sendMessageSafe = createSendMessageSafe(
      (message, callback) => chrome.runtime.sendMessage(message, callback),
      (message, tone) => showToast(message, tone)
    );

    shadow.appendChild(root);
    (document.body || document.documentElement).appendChild(host);

    let items = [];
    let scanNonce = 0;
    let reactingItemUrl: string | null = null;
    let reactingItemType: string | null = null;
    let debugTargetUrl: string | null = null;
    let markerSyncTimer: number | null = null;
    const scheduleMarkerSync = (delayMs = 300) => {
      if (markerSyncTimer !== null) {
        window.clearTimeout(markerSyncTimer);
      }
      markerSyncTimer = window.setTimeout(() => {
        markerSyncTimer = null;
        syncAtlasStatusForPageMarkers();
      }, delayMs);
    };
    const queuedLookupUrls = new Set<string>();
    const lookupByTransferId = new Map<number, string>();
    // Enable hotkeys immediately after UI mounts; event delegation makes it work for dynamic content too.
    const hotkeysEnabled = true;
    let hotkeysHintShown = false;
    const sheetState = reactive({
      open: false,
      version: EXT_VERSION,
      metaText: '',
      items: [] as Array<Record<string, unknown>>,
      queueDisabled: true,
      refreshDisabled: true,
      checkAtlasDisabled: true,
      selectAllDisabled: true,
      selectNoneDisabled: true,
      debugTargetUrl: null as string | null,
      debugPayloads: {} as Record<string, unknown>,
    });

    const onCloseModal = () => closeModal();
    const onRefresh = () => refreshList();
    const onCheckAtlas = () => checkAtlasStatus(false);
    const onSelectAll = () => setAllSelected(true);
    const onSelectNone = () => setAllSelected(false);
    const onQueueSelected = () => queueSelected();
    const onToggleRow = (index: number) => {
      const item = items[index];
      if (!item) {
        return;
      }

      item.selected = !item.selected;
      renderList();
      setReady(summaryText());
    };
    const onUpdateSelected = (index: number, selected: boolean) => {
      const item = items[index];
      if (!item) {
        return;
      }

      item.selected = selected;
      renderList();
      setReady(summaryText());
    };
    const onReact = (index: number, type: string) => {
      const item = items[index];
      if (!item) {
        return;
      }

      reactToItem(item, type);
    };
    const onBlacklist = (index: number) => {
      const item = items[index];
      if (!item) {
        return;
      }

      reactToItem(item, 'dislike', { blacklist: true });
    };
    const onDeleteDownload = (index: number) => {
      const item = items[index];
      if (!item) {
        return;
      }

      deleteDownloadForItem(item);
    };
    const onToggleDebug = (index: number) => {
      const item = items[index];
      if (!item) {
        return;
      }

      debugTargetUrl = debugTargetUrl === item.url ? null : item.url;
      renderList();
      setReady(summaryText());
    };

    createApp({
      setup() {
        return () =>
          h(SheetModal, {
            open: sheetState.open,
            version: sheetState.version,
            metaText: sheetState.metaText,
            items: sheetState.items,
            queueDisabled: sheetState.queueDisabled,
            refreshDisabled: sheetState.refreshDisabled,
            checkAtlasDisabled: sheetState.checkAtlasDisabled,
            selectAllDisabled: sheetState.selectAllDisabled,
            selectNoneDisabled: sheetState.selectNoneDisabled,
            debugTargetUrl: sheetState.debugTargetUrl,
            debugPayloads: sheetState.debugPayloads,
            reactions: REACTIONS,
            blacklistAction: BLACKLIST_ACTION,
            onClose: onCloseModal,
            onRefresh,
            onCheckAtlas,
            onSelectAll,
            onSelectNone,
            onQueueSelected,
            onToggleRow,
            onUpdateSelected,
            onReact,
            onBlacklist,
            onDeleteDownload,
            onToggleDebug,
          });
      },
    }).mount(appMount);

    const styledRoot = root.querySelector('.atlas-shadow-root');
    if (styledRoot instanceof HTMLElement) {
      showToast = createToastFn(styledRoot);
      chooseDialog = createDialogChooser(styledRoot);
    }

    handleRealtimeDownloadEvent = (payload: unknown) => {
      if (!payload || typeof payload !== 'object') {
        return;
      }

      const data = payload as {
        event?: unknown;
        transferId?: unknown;
        status?: unknown;
        percent?: unknown;
        original?: unknown;
        referrer_url?: unknown;
        downloaded?: unknown;
        failed?: unknown;
        finished_at?: unknown;
        failed_at?: unknown;
      };

      const transferId = Number.isFinite(Number(data.transferId)) ? Number(data.transferId) : null;
      const eventName = typeof data.event === 'string' ? data.event : '';
      const status = typeof data.status === 'string' ? data.status : '';
      const progress = normalizeProgress(data.percent);
      const downloaded = Boolean(data.downloaded) || status === 'completed' || typeof data.finished_at === 'string';
      const failed = Boolean(data.failed) || status === 'failed' || status === 'canceled' || typeof data.failed_at === 'string';
      const downloadedAt = downloaded ? normalizeDownloadedAt(data.finished_at) : null;
      const isStartEvent =
        eventName === 'DownloadTransferCreated'
        || eventName === 'DownloadTransferQueued';
      const isEndEvent = downloaded || failed;

      const originalLookup = typeof data.original === 'string' ? data.original.trim() : '';
      const referrerLookup = typeof data.referrer_url === 'string' ? data.referrer_url.trim() : '';
      const lookupCandidates = new Set<string>(buildLookupKeys(originalLookup, referrerLookup));

      const mappedLookup = transferId ? (lookupByTransferId.get(transferId) ?? '') : '';
      for (const lookup of buildLookupKeys(mappedLookup)) {
        lookupCandidates.add(lookup);
      }

      if (lookupCandidates.size === 0) {
        return;
      }

      const primaryLookup = originalLookup || mappedLookup || referrerLookup || '';
      if (transferId && primaryLookup && !downloaded && !failed) {
        lookupByTransferId.set(transferId, primaryLookup);
      }

      let changed = false;
      let cacheUpdated = false;
      for (const lookup of lookupCandidates) {
        const cached = getCachedAtlasStatus(lookup);
        atlasStatusCache.set(lookup, {
          exists: true,
          downloaded,
          blacklisted: cached ? Boolean(cached.blacklisted) : false,
          reactionType: cached?.reactionType ?? null,
          downloadProgress: downloaded ? 100 : failed ? 0 : progress ?? cached?.downloadProgress ?? null,
          downloadedAt: downloadedAt ?? cached?.downloadedAt ?? null,
          ts: Date.now(),
        });
        cacheUpdated = true;
      }

      for (const item of items) {
        const itemLookups = itemLookupKeys(item);
        if (itemLookups.length === 0 || !itemLookups.some((lookup) => lookupCandidates.has(lookup))) {
          continue;
        }

        if (!itemLookups.some((lookup) => queuedLookupUrls.has(lookup)) && item.status !== 'Queued') {
          continue;
        }

        item.atlas = {
          exists: true,
          downloaded,
          blacklisted: item.atlas?.blacklisted ?? false,
          file_id: item.atlas?.file_id ?? null,
          reaction: item.atlas?.reaction ?? null,
        };

        if (downloaded) {
          item.status = '';
          item.statusClass = '';
          item.reactionQueued = null;
          for (const lookup of itemLookups) {
            queuedLookupUrls.delete(lookup);
          }
        } else if (failed) {
          item.status = 'Failed';
          item.statusClass = 'err';
          item.reactionQueued = null;
          for (const lookup of itemLookups) {
            queuedLookupUrls.delete(lookup);
          }
        } else {
          item.status = 'Queued';
          item.statusClass = 'queued';
        }

        changed = true;
      }

      if (transferId && (downloaded || failed)) {
        lookupByTransferId.delete(transferId);
      }

      if (cacheUpdated) {
        window.dispatchEvent(new Event(STATUS_CACHE_UPDATED_EVENT));
      }

      if (isStartEvent || isEndEvent) {
        scheduleMarkerSync(isEndEvent ? 120 : 220);
      }

      if (!changed) {
        return;
      }

      renderList();
      setReady(summaryText());
      applyPageMarkers(items);
    };

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && sheetState.open) {
        closeModal();
        return;
      }

      if (!sheetState.open) {
        return;
      }

      const hotkeyType =
        event.key === '1' ? 'love' : event.key === '2' ? 'like' : event.key === '3' ? 'funny' : null;
      if (!hotkeyType) {
        return;
      }

      const target = items.find((item) => item.selected) ?? items[0] ?? null;
      if (!target) {
        return;
      }

      event.preventDefault();
      reactToItem(target, hotkeyType, { closeOnSuccess: true });
    });

    function openModal() {
      sheetState.open = true;
      refreshList();
    }

    function toggleModal() {
      if (sheetState.open) {
        closeModal();
        return;
      }

      openModal();
    }

    function closeModal() {
      sheetState.open = false;
    }

    openSheet = toggleModal;

    installHotkeys({
      showToast,
      sendMessageSafe,
      isSheetOpen: () => sheetState.open,
      chooseDialog,
      setHintShown: (value) => {
        hotkeysHintShown = value;
      },
      getHintShown: () => hotkeysHintShown,
      enabled: hotkeysEnabled,
    }, {
      rootId: ROOT_ID,
      get minWidth() {
        return minMediaWidth;
      },
      maxMetadataLen: MAX_METADATA_LEN,
      limitString,
      sourceFromMediaUrl,
      fetchAtlasStatus,
      atlasStatusCache,
      getCachedAtlasStatus,
    });

    installMediaReactionOverlay({
      root,
      showToast,
      sendMessageSafe,
      isSheetOpen: () => sheetState.open,
      chooseDialog,
    }, {
      rootId: ROOT_ID,
      get minWidth() {
        return minMediaWidth;
      },
      maxMetadataLen: MAX_METADATA_LEN,
      limitString,
      sourceFromMediaUrl,
      fetchAtlasStatus,
      atlasStatusCache,
      getCachedAtlasStatus,
    });

    window.addEventListener(
      'atlas-shortcut-reaction-state',
      (event: Event) => {
        const custom = event as CustomEvent<{
          media?: Element;
          pending?: boolean;
          reactionType?: string | null;
          url?: string | null;
        }>;

        const pending = Boolean(custom.detail?.pending);
        const reactionType = custom.detail?.reactionType ? String(custom.detail.reactionType) : null;
        const explicitUrl = typeof custom.detail?.url === 'string' ? custom.detail.url : '';
        const resolvedUrl = explicitUrl || (custom.detail?.media ? buildItemFromElement(custom.detail.media, minMediaWidth)?.url || '' : '');
        const lookupKeys = buildLookupKeys(resolvedUrl);
        const lookupKeySet = new Set(lookupKeys);
        const lookup = lookupKeys[0] || '';

        if (pending) {
          reactingItemUrl = lookup || '__external-reaction__';
          reactingItemType = reactionType || reactingItemType || 'like';
          for (const item of items) {
            const itemKeys = itemLookupKeys(item);
            if (lookupKeySet.size > 0 && !itemKeys.some((key) => lookupKeySet.has(key))) {
              continue;
            }
            item.reactionPending = reactionType || item.reactionPending || 'like';
          }
          renderList();
          setReady(summaryText());
          return;
        }

        reactingItemUrl = null;
        reactingItemType = null;
        for (const item of items) {
          const itemKeys = itemLookupKeys(item);
          if (lookupKeySet.size > 0 && !itemKeys.some((key) => lookupKeySet.has(key))) {
            continue;
          }

          if (item.reactionPending) {
            item.reactionPending = null;
          }

          const cached =
            (lookup ? getCachedAtlasStatus(lookup) : null) ??
            itemKeys
              .map((key) => getCachedAtlasStatus(key))
              .find((value) => Boolean(value)) ??
            null;

          if (cached) {
            item.atlas = {
              exists: Boolean(cached.exists),
              downloaded: Boolean(cached.downloaded),
              blacklisted: Boolean(cached.blacklisted),
              file_id: item.atlas?.file_id ?? null,
              reaction: cached.reactionType ? { type: cached.reactionType } : item.atlas?.reaction ?? null,
            };
          } else if (reactionType) {
            item.atlas = {
              exists: item.atlas?.exists ?? true,
              downloaded: item.atlas?.downloaded ?? false,
              blacklisted: item.atlas?.blacklisted ?? false,
              file_id: item.atlas?.file_id ?? null,
              reaction: { type: reactionType },
            };
          }

          if (item.atlas?.exists && !item.atlas?.downloaded && item.atlas?.reaction?.type && item.atlas.reaction.type !== 'dislike') {
            item.reactionQueued = item.atlas.reaction.type;
          } else {
            item.reactionQueued = null;
          }
        }

        renderList();
        setReady(summaryText());
        applyPageMarkers(items);
      },
      true
    );

    let reactionBadgeSyncFrame: number | null = null;
    const syncReactionBadgesFromDom = () => {
      syncReactionIconBadges(
        Array.from(
          document.querySelectorAll(
            '[data-atlas-marked="1"][data-atlas-state="reacted"][data-atlas-reaction]'
          )
        )
      );
    };
    const syncOpenTabBadgesFromDom = () => {
      syncOpenTabIconBadges(
        Array.from(
          document.querySelectorAll(
            '[data-atlas-open-tab="1"]:not([data-atlas-state="reacted"])'
          )
        )
      );
    };
    const scheduleReactionBadgeSync = () => {
      if (reactionBadgeSyncFrame !== null) {
        return;
      }

      reactionBadgeSyncFrame = window.requestAnimationFrame(() => {
        reactionBadgeSyncFrame = null;
        syncReactionBadgesFromDom();
        syncOpenTabBadgesFromDom();
      });
    };

    const mutationObserver = new MutationObserver((mutations) => {
      if (shouldIgnoreMutationBatch(mutations, ROOT_ID)) {
        return;
      }

      applyPageMarkers(items);
      scheduleMarkerSync(450);
    });
    mutationObserver.observe(document.documentElement, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['src', 'srcset', 'href', 'style', 'class'],
    });

    window.addEventListener('pageshow', () => scheduleMarkerSync(80), true);
    window.addEventListener('scroll', scheduleReactionBadgeSync, true);
    window.addEventListener('resize', scheduleReactionBadgeSync, true);
    document.addEventListener(
      'visibilitychange',
      () => {
        if (document.visibilityState === 'visible') {
          scheduleMarkerSync(120);
          scheduleReactionBadgeSync();
        }
      },
      true
    );
    scheduleMarkerSync(120);
    sendMessageSafe({ type: 'atlas-open-tabs-request' }, (response) => {
      const payload = response as { ok?: unknown; urls?: unknown } | null;
      if (!payload || payload.ok !== true) {
        return;
      }

      if (applyOpenTabUrls(payload.urls)) {
        applyPageMarkers(items);
      }
    });

    function setLoading(text) {
      sheetState.metaText = text;
      sheetState.queueDisabled = true;
      sheetState.refreshDisabled = true;
      sheetState.checkAtlasDisabled = true;
      sheetState.selectAllDisabled = true;
      sheetState.selectNoneDisabled = true;
    }

    function setReady(text) {
      sheetState.metaText = text;
      const selectedCount = items.filter((item) => item.selected).length;
      const busy = items.some((item) => Boolean(item.reactionPending)) || reactingItemUrl !== null;
      sheetState.queueDisabled = selectedCount === 0 || busy;
      sheetState.refreshDisabled = busy;
      sheetState.checkAtlasDisabled = items.length === 0 || busy;
      sheetState.selectAllDisabled = items.length === 0 || busy;
      sheetState.selectNoneDisabled = items.length === 0 || busy;
    }

    function refreshList() {
      scanNonce += 1;
      const currentScan = scanNonce;
      items = [];
      debugTargetUrl = null;
      renderList();
      setLoading('Scanning this page…');

      collectCandidates((progress) => {
        if (scanNonce !== currentScan) {
          return;
        }
        sheetState.metaText = `Scanning… ${progress.scanned}/${progress.total}`;
      }).then((found) => {
        if (scanNonce !== currentScan) {
          return;
        }

        items = found.map((item) => ({
          ...item,
          selected: true,
          status: '',
          statusClass: '',
          atlas: null,
          reactionPending:
            reactingItemUrl
            && reactingItemUrl !== '__external-reaction__'
            && itemLookupKeys(item).includes(reactingItemUrl)
              ? reactingItemType || 'like'
              : null,
          reactionQueued: null,
        }));

        renderList();
        setReady(summaryText());
        checkAtlasStatus(true);
      });
    }

    function setAllSelected(selected) {
      for (const item of items) {
        item.selected = selected;
      }
      renderList();
      setReady(summaryText());
    }

    function renderList() {
      sheetState.items = items.map((item, index) => ({
        ...item,
        index,
      }));
      sheetState.debugTargetUrl = debugTargetUrl;
      if (debugTargetUrl) {
        const target = items.find((item) => item.url === debugTargetUrl);
        if (target) {
          sheetState.debugPayloads = {
            [target.url]: {
              react: buildReactionPayload(target, target.atlas?.reaction?.type || 'like'),
              download: buildDownloadPayload(target),
            },
          };
          return;
        }
      }
      sheetState.debugPayloads = {};
    }

    function buildReactionPayload(item, type) {
      return {
        type,
        url: item.url,
        referrer_url: item.referrer_url || window.location.href,
        page_title: limitString(document.title, MAX_METADATA_LEN),
        tag_name: item.tag_name,
        width: item.width,
        height: item.height,
        alt: limitString(item.alt || '', MAX_METADATA_LEN),
        preview_url: item.preview_url || '',
        source: sourceFromMediaUrl((item.referrer_url || '').trim() || item.url || window.location.href),
        ...(item.download_via ? { download_via: item.download_via } : {}),
      };
    }

    function buildDownloadPayload(item) {
      return {
        url: item.url,
        referrer_url: item.referrer_url || window.location.href,
        page_title: limitString(document.title, MAX_METADATA_LEN),
        tag_name: item.tag_name,
        width: item.width,
        height: item.height,
        alt: limitString(item.alt || '', MAX_METADATA_LEN),
        preview_url: item.preview_url || '',
        source: sourceFromMediaUrl((item.referrer_url || '').trim() || item.url || window.location.href),
        ...(item.download_via ? { download_via: item.download_via } : {}),
        // Align with Atlas core behavior: reactions dispatch downloads.
        reaction_type: 'like',
      };
    }

    function summaryText() {
      const selectedCount = items.filter((item) => item.selected).length;
      return `${items.length} found • ${selectedCount} selected`;
    }

    function itemLookupKeys(item) {
      const url = (item?.url || '').trim();
      const referrerUrl = (item?.referrer_url || '').trim();
      return buildLookupKeys(url, referrerUrl);
    }

    function itemLookupUrl(item) {
      return itemLookupKeys(item)[0] || '';
    }

    function checkAtlasStatus(silent) {
      const urls = [...new Set(items.flatMap((item) => itemLookupKeys(item)).filter(Boolean))];
      if (urls.length === 0) {
        return;
      }

      sendMessageSafe({ type: 'atlas-check-batch', urls }, (response) => {
        if (!response) {
          if (!silent) {
            showToast('Atlas extension did not respond.', 'danger');
          }
          return;
        }

        if (!response.ok) {
          if (!silent) {
            showToast(response.error || 'Atlas check failed.', 'danger');
          }
          return;
        }

        const results = Array.isArray(response.data?.results) ? response.data.results : [];
        const byUrl = new Map<string, unknown>();
        for (const result of results) {
          const resultUrl = typeof result?.url === 'string' ? String(result.url) : '';
          if (!resultUrl) {
            continue;
          }

          for (const key of buildLookupKeys(resultUrl)) {
            if (!byUrl.has(key)) {
              byUrl.set(key, result);
            }
          }
        }

        let existsCount = 0;
        let downloadedCount = 0;
        for (const item of items) {
          const lookups = itemLookupKeys(item);
          const match = lookups
            .map((lookup) => byUrl.get(lookup))
            .find((value) => Boolean(value)) as
            | {
              exists?: unknown;
              downloaded?: unknown;
              blacklisted?: unknown;
              file_id?: unknown;
              reaction?: { type?: unknown } | null;
              download_progress?: unknown;
              downloaded_at?: unknown;
            }
            | undefined;
          if (!match) {
            continue;
          }

          item.atlas = {
            exists: Boolean(match.exists),
            downloaded: Boolean(match.downloaded),
            blacklisted: Boolean(match.blacklisted),
            file_id: match.file_id ?? null,
            reaction: match.reaction ?? null,
          };
          const reactionType = item.atlas.reaction?.type ? String(item.atlas.reaction.type) : null;
          const isQueuedReaction = item.atlas.exists && !item.atlas.downloaded && Boolean(reactionType) && reactionType !== 'dislike';
          const hasTerminalAtlasState = item.atlas.downloaded || item.atlas.blacklisted || !isQueuedReaction;

          if (hasTerminalAtlasState) {
            item.status = '';
            item.statusClass = '';
          }

          if (item.atlas.exists && !item.atlas.downloaded && item.atlas.reaction?.type && item.atlas.reaction.type !== 'dislike') {
            item.reactionQueued = item.atlas.reaction.type;
            for (const lookup of lookups) {
              queuedLookupUrls.add(lookup);
            }
          } else {
            item.reactionQueued = null;
            for (const lookup of lookups) {
              queuedLookupUrls.delete(lookup);
            }
          }

          const status = {
            exists: Boolean(match.exists),
            downloaded: Boolean(match.downloaded),
            blacklisted: Boolean(match.blacklisted),
            reactionType: match.reaction?.type ? String(match.reaction.type) : null,
            downloadProgress: normalizeProgress(match.download_progress),
            downloadedAt: normalizeDownloadedAt(match.downloaded_at),
            ts: Date.now(),
          };
          for (const lookup of lookups) {
            atlasStatusCache.set(lookup, status);
          }

          if (match.exists) existsCount += 1;
          if (match.downloaded) downloadedCount += 1;
        }

        renderList();
        setReady(summaryText());
        applyPageMarkers(items);
        window.dispatchEvent(new Event(STATUS_CACHE_UPDATED_EVENT));

        if (!silent) {
          showToast(`Atlas check: ${downloadedCount}/${existsCount} downloaded.`);
        }
      });
    }

    function reactToItem(
      item,
      type,
      options: { blacklist?: boolean; closeOnSuccess?: boolean } = {}
    ) {
      const proceed = (extraPayload: Record<string, unknown> = {}) => {
        item.reactionPending = options.blacklist ? BLACKLIST_ACTION.type : type;
        item.status = 'Reacting…';
        item.statusClass = '';
        reactingItemUrl = itemLookupUrl(item) || item.url;
        reactingItemType = item.reactionPending;
        renderList();
        setReady(summaryText());

        const payload = {
          ...buildReactionPayload(item, type),
          ...extraPayload,
          ...(options.blacklist ? { blacklist: true } : {}),
        };

        sendMessageSafe({ type: 'atlas-react', payload }, (response) => {
          item.reactionPending = null;
          reactingItemUrl = null;
          reactingItemType = null;

          if (!response) {
            item.status = 'No response';
            item.statusClass = 'err';
            renderList();
            setReady(summaryText());
            showToast('Atlas extension did not respond.', 'danger');
            return;
          }

          if (!response.ok) {
            item.status = response.error || 'Failed';
            item.statusClass = 'err';
            renderList();
            setReady(summaryText());
            showToast(response.error || 'Reaction failed.', 'danger');
            return;
          }

          const data = response.data || null;
          const file = data?.file || null;

          item.atlas = {
            exists: Boolean(file),
            downloaded: Boolean(file?.downloaded),
            blacklisted: Boolean(file?.blacklisted_at),
            file_id: file?.id ?? null,
            reaction: data?.reaction ?? null,
          };

          const lookupKeys = itemLookupKeys(item);
          const status = {
            exists: item.atlas.exists,
            downloaded: item.atlas.downloaded,
            blacklisted: item.atlas.blacklisted,
            reactionType: item.atlas.reaction?.type ? String(item.atlas.reaction.type) : null,
            downloadProgress: normalizeProgress(file?.download_progress),
            downloadedAt: normalizeDownloadedAt(file?.downloaded_at),
            ts: Date.now(),
          };
          for (const key of lookupKeys) {
            atlasStatusCache.set(key, status);
          }

          if (item.atlas.exists && !item.atlas.downloaded && type !== 'dislike') {
            item.status = 'Queued';
            item.statusClass = 'queued';
            item.reactionQueued = options.blacklist ? BLACKLIST_ACTION.type : type;
            if (lookupKeys.length > 0) {
              for (const key of lookupKeys) {
                queuedLookupUrls.add(key);
              }
            }
          } else {
            item.status = '';
            item.statusClass = '';
            item.reactionQueued = null;
            if (lookupKeys.length > 0) {
              for (const key of lookupKeys) {
                queuedLookupUrls.delete(key);
              }
            }
          }
          renderList();
          setReady(summaryText());
          applyPageMarkers(items);
          window.dispatchEvent(new Event(STATUS_CACHE_UPDATED_EVENT));

          if (options.closeOnSuccess) {
            closeModal();
          }

        });
      };

      const prompt = resolveSheetReactionPrompt(
        type,
        Boolean(item.atlas?.downloaded),
        Boolean(options.blacklist)
      );
      if (prompt) {
        chooseDialog(prompt).then((choice) => {
          if (choice === 'alternate') {
            showToast('Cancelled.');
            return;
          }

          if (prompt.kind === 're-download') {
            proceed(choice === 'confirm' ? { force_download: true } : {});
            return;
          }

          proceed(choice === 'confirm' ? { clear_download: true } : {});
        });
        return;
      }

      proceed();
    }

    function deleteDownloadForItem(item) {
      chooseDialog({
        title: 'Delete downloaded file',
        message: 'This removes the stored file from disk and keeps the Atlas record.',
        confirmLabel: 'Delete download',
        cancelLabel: 'Cancel',
        danger: true,
      }).then((choice) => {
        if (choice !== 'confirm') {
          return;
        }

        item.reactionPending = 'delete-download';
        item.status = 'Deleting…';
        item.statusClass = '';
        reactingItemUrl = itemLookupUrl(item) || item.url;
        reactingItemType = item.reactionPending;
        renderList();
        setReady(summaryText());

        sendMessageSafe(
          {
            type: 'atlas-delete-download',
            payload: {
              url: item.url,
              tag_name: item.tag_name,
              download_via: item.download_via || null,
            },
          },
          (response) => {
            item.reactionPending = null;
            reactingItemUrl = null;
            reactingItemType = null;

            if (!response || !response.ok) {
              item.status = response?.error || 'Failed';
              item.statusClass = 'err';
              renderList();
              setReady(summaryText());
              showToast(response?.error || 'Delete failed.', 'danger');
              return;
            }

            const file = response.data?.file || null;
            item.atlas = {
              exists: Boolean(file),
              downloaded: Boolean(file?.downloaded),
              blacklisted: Boolean(file?.blacklisted_at),
              file_id: file?.id ?? null,
              reaction: null,
            };
            item.reactionQueued = null;
            const lookupKeys = itemLookupKeys(item);
            const status = {
              exists: item.atlas.exists,
              downloaded: item.atlas.downloaded,
              blacklisted: item.atlas.blacklisted,
              reactionType: null,
              downloadProgress: normalizeProgress(file?.download_progress),
              downloadedAt: normalizeDownloadedAt(file?.downloaded_at),
              ts: Date.now(),
            };
            for (const key of lookupKeys) {
              atlasStatusCache.set(key, status);
            }
            item.status = '';
            item.statusClass = '';
            renderList();
            setReady(summaryText());
            applyPageMarkers(items);
            window.dispatchEvent(new Event(STATUS_CACHE_UPDATED_EVENT));
            showToast('Download deleted.');
          }
        );
      });
    }

    function queueSelected() {
      const selected = items.filter((item) => item.selected);
      if (selected.length === 0) {
        showToast('Select one or more items first.');
        return;
      }

      const hasDownloaded = selected.some((item) => Boolean(item.atlas?.downloaded));
      if (hasDownloaded) {
        chooseDialog({
          title: 'Re-download selected files',
          message: 'One or more selected files are already downloaded. Re-download them?',
          confirmLabel: 'Re-download',
          cancelLabel: 'Keep existing files',
          alternateLabel: 'Cancel',
        }).then((choice) => {
          if (choice === 'alternate') {
            showToast('Cancelled.');
            return;
          }

          runQueueSelected(choice === 'confirm');
        });
        return;
      }
      runQueueSelected(false);

      function runQueueSelected(forceDownload) {
        sheetState.queueDisabled = true;
        sheetState.refreshDisabled = true;
        sheetState.checkAtlasDisabled = true;
        sheetState.selectAllDisabled = true;
        sheetState.selectNoneDisabled = true;

        for (const item of selected) {
          item.status = 'Sending…';
          item.statusClass = '';
        }
        renderList();

        const payloads = selected.map((item) => {
          const payload = buildDownloadPayload(item);
          if (forceDownload) {
            payload.force_download = true;
          }
          return payload;
        });

        sendMessageSafe(
          { type: 'atlas-download-batch', payloads },
          (response) => {
          if (!response) {
            for (const item of selected) {
              item.status = 'No response';
              item.statusClass = 'err';
            }
            renderList();
            setReady(summaryText());
            showToast('Atlas extension did not respond.', 'danger');
            return;
          }

          const results = Array.isArray(response.results) ? response.results : [];
          for (let i = 0; i < selected.length; i += 1) {
            const item = selected[i];
            const result = results[i];
            if (result?.ok) {
              const data = result.data || null;
              const file = data?.file || null;

              item.atlas = {
                exists: Boolean(file),
                downloaded: Boolean(file?.downloaded),
                blacklisted: Boolean(file?.blacklisted_at),
                file_id: file?.id ?? null,
                reaction: item.atlas?.reaction ?? null,
              };
              const lookupKeys = itemLookupKeys(item);
              if (lookupKeys.length > 0) {
                const status = {
                  exists: item.atlas.exists,
                  downloaded: item.atlas.downloaded,
                  blacklisted: item.atlas.blacklisted,
                  reactionType: item.atlas.reaction?.type ? String(item.atlas.reaction.type) : null,
                  downloadProgress: normalizeProgress(file?.download_progress),
                  downloadedAt: normalizeDownloadedAt(file?.downloaded_at),
                  ts: Date.now(),
                };
                for (const key of lookupKeys) {
                  atlasStatusCache.set(key, status);
                }
              }

              if (data?.queued) {
                item.status = 'Queued';
                item.statusClass = 'queued';
                if (item.atlas?.reaction?.type && item.atlas.reaction.type !== 'dislike') {
                  item.reactionQueued = item.atlas.reaction.type;
                }
                if (lookupKeys.length > 0) {
                  for (const key of lookupKeys) {
                    queuedLookupUrls.add(key);
                  }
                }
              } else {
                item.status = '';
                item.statusClass = '';
                item.reactionQueued = null;
                if (lookupKeys.length > 0) {
                  for (const key of lookupKeys) {
                    queuedLookupUrls.delete(key);
                  }
                }
              }
            } else {
              item.status = result?.error || 'Failed';
              item.statusClass = 'err';
            }
          }

          renderList();
          setReady(summaryText());
          applyPageMarkers(items);
          window.dispatchEvent(new Event(STATUS_CACHE_UPDATED_EVENT));

          if (response.ok) {
            showToast(`Queued ${selected.length} download(s) in Atlas.`);
          } else {
            showToast(response.error || 'Some requests failed.', 'danger');
          }
          }
        );
      }
    }

    function applyPageMarkers(sheetItems = items) {
      ensurePageMarkerStyles();

      clearNodeMarkerAttributes(
        document.querySelectorAll('[data-atlas-marked="1"],[data-atlas-open-tab="1"]')
      );

      const statusByUrl = buildStatusMapFromCache(atlasStatusCache, ATLAS_STATUS_TTL_MS, stripHash);
      mergeSheetItemStatuses(statusByUrl, sheetItems, stripHash);
      const openTabBadgeNodes: Element[] = [];

      const shouldMarkStatusNode = (node: Element): boolean => {
        if (node instanceof HTMLAnchorElement) {
          return node.querySelector('img, video') !== null;
        }

        if (node instanceof HTMLImageElement || node instanceof HTMLVideoElement) {
          return node.closest('a[href]') === null;
        }

        return false;
      };
      const shouldMarkOpenTabNode = (node: Element): boolean =>
        node instanceof HTMLAnchorElement && node.querySelector('img, video') !== null;
      const resolveMarkerTargetNode = (node: Element): Element | null => {
        if (node instanceof HTMLAnchorElement) {
          return node.querySelector('img, video');
        }

        if (node instanceof HTMLImageElement || node instanceof HTMLVideoElement) {
          return node;
        }

        return null;
      };

      const collectStatusLookupKeysForMarkerNode = (node: Element): string[] => {
        if (node instanceof HTMLAnchorElement) {
          if (!shouldMarkStatusNode(node)) {
            return [];
          }

          return collectLookupKeysForNode(node, {
            includeAnchor: true,
            includePageFallback: false,
          });
        }

        if (node instanceof HTMLImageElement || node instanceof HTMLVideoElement) {
          if (!shouldMarkStatusNode(node)) {
            return [];
          }

          return collectLookupKeysForNode(node, {
            includeAnchor: false,
            includePageFallback: false,
          });
        }

        return [];
      };

      const nodes = document.querySelectorAll('img, video, a[href]');
      for (const node of nodes) {
        const markerTargetNode = resolveMarkerTargetNode(node);
        if (!markerTargetNode) {
          continue;
        }

        const statusLookupKeys = shouldMarkStatusNode(node)
          ? collectStatusLookupKeysForMarkerNode(node)
          : [];
        const openTabLookupKeys = shouldMarkOpenTabNode(node)
          ? collectLookupKeysForNode(node, {
            includeAnchor: true,
            includePageFallback: false,
          })
          : [];
        const status = statusLookupKeys.length > 0
          ? findStatusForLookupKeys(statusLookupKeys, statusByUrl, stripHash)
          : null;
        const isOpenInTab = openTabLookupKeys.some((key) => {
          const normalized = normalizeOpenTabUrl(key);
          if (!normalized || !isOpenTabHighlightEligibleUrl(normalized)) {
            return false;
          }

          return openTabUrlSet.has(normalized) || openTabUrlSet.has(stripHash(normalized));
        });

        if (!status && !isOpenInTab) {
          continue;
        }

        if (status) {
          markerTargetNode.setAttribute('data-atlas-marked', '1');
          if (status.blacklisted) {
            markerTargetNode.setAttribute('data-atlas-state', 'blacklisted');
          } else if (status.reactionType) {
            markerTargetNode.setAttribute('data-atlas-state', 'reacted');
          } else if (status.downloaded) {
            markerTargetNode.setAttribute('data-atlas-state', 'downloaded');
          } else if (status.exists) {
            markerTargetNode.setAttribute('data-atlas-state', 'exists');
          }
        }

        if (status?.reactionType) {
          markerTargetNode.setAttribute('data-atlas-reaction', status.reactionType);
        }

        if (isOpenInTab) {
          markerTargetNode.setAttribute('data-atlas-open-tab', '1');
          if (!status?.reactionType) {
            openTabBadgeNodes.push(markerTargetNode);
          }
        }
      }

      syncMarkerRails(
        Array.from(
          document.querySelectorAll('[data-atlas-marked="1"], [data-atlas-open-tab="1"]')
        )
      );
      syncReactionBadgesFromDom();
      syncOpenTabIconBadges(openTabBadgeNodes);
      syncPageVisitedBadge(window.location.href, statusByUrl, stripHash);
    }
    syncOpenTabMarkers = () => applyPageMarkers(items);

    function collectPageMarkerUrls() {
      const urls = new Set<string>();
      const pageUrl = (window.location.href || '').trim();
      if (pageUrl) {
        urls.add(pageUrl);
        urls.add(stripHash(pageUrl));
      }

      const shouldMarkStatusNode = (node: Element): boolean => {
        if (node instanceof HTMLAnchorElement) {
          return node.querySelector('img, video') !== null;
        }

        if (node instanceof HTMLImageElement || node instanceof HTMLVideoElement) {
          return node.closest('a[href]') === null;
        }

        return false;
      };

      const collectStatusLookupKeysForMarkerNode = (node: Element): string[] => {
        if (node instanceof HTMLAnchorElement) {
          if (!shouldMarkStatusNode(node)) {
            return [];
          }

          return collectLookupKeysForNode(node, {
            includeAnchor: true,
            includePageFallback: false,
          });
        }

        if (node instanceof HTMLImageElement || node instanceof HTMLVideoElement) {
          if (!shouldMarkStatusNode(node)) {
            return [];
          }

          return collectLookupKeysForNode(node, {
            includeAnchor: false,
            includePageFallback: false,
          });
        }

        return [];
      };

      const nodes = document.querySelectorAll('img, video, a[href]');
      for (const node of nodes) {
        if (!shouldMarkStatusNode(node)) {
          continue;
        }

        const statusLookupKeys = collectStatusLookupKeysForMarkerNode(node);
        for (const key of statusLookupKeys) {
          urls.add(key);
          urls.add(stripHash(key));
        }
      }

      const direct = buildDirectPageCandidate();
      if (direct?.url) {
        urls.add(direct.url);
        urls.add(stripHash(direct.url));
      }

      return [...urls].filter(Boolean);
    }

    // collectLookupKeysForNode moved to items.ts

    function syncAtlasStatusForPageMarkers() {
      const urls = collectPageMarkerUrls();
      if (urls.length === 0) {
        applyPageMarkers(items);
        return;
      }

      sendMessageSafe({ type: 'atlas-check-batch', urls }, (response) => {
        if (!response?.ok) {
          applyPageMarkers(items);
          return;
        }

        const results = Array.isArray(response.data?.results) ? response.data.results : [];
        for (const match of results) {
          if (!match?.url) {
            continue;
          }

          atlasStatusCache.set(String(match.url), {
            exists: Boolean(match.exists),
            downloaded: Boolean(match.downloaded),
            blacklisted: Boolean(match.blacklisted),
            reactionType: match.reaction?.type ? String(match.reaction.type) : null,
            downloadProgress: normalizeProgress(match.download_progress),
            downloadedAt: normalizeDownloadedAt(match.downloaded_at),
            ts: Date.now(),
          });
        }

        applyPageMarkers(items);
        window.dispatchEvent(new Event(STATUS_CACHE_UPDATED_EVENT));
      });
    }

    window.addEventListener(
      STATUS_CACHE_UPDATED_EVENT,
      () => {
        applyPageMarkers(items);
      },
      true
    );
  }

  function collectCandidates(onProgress) {
    return collectCandidatesModule(
      {
        rootId: ROOT_ID,
        getMinMediaWidth: () => minMediaWidth,
      },
      onProgress
    );
  }

  // buildItemFromElement moved to items.ts

  // buildDirectPageCandidate helpers moved to items.ts

  // UI and interaction helpers moved to dedicated modules.
}
