import './content.css';
import { registrableDomainFromUrl } from '../shared/domain';
import {
  buildDirectPageCandidate,
  buildItemFromElement,
  collectLookupKeysForNode,
  configureMediaNoiseFilters,
} from './items';
import { installHotkeys, installMediaReactionOverlay } from './interactions';
import { isHostExcluded, isHostMatch, parseExcludedDomains, resolveHost, stripHash } from './network';
import { BLACKLIST_ACTION, REACTIONS, createSvgIcon } from './reactions';
import { createDialogChooser, createToastFn, ensurePageMarkerStyles } from './ui';

type ContentSettings = {
  atlasBaseUrl?: string;
  atlasExcludedDomains?: string;
  atlasMediaNoiseFilters?: string;
};

type ChromeStorageSync = {
  get: (keys: string[], callback: (data: ContentSettings) => void) => void;
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
  };
  runtime: ChromeRuntime;
};

declare const chrome: ChromeApi;

(() => {
  const MIN_SIZE = 200;
  // Keep extension metadata short; some Atlas deployments validate at 500 chars.
  const MAX_METADATA_LEN = 500;
  const ROOT_ID = 'atlas-downloader-root';
  const OPEN_CLASS = 'atlas-open';
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

  let openSheet: (() => void) | null = null;
  let handleRealtimeDownloadEvent: ((payload: unknown) => void) | null = null;

  const ATLAS_STATUS_TTL_MS = 30_000;
  const atlasStatusCache = new Map<
    string,
    {
      exists: boolean;
      downloaded: boolean;
      blacklisted: boolean;
      reactionType: string | null;
      ts: number;
    }
  >();

  function limitString(value, max) {
    const v = typeof value === 'string' ? value : '';
    if (v.length <= max) return v;
    return v.slice(0, max);
  }

  function sourceFromMediaUrl(url) {
    return registrableDomainFromUrl(url) || 'Extension';
  }

  function getCachedAtlasStatus(url: string) {
    const cached = atlasStatusCache.get(url);
    if (!cached) return null;
    if (Date.now() - cached.ts > ATLAS_STATUS_TTL_MS) {
      atlasStatusCache.delete(url);
      return null;
    }
    return cached;
  }

  function fetchAtlasStatus(
    sendMessageSafe: (message: unknown, callback: (response: unknown) => void) => void,
    url: string,
    _referrerUrl: string | null,
    callback: (
      status: { exists: boolean; downloaded: boolean; blacklisted: boolean; reactionType: string | null } | null
    ) => void
  ) {
    const lookupUrl = stripHash((url || '').trim());
    if (!lookupUrl) {
      callback(null);
      return;
    }

    const cached = getCachedAtlasStatus(lookupUrl);
    if (cached) {
      callback(cached);
      return;
    }

    sendMessageSafe({ type: 'atlas-check-batch', urls: [lookupUrl] }, (response) => {
      if (!response || !response.ok) {
        callback(null);
        return;
      }

      const results = Array.isArray(response.data?.results) ? response.data.results : [];
      const byUrl = new Map(
        results
          .filter((r) => typeof r?.url === 'string' && r.url.trim() !== '')
          .map((r) => [stripHash(String(r.url)), r])
      );
      const match = byUrl.get(lookupUrl) ?? null;
      if (!match) {
        callback(null);
        return;
      }

      const status = {
        exists: Boolean(match.exists),
        downloaded: Boolean(match.downloaded),
        blacklisted: Boolean(match.blacklisted),
        reactionType: match.reaction?.type ? String(match.reaction.type) : null,
        ts: Date.now(),
      };

      atlasStatusCache.set(lookupUrl, status);
      callback(status);
    });
  }

  // Allow the toolbar icon (background script) to open the sheet.
  chrome.runtime.onMessage.addListener((message: unknown) => {
    if (!message || typeof message !== 'object') {
      return;
    }

    const msg = message as { type?: unknown; payload?: unknown };
    if (msg.type === 'atlas-download-event') {
      handleRealtimeDownloadEvent?.(msg.payload);
      return;
    }

    if (msg.type !== 'atlas-open-sheet') {
      return;
    }

    if (!IS_TOP_WINDOW) {
      return;
    }

    try {
      chrome.storage.sync.get(['atlasBaseUrl', 'atlasExcludedDomains', 'atlasMediaNoiseFilters'], (data) => {
        configureMediaNoiseFilters(data.atlasMediaNoiseFilters || '');

        const baseHost = resolveHost(data.atlasBaseUrl || '');
        if (baseHost && isHostMatch(window.location.hostname, baseHost)) {
          return;
        }

        const excluded = parseExcludedDomains(data.atlasExcludedDomains || '');
        if (isHostExcluded(window.location.hostname, excluded)) {
          return;
        }

        mountUi();
        openSheet?.();
      });
    } catch {
      // Happens if the extension was reloaded while this tab stayed open.
    }
  });

  chrome.storage.sync.get(['atlasBaseUrl', 'atlasExcludedDomains', 'atlasMediaNoiseFilters'], (data) => {
    configureMediaNoiseFilters(data.atlasMediaNoiseFilters || '');

    const baseHost = resolveHost(data.atlasBaseUrl || '');
    if (baseHost && isHostMatch(window.location.hostname, baseHost)) {
      return;
    }

    const excluded = parseExcludedDomains(data.atlasExcludedDomains || '');
    if (isHostExcluded(window.location.hostname, excluded)) {
      return;
    }

    if (IS_TOP_WINDOW) {
      mountUi();
    } else {
      mountHotkeysOnly();
    }
  });

  function mountHotkeysOnly() {
    if (document.getElementById(ROOT_ID)) {
      return;
    }

    const host = document.createElement('div');
    host.id = ROOT_ID;

    const shadow = host.attachShadow({ mode: 'closed' });

    const style = document.createElement('link');
    style.rel = 'stylesheet';
    style.href = chrome.runtime.getURL('dist/content.css');
    shadow.appendChild(style);

    const root = document.createElement('div');
    root.className = 'atlas-shadow-root';

    const showToast = createToastFn(root);
    const chooseDialog = createDialogChooser(root);
    const sendMessageSafe = (
      message: unknown,
      callback: (response: unknown) => void
    ) => {
      try {
        chrome.runtime.sendMessage(message, callback);
      } catch (error) {
        const messageText = (() => {
          if (error instanceof Error) {
            return error.message;
          }

          if (error && typeof error === 'object' && 'message' in error) {
            const messageValue = (error as { message?: unknown }).message;
            return typeof messageValue === 'string' ? messageValue : String(messageValue);
          }

          return String(error);
        })();

        if (messageText.includes('Extension context invalidated')) {
          showToast('Atlas extension was reloaded. Refresh this tab.', 'danger');
        } else {
          showToast('Atlas extension error. Refresh this tab.', 'danger');
        }

        callback(null);
      }
    };

    shadow.appendChild(root);
    (document.body || document.documentElement).appendChild(host);

    installHotkeys({
      showToast,
      sendMessageSafe,
      isSheetOpen: () => false,
      chooseDialog,
    }, {
      rootId: ROOT_ID,
      minSize: MIN_SIZE,
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
      isSheetOpen: () => false,
      chooseDialog,
    }, {
      rootId: ROOT_ID,
      minSize: MIN_SIZE,
      maxMetadataLen: MAX_METADATA_LEN,
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

    const shadow = host.attachShadow({ mode: 'closed' });

    // Inject styles into shadow DOM
    const style = document.createElement('link');
    style.rel = 'stylesheet';
    style.href = chrome.runtime.getURL('dist/content.css');
    shadow.appendChild(style);

    const root = document.createElement('div');
    root.className = 'atlas-shadow-root';

    const showToast = createToastFn(root);
    const sendMessageSafe = (
      message: unknown,
      callback: (response: unknown) => void
    ) => {
      try {
        chrome.runtime.sendMessage(message, callback);
      } catch (error) {
        const messageText = (() => {
          if (error instanceof Error) {
            return error.message;
          }

          if (error && typeof error === 'object' && 'message' in error) {
            const messageValue = (error as { message?: unknown }).message;
            return typeof messageValue === 'string' ? messageValue : String(messageValue);
          }

          return String(error);
        })();

        if (messageText.includes('Extension context invalidated')) {
          showToast('Atlas extension was reloaded. Refresh this tab.', 'danger');
        } else {
          showToast('Atlas extension error. Refresh this tab.', 'danger');
        }

        callback(null);
      }
    };

    const toggle = document.createElement('button');
    toggle.className = 'atlas-downloader-toggle';
    toggle.type = 'button';
    toggle.setAttribute('aria-label', 'Atlas Downloader');
    toggle.title = 'Atlas Downloader';

    const icon = document.createElement('img');
    icon.alt = '';
    icon.src = chrome.runtime.getURL('icon.svg');
    toggle.appendChild(icon);

    const overlay = document.createElement('div');
    overlay.className = 'atlas-downloader-overlay';

    const modal = document.createElement('div');
    modal.className = 'atlas-downloader-modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-label', 'Atlas Media Picker');

    const header = document.createElement('div');
    header.className = 'atlas-downloader-header';

    const title = document.createElement('div');
    title.className = 'atlas-downloader-title';
    title.textContent = 'Atlas Media Picker';

    const version = document.createElement('div');
    version.className = 'atlas-downloader-version';
    version.textContent = EXT_VERSION ? `v${EXT_VERSION}` : '';

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'atlas-downloader-close';
    close.setAttribute('aria-label', 'Close');
    close.textContent = 'x';

    header.appendChild(title);
    header.appendChild(version);
    header.appendChild(close);

    const toolbar = document.createElement('div');
    toolbar.className = 'atlas-downloader-toolbar';

    const refresh = makeButton('Rescan', () => refreshList());
    const checkAtlas = makeButton('Check Atlas', () => checkAtlasStatus(false));
    const selectAll = makeButton('Select all', () => setAllSelected(true));
    const selectNone = makeButton('Select none', () => setAllSelected(false));

    const spacer = document.createElement('span');
    spacer.className = 'spacer';

    const queue = makeButton('Queue selected', () => queueSelected(), {
      primary: true,
    });

    toolbar.appendChild(refresh);
    toolbar.appendChild(checkAtlas);
    toolbar.appendChild(selectAll);
    toolbar.appendChild(selectNone);
    toolbar.appendChild(spacer);
    toolbar.appendChild(queue);

    const meta = document.createElement('div');
    meta.className = 'atlas-downloader-meta';

    const list = document.createElement('div');
    list.className = 'atlas-downloader-list';

    modal.appendChild(header);
    modal.appendChild(toolbar);
    modal.appendChild(meta);
    modal.appendChild(list);

    root.appendChild(toggle);
    root.appendChild(overlay);
    root.appendChild(modal);
    shadow.appendChild(root);
    (document.body || document.documentElement).appendChild(host);

    const chooseDialog = createDialogChooser(root);

    let items = [];
    let scanNonce = 0;
    let reactingItemUrl: string | null = null;
    let reactingItemType: string | null = null;
    let debugTargetUrl: string | null = null;
    let markerSyncTimer: number | null = null;
    const queuedLookupUrls = new Set<string>();
    const lookupByTransferId = new Map<number, string>();
    // Enable hotkeys immediately after UI mounts; event delegation makes it work for dynamic content too.
    const hotkeysEnabled = true;
    let hotkeysHintShown = false;

    handleRealtimeDownloadEvent = (payload: unknown) => {
      if (!payload || typeof payload !== 'object') {
        return;
      }

      const data = payload as {
        transferId?: unknown;
        status?: unknown;
        original?: unknown;
        referrer_url?: unknown;
        downloaded?: unknown;
        failed?: unknown;
        finished_at?: unknown;
        failed_at?: unknown;
      };

      const transferId = Number.isFinite(Number(data.transferId)) ? Number(data.transferId) : null;
      const status = typeof data.status === 'string' ? data.status : '';
      const downloaded = Boolean(data.downloaded) || status === 'completed' || typeof data.finished_at === 'string';
      const failed = Boolean(data.failed) || status === 'failed' || status === 'canceled' || typeof data.failed_at === 'string';

      const lookupCandidates = new Set<string>();
      const originalLookup = typeof data.original === 'string' ? stripHash(data.original.trim()) : '';
      const referrerLookup = typeof data.referrer_url === 'string' ? stripHash(data.referrer_url.trim()) : '';
      if (originalLookup) {
        lookupCandidates.add(originalLookup);
      }
      if (referrerLookup) {
        lookupCandidates.add(referrerLookup);
      }

      const mappedLookup = transferId ? lookupByTransferId.get(transferId) ?? '' : '';
      if (mappedLookup) {
        lookupCandidates.add(mappedLookup);
      }

      if (lookupCandidates.size === 0) {
        return;
      }

      const primaryLookup = originalLookup || mappedLookup || referrerLookup || '';
      if (transferId && primaryLookup && !downloaded && !failed) {
        lookupByTransferId.set(transferId, primaryLookup);
      }

      let changed = false;
      for (const lookup of lookupCandidates) {
        const cached = getCachedAtlasStatus(lookup);
        atlasStatusCache.set(lookup, {
          exists: true,
          downloaded,
          blacklisted: cached ? Boolean(cached.blacklisted) : false,
          reactionType: cached?.reactionType ?? null,
          ts: Date.now(),
        });
      }

      for (const item of items) {
        const lookup = itemLookupUrl(item) || stripHash(String(item.url || ''));
        if (!lookup || !lookupCandidates.has(lookup)) {
          continue;
        }

        if (!queuedLookupUrls.has(lookup) && item.status !== 'Queued') {
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
          queuedLookupUrls.delete(lookup);
        } else if (failed) {
          item.status = 'Failed';
          item.statusClass = 'err';
          item.reactionQueued = null;
          queuedLookupUrls.delete(lookup);
        } else {
          item.status = 'Queued';
          item.statusClass = 'queued';
        }

        changed = true;
      }

      if (transferId && (downloaded || failed)) {
        lookupByTransferId.delete(transferId);
      }

      if (!changed) {
        return;
      }

      renderList();
      setReady(summaryText());
      applyPageMarkers(items);
    };

    toggle.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      toggleModal();
    });

    overlay.addEventListener('click', () => closeModal());
    close.addEventListener('click', () => closeModal());

    document.addEventListener('keydown', (event) => {
      const key = event.key.toLowerCase();
      if (event.altKey && event.shiftKey && key === 'a') {
        event.preventDefault();
        toggleModal();
        return;
      }

      if (event.key === 'Escape' && root.classList.contains(OPEN_CLASS)) {
        closeModal();
        return;
      }

      if (!root.classList.contains(OPEN_CLASS)) {
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
      root.classList.add(OPEN_CLASS);
      refreshList();
    }

    function toggleModal() {
      if (root.classList.contains(OPEN_CLASS)) {
        closeModal();
        return;
      }

      openModal();
    }

    function closeModal() {
      root.classList.remove(OPEN_CLASS);
    }

    openSheet = toggleModal;

    installHotkeys({
      showToast,
      sendMessageSafe,
      isSheetOpen: () => root.classList.contains(OPEN_CLASS),
      chooseDialog,
      setHintShown: (value) => {
        hotkeysHintShown = value;
      },
      getHintShown: () => hotkeysHintShown,
      enabled: hotkeysEnabled,
    }, {
      rootId: ROOT_ID,
      minSize: MIN_SIZE,
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
      isSheetOpen: () => root.classList.contains(OPEN_CLASS),
      chooseDialog,
    }, {
      rootId: ROOT_ID,
      minSize: MIN_SIZE,
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
        const resolvedUrl = explicitUrl || (custom.detail?.media ? buildItemFromElement(custom.detail.media, MIN_SIZE)?.url || '' : '');
        const lookup = resolvedUrl ? stripHash(resolvedUrl) : '';

        if (pending) {
          reactingItemUrl = lookup || '__external-reaction__';
          reactingItemType = reactionType || reactingItemType || 'like';
          for (const item of items) {
            if (lookup && (itemLookupUrl(item) || stripHash(String(item.url || ''))) !== lookup) {
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
          if (lookup && (itemLookupUrl(item) || stripHash(String(item.url || ''))) !== lookup) {
            continue;
          }

          if (item.reactionPending) {
            item.reactionPending = null;
          }

          const cached =
            (lookup ? getCachedAtlasStatus(lookup) : null) ??
            getCachedAtlasStatus(itemLookupUrl(item)) ??
            getCachedAtlasStatus(stripHash(String(item.url || '')));

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

    const scheduleMarkerSync = (delayMs = 300) => {
      if (markerSyncTimer !== null) {
        window.clearTimeout(markerSyncTimer);
      }
      markerSyncTimer = window.setTimeout(() => {
        markerSyncTimer = null;
        syncAtlasStatusForPageMarkers();
      }, delayMs);
    };

    const mutationObserver = new MutationObserver(() => {
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
    document.addEventListener(
      'visibilitychange',
      () => {
        if (document.visibilityState === 'visible') {
          scheduleMarkerSync(120);
        }
      },
      true
    );
    scheduleMarkerSync(120);

    function makeButton(label, onClick, options) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `atlas-downloader-btn${options?.primary ? ' primary' : ''}`;
      button.textContent = label;
      button.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        onClick();
      });
      return button;
    }

    function setLoading(text) {
      meta.textContent = text;
      queue.disabled = true;
      refresh.disabled = true;
      checkAtlas.disabled = true;
      selectAll.disabled = true;
      selectNone.disabled = true;
    }

    function setReady(text) {
      meta.textContent = text;
      const selectedCount = items.filter((item) => item.selected).length;
      const busy = items.some((item) => Boolean(item.reactionPending)) || reactingItemUrl !== null;
      queue.disabled = selectedCount === 0 || busy;
      refresh.disabled = busy;
      checkAtlas.disabled = items.length === 0 || busy;
      selectAll.disabled = items.length === 0 || busy;
      selectNone.disabled = items.length === 0 || busy;
    }

    function refreshList() {
      scanNonce += 1;
      const currentScan = scanNonce;
      items = [];
      debugTargetUrl = null;
      list.replaceChildren();
      setLoading('Scanning this page…');

      collectCandidates((progress) => {
        if (scanNonce !== currentScan) {
          return;
        }
        meta.textContent = `Scanning… ${progress.scanned}/${progress.total}`;
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
            && (itemLookupUrl(item) || stripHash(String(item.url || ''))) === reactingItemUrl
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
      list.replaceChildren();

      if (items.length === 0) {
        const empty = document.createElement('div');
        empty.style.padding = '10px 12px';
        empty.style.color = '#94a3b8';
        empty.style.fontSize = '12px';
        empty.textContent = 'No matching images/videos found.';
        list.appendChild(empty);
        return;
      }

      for (const item of items) {
        list.appendChild(renderItemRow(item));
      }
    }

    function renderItemRow(item) {
      const row = document.createElement('div');
      row.className = `atlas-downloader-item${item.selected ? ' selected' : ''}`;

      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.checked = item.selected;
      checkbox.addEventListener('change', () => {
        item.selected = checkbox.checked;
        row.classList.toggle('selected', item.selected);
        setReady(summaryText());
      });

      const preview = document.createElement('div');
      preview.className = 'atlas-downloader-preview';
      if (item.preview_url) {
        const img = document.createElement('img');
        img.loading = 'lazy';
        img.alt = '';
        img.src = item.preview_url;
        preview.appendChild(img);
      }

      const info = document.createElement('div');
      info.className = 'atlas-downloader-info';

      const kind = document.createElement('div');
      kind.className = 'atlas-downloader-kind';
      kind.textContent = item.tag_name;

      const url = document.createElement('div');
      url.className = 'atlas-downloader-url';
      url.textContent = item.url;
      url.title = item.url;

      const sub = document.createElement('div');
      sub.className = 'atlas-downloader-sub';
      sub.textContent = formatSubline(item);

      const reactions = document.createElement('div');
      reactions.className = 'atlas-downloader-reactions';
      const currentReaction = item.atlas?.reaction?.type || null;
      const isBusy = Boolean(item.reactionPending) || Boolean(item.reactionQueued);
      for (const reaction of REACTIONS) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `atlas-downloader-reaction-btn ${reaction.className}${
          currentReaction === reaction.type ? ' active' : ''
        }${item.reactionPending === reaction.type ? ' pending' : ''}${
          item.reactionQueued === reaction.type ? ' queued' : ''
        }`.trim();
        button.setAttribute('aria-label', reaction.label);
        button.title = reaction.label;
        button.replaceChildren(createSvgIcon(reaction.pathDs));
        button.disabled = isBusy;
        button.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();
          reactToItem(item, reaction.type);
        });
        reactions.appendChild(button);
      }

      const blacklistButton = document.createElement('button');
      blacklistButton.type = 'button';
      blacklistButton.className = `atlas-downloader-reaction-btn ${BLACKLIST_ACTION.className}${
        item.atlas?.blacklisted ? ' active' : ''
      }${item.reactionPending === BLACKLIST_ACTION.type ? ' pending' : ''}${
        item.reactionQueued === BLACKLIST_ACTION.type ? ' queued' : ''
      }`.trim();
      blacklistButton.setAttribute('aria-label', BLACKLIST_ACTION.label);
      blacklistButton.title = BLACKLIST_ACTION.label;
      blacklistButton.replaceChildren(createSvgIcon(BLACKLIST_ACTION.pathDs));
      blacklistButton.disabled = isBusy;
      blacklistButton.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        reactToItem(item, 'dislike', { blacklist: true });
      });
      reactions.appendChild(blacklistButton);

      if (item.atlas?.downloaded) {
        const deleteButton = document.createElement('button');
        deleteButton.type = 'button';
        deleteButton.className = `atlas-downloader-reaction-btn delete${
          item.reactionPending === 'delete-download' ? ' pending' : ''
        }`.trim();
        deleteButton.setAttribute('aria-label', 'Delete download');
        deleteButton.title = 'Delete download';
        deleteButton.replaceChildren(
          createSvgIcon(['M3 6h18', 'M8 6V4h8v2', 'M8 10v8', 'M12 10v8', 'M16 10v8', 'M6 6l1 14h10l1-14'])
        );
        deleteButton.disabled = isBusy;
        deleteButton.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();
          deleteDownloadForItem(item);
        });
        reactions.appendChild(deleteButton);
      }

      const debugButton = document.createElement('button');
      debugButton.type = 'button';
      debugButton.className = `atlas-downloader-debug-toggle${debugTargetUrl === item.url ? ' active' : ''}`;
      debugButton.textContent = debugTargetUrl === item.url ? 'Hide debug' : 'Debug';
      debugButton.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        debugTargetUrl = debugTargetUrl === item.url ? null : item.url;
        renderList();
        setReady(summaryText());
      });
      reactions.appendChild(debugButton);

      info.appendChild(kind);
      info.appendChild(url);
      info.appendChild(sub);
      info.appendChild(reactions);

      if (debugTargetUrl === item.url) {
        info.appendChild(renderDebugDetails(item));
      }

      const status = document.createElement('div');
      const displayStatus = getDisplayStatus(item);
      status.className = `atlas-downloader-status ${displayStatus.className}`.trim();
      status.textContent = displayStatus.text;

      row.addEventListener('click', (event) => {
        const target = event.target;
        if (target instanceof HTMLInputElement) {
          return;
        }

        item.selected = !item.selected;
        checkbox.checked = item.selected;
        row.classList.toggle('selected', item.selected);
        setReady(summaryText());
      });

      row.appendChild(checkbox);
      row.appendChild(preview);
      row.appendChild(info);
      row.appendChild(status);

      return row;
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
        source: sourceFromMediaUrl(item.url),
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
        source: sourceFromMediaUrl(item.url),
        ...(item.download_via ? { download_via: item.download_via } : {}),
        // Align with Atlas core behavior: reactions dispatch downloads.
        reaction_type: 'like',
      };
    }

    function renderDebugDetails(item) {
      const container = document.createElement('details');
      container.className = 'atlas-downloader-debug';
      container.open = true;

      const summary = document.createElement('summary');
      summary.textContent = 'Debug payload';
      container.appendChild(summary);

      const payload = {
        react: buildReactionPayload(item, item.atlas?.reaction?.type || 'like'),
        download: buildDownloadPayload(item),
      };
      container.appendChild(renderJsonTree(payload));

      return container;
    }

    function renderJsonTree(value, depth = 0) {
      const root = document.createElement('div');
      root.className = 'atlas-downloader-json-tree';
      const normalized = normalizeJsonValue(value);
      if (!isJsonContainer(normalized)) {
        const primitive = document.createElement('div');
        primitive.className = 'atlas-downloader-json-line';
        primitive.textContent = formatJsonPrimitive(normalized);
        root.appendChild(primitive);
        return root;
      }

      const entries = Array.isArray(normalized)
        ? normalized.map((entry, index) => [String(index), entry])
        : Object.entries(normalized);
      for (const [key, child] of entries) {
        root.appendChild(renderJsonEntry(key, child, depth));
      }

      return root;
    }

    function renderJsonEntry(key, value, depth) {
      const normalized = normalizeJsonValue(value);
      const line = document.createElement('div');
      line.className = 'atlas-downloader-json-line';
      line.style.paddingLeft = `${depth * 12}px`;

      if (!isJsonContainer(normalized)) {
        line.innerHTML = `<span class="atlas-downloader-json-key">${escapeHtml(
          key
        )}</span>: <span class="atlas-downloader-json-value">${escapeHtml(
          formatJsonPrimitive(normalized)
        )}</span>`;
        return line;
      }

      const details = document.createElement('details');
      details.className = 'atlas-downloader-json-node';
      details.open = depth === 0;

      const summary = document.createElement('summary');
      summary.style.paddingLeft = `${depth * 12}px`;
      const preview = Array.isArray(normalized)
        ? `[${normalized.length}]`
        : `{${Object.keys(normalized).length}}`;
      summary.innerHTML = `<span class="atlas-downloader-json-key">${escapeHtml(
        key
      )}</span>: <span class="atlas-downloader-json-preview">${escapeHtml(preview)}</span>`;
      details.appendChild(summary);

      const children = Array.isArray(normalized)
        ? normalized.map((entry, index) => [String(index), entry])
        : Object.entries(normalized);
      for (const [childKey, childValue] of children) {
        details.appendChild(renderJsonEntry(childKey, childValue, depth + 1));
      }

      return details;
    }

    function normalizeJsonValue(value) {
      if (value === undefined) {
        return null;
      }

      if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
        return value;
      }

      if (Array.isArray(value)) {
        return value.map((entry) => normalizeJsonValue(entry));
      }

      if (!value || typeof value !== 'object') {
        return null;
      }

      const normalized = {};
      for (const [key, entry] of Object.entries(value)) {
        normalized[key] = normalizeJsonValue(entry);
      }

      return normalized;
    }

    function isJsonContainer(value) {
      return Array.isArray(value) || (value && typeof value === 'object');
    }

    function formatJsonPrimitive(value) {
      return JSON.stringify(value);
    }

    function escapeHtml(value) {
      return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    }

    function formatSubline(item) {
      const dims =
        item.width && item.height ? `${item.width}×${item.height}` : 'size unknown';
      const host = safeHost(item.url);
      return host ? `${dims} • ${host}` : dims;
    }

    function safeHost(url) {
      try {
        return new URL(url).hostname;
      } catch {
        return '';
      }
    }

    function summaryText() {
      const selectedCount = items.filter((item) => item.selected).length;
      return `${items.length} found • ${selectedCount} selected`;
    }

    function itemLookupUrl(item) {
      const preferred = (item?.url || item?.referrer_url || '').trim();
      return preferred ? stripHash(preferred) : '';
    }

    function getDisplayStatus(item) {
      if (item.status) {
        return {
          text: item.status,
          className: item.statusClass || '',
        };
      }

      if (item.atlas?.downloaded) {
        return { text: 'Downloaded', className: 'ok' };
      }

      if (item.atlas?.blacklisted) {
        return { text: 'Blacklisted', className: 'err' };
      }

      if (item.atlas?.exists) {
        return { text: 'In Atlas', className: '' };
      }

      return { text: '', className: '' };
    }

    function checkAtlasStatus(silent) {
      const urls = [...new Set(items.map((item) => itemLookupUrl(item)).filter(Boolean))];
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
        const byUrl = new Map(
          results
            .filter((r) => typeof r?.url === 'string' && r.url.trim() !== '')
            .map((r) => [stripHash(String(r.url)), r])
        );

        let existsCount = 0;
        let downloadedCount = 0;
        for (const item of items) {
          const lookup = itemLookupUrl(item);
          const match = byUrl.get(lookup);
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
          if (item.atlas.exists && !item.atlas.downloaded && item.atlas.reaction?.type && item.atlas.reaction.type !== 'dislike') {
            item.reactionQueued = item.atlas.reaction.type;
            queuedLookupUrls.add(lookup);
          } else {
            item.reactionQueued = null;
            queuedLookupUrls.delete(lookup);
          }

          atlasStatusCache.set(lookup, {
            exists: Boolean(match.exists),
            downloaded: Boolean(match.downloaded),
            blacklisted: Boolean(match.blacklisted),
            reactionType: match.reaction?.type ? String(match.reaction.type) : null,
            ts: Date.now(),
          });

          if (match.exists) existsCount += 1;
          if (match.downloaded) downloadedCount += 1;
        }

        renderList();
        setReady(summaryText());
        applyPageMarkers(items);

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

          const lookup = itemLookupUrl(item) || item.url;
          atlasStatusCache.set(lookup, {
            exists: item.atlas.exists,
            downloaded: item.atlas.downloaded,
            blacklisted: item.atlas.blacklisted,
            reactionType: item.atlas.reaction?.type ? String(item.atlas.reaction.type) : null,
            ts: Date.now(),
          });

          if (item.atlas.exists && !item.atlas.downloaded && type !== 'dislike') {
            item.status = 'Queued';
            item.statusClass = 'queued';
            item.reactionQueued = options.blacklist ? BLACKLIST_ACTION.type : type;
            if (lookup) {
              queuedLookupUrls.add(lookup);
            }
          } else {
            item.status = '';
            item.statusClass = '';
            item.reactionQueued = null;
            if (lookup) {
              queuedLookupUrls.delete(lookup);
            }
          }
          renderList();
          setReady(summaryText());
          applyPageMarkers(items);

          if (options.closeOnSuccess) {
            closeModal();
          }

        });
      };

      const hasExistingReaction = Boolean(item.atlas?.reaction?.type);
      if (type !== 'dislike' && item.atlas?.downloaded && hasExistingReaction) {
        chooseDialog({
          title: 'Already downloaded',
          message: 'This file is already downloaded. Re-download before updating the reaction?',
          confirmLabel: 'Re-download',
          cancelLabel: 'Keep existing file',
          alternateLabel: 'Cancel',
        }).then((choice) => {
          if (choice === 'alternate') {
            showToast('Cancelled.');
            return;
          }
          proceed(choice === 'confirm' ? { force_download: true } : {});
        });
        return;
      }

      if (type === 'dislike' && item.atlas?.downloaded) {
        chooseDialog({
          title: options.blacklist ? 'Blacklist file' : 'Dislike file',
          message: 'Delete the downloaded file before applying this action?',
          confirmLabel: 'Delete then proceed',
          cancelLabel: 'Keep file and proceed',
          alternateLabel: 'Cancel',
          danger: true,
        }).then((choice) => {
          if (choice === 'alternate') {
            showToast('Cancelled.');
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
            const lookup = itemLookupUrl(item) || item.url;
            atlasStatusCache.set(lookup, {
              exists: item.atlas.exists,
              downloaded: item.atlas.downloaded,
              blacklisted: item.atlas.blacklisted,
              reactionType: null,
              ts: Date.now(),
            });
            item.status = '';
            item.statusClass = '';
            renderList();
            setReady(summaryText());
            applyPageMarkers(items);
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
        queue.disabled = true;
        refresh.disabled = true;
        checkAtlas.disabled = true;
        selectAll.disabled = true;
        selectNone.disabled = true;

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

              if (data?.queued) {
                item.status = 'Queued';
                item.statusClass = 'queued';
                if (item.atlas?.reaction?.type && item.atlas.reaction.type !== 'dislike') {
                  item.reactionQueued = item.atlas.reaction.type;
                }
                const lookup = itemLookupUrl(item) || stripHash(String(item.url || ''));
                if (lookup) {
                  queuedLookupUrls.add(lookup);
                }
              } else {
                item.status = '';
                item.statusClass = '';
                item.reactionQueued = null;
                const lookup = itemLookupUrl(item) || stripHash(String(item.url || ''));
                if (lookup) {
                  queuedLookupUrls.delete(lookup);
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

      const marked = document.querySelectorAll('[data-atlas-marked="1"]');
      for (const node of marked) {
        node.removeAttribute('data-atlas-marked');
        node.removeAttribute('data-atlas-state');
        node.removeAttribute('data-atlas-reaction');
      }

      const statusByUrl = new Map<
        string,
        { exists: boolean; downloaded: boolean; blacklisted: boolean; reactionType: string | null }
      >();

      for (const [url, cached] of atlasStatusCache.entries()) {
        if (Date.now() - cached.ts > ATLAS_STATUS_TTL_MS) {
          atlasStatusCache.delete(url);
          continue;
        }

        statusByUrl.set(url, {
          exists: Boolean(cached.exists),
          downloaded: Boolean(cached.downloaded),
          blacklisted: Boolean(cached.blacklisted),
          reactionType: cached.reactionType ? String(cached.reactionType) : null,
        });
        statusByUrl.set(stripHash(url), {
          exists: Boolean(cached.exists),
          downloaded: Boolean(cached.downloaded),
          blacklisted: Boolean(cached.blacklisted),
          reactionType: cached.reactionType ? String(cached.reactionType) : null,
        });
      }

      for (const item of sheetItems) {
        if (!item?.url || !item?.atlas) {
          continue;
        }

        const reactionType = item.atlas?.reaction?.type ? String(item.atlas.reaction.type) : null;
        const status = {
          exists: Boolean(item.atlas.exists),
          downloaded: Boolean(item.atlas.downloaded),
          blacklisted: Boolean(item.atlas.blacklisted),
          reactionType,
        };

        statusByUrl.set(item.url, status);
        statusByUrl.set(stripHash(item.url), status);
      }

      if (statusByUrl.size === 0) {
        return;
      }

      const nodes = document.querySelectorAll('img, video, a[href]');
      for (const node of nodes) {
        const lookupKeys = collectLookupKeysForNode(node);
        if (lookupKeys.length === 0) {
          continue;
        }

        const status =
          lookupKeys
            .map((key) => statusByUrl.get(key) || statusByUrl.get(stripHash(key)))
            .find((value) =>
              Boolean(value && (value.exists || value.downloaded || value.blacklisted || value.reactionType))
            ) ?? null;
        if (!status) {
          continue;
        }

        node.setAttribute('data-atlas-marked', '1');
        if (status.blacklisted) {
          node.setAttribute('data-atlas-state', 'blacklisted');
        } else if (status.reactionType) {
          node.setAttribute('data-atlas-state', 'reacted');
        } else if (status.downloaded) {
          node.setAttribute('data-atlas-state', 'downloaded');
        } else if (status.exists) {
          node.setAttribute('data-atlas-state', 'exists');
        }

        if (status.reactionType) {
          node.setAttribute('data-atlas-reaction', status.reactionType);
        }
      }
    }

    function collectPageMarkerUrls() {
      const urls = new Set<string>();
      const nodes = document.querySelectorAll('img, video, a[href]');
      for (const node of nodes) {
        for (const key of collectLookupKeysForNode(node)) {
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
            ts: Date.now(),
          });
        }

        applyPageMarkers(items);
      });
    }
  }

  function collectCandidates(onProgress) {
    return new Promise((resolve) => {
      const root = document.body || document.documentElement;
      const nodes = Array.from(root.querySelectorAll('img, video'));
      const total = nodes.length;
      const seen = new Set();
      const items = [];
      let index = 0;

      const schedule = (fn) => {
        if (typeof window.requestIdleCallback === 'function') {
          window.requestIdleCallback(fn, { timeout: 500 });
          return;
        }

        setTimeout(() => fn({ timeRemaining: () => 25 }), 0);
      };

      const work = (deadline) => {
        const timeRemaining =
          typeof deadline?.timeRemaining === 'function' ? deadline.timeRemaining() : 25;

        let steps = 0;
        while (index < total && (steps < 80 || timeRemaining > 5)) {
          const element = nodes[index];
          if (element.closest && element.closest(`#${ROOT_ID}`)) {
            index += 1;
            steps += 1;
            continue;
          }

          const item = buildItemFromElement(element, MIN_SIZE);
          if (item?.url && !seen.has(item.url)) {
            seen.add(item.url);
            items.push(item);
          }

          index += 1;
          steps += 1;
        }

        onProgress?.({ scanned: index, total });

        if (index < total) {
          schedule(work);
          return;
        }

        const direct = buildDirectPageCandidate();
        if (direct && !seen.has(direct.url)) {
          seen.add(direct.url);
          items.push(direct);
        }

        resolve(items);
      };

      schedule(work);
    });
  }

  // buildItemFromElement moved to items.ts

  // buildDirectPageCandidate helpers moved to items.ts

  // UI and interaction helpers moved to dedicated modules.
})();
