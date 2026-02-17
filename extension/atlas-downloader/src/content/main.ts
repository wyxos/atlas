import './content.css';
import { registrableDomainFromUrl } from '../shared/domain';

type ContentSettings = {
  atlasBaseUrl?: string;
  atlasExcludedDomains?: string;
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
  const MIN_SIZE = 450;
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

  const SVG_NS = 'http://www.w3.org/2000/svg';
  const createSvgIcon = (pathDs: string[]): SVGSVGElement => {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('width', '18');
    svg.setAttribute('height', '18');

    for (const d of pathDs) {
      const path = document.createElementNS(SVG_NS, 'path');
      path.setAttribute('d', d);
      path.setAttribute('fill', 'none');
      path.setAttribute('stroke', 'currentColor');
      path.setAttribute('stroke-width', '2');
      path.setAttribute('stroke-linecap', 'round');
      path.setAttribute('stroke-linejoin', 'round');
      svg.appendChild(path);
    }

    return svg;
  };

  const REACTIONS = [
    {
      type: 'love',
      label: 'Favorite',
      className: 'love',
      pathDs: [
        'M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78Z',
      ],
    },
    {
      type: 'like',
      label: 'Like',
      className: 'like',
      pathDs: [
        'M7 10v12',
        'M15 5.88 14 10h6.14a2 2 0 0 1 1.94 2.46l-2.34 8.25A2 2 0 0 1 17.82 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.34a2 2 0 0 0 1.79-1.11l3.07-5.89A2 2 0 0 1 15 2a2 2 0 0 1 2 2v1.88Z',
      ],
    },
    {
      type: 'dislike',
      label: 'Dislike',
      className: 'dislike',
      pathDs: [
        'M17 14V2',
        'M9 18.12 10 14H3.86a2 2 0 0 1-1.94-2.46L4.26 3.29A2 2 0 0 1 6.18 2H20a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2.34a2 2 0 0 0-1.79 1.11l-3.07 5.89A2 2 0 0 1 9 22a2 2 0 0 1-2-2v-1.88Z',
      ],
    },
    {
      type: 'funny',
      label: 'Funny',
      className: 'funny',
      pathDs: [
        'M8 14s1.5 2 4 2 4-2 4-2',
        'M9 9h.01',
        'M15 9h.01',
        'M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z',
      ],
    },
  ];
  const BLACKLIST_ACTION = {
    type: 'blacklist',
    label: 'Blacklist',
    className: 'blacklist',
    pathDs: ['M18 6 6 18', 'M6 6l12 12', 'M3 12a9 9 0 1 0 18 0 9 9 0 0 0-18 0Z'],
  };

  const PAGE_MARKER_STYLE_ID = 'atlas-downloader-page-markers';

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
    callback: (
      status: { exists: boolean; downloaded: boolean; blacklisted: boolean; reactionType: string | null } | null
    ) => void
  ) {
    if (!url) {
      callback(null);
      return;
    }

    const cached = getCachedAtlasStatus(url);
    if (cached) {
      callback(cached);
      return;
    }

    sendMessageSafe({ type: 'atlas-check-batch', urls: [url] }, (response) => {
      if (!response || !response.ok) {
        callback(null);
        return;
      }

      const results = Array.isArray(response.data?.results) ? response.data.results : [];
      const match = results.find((r) => r?.url === url) ?? results[0] ?? null;
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

      atlasStatusCache.set(url, status);
      callback(status);
    });
  }

  // Allow the toolbar icon (background script) to open the sheet.
  chrome.runtime.onMessage.addListener((message: unknown) => {
    if (!message || typeof message !== 'object') {
      return;
    }

    const msg = message as { type?: unknown };
    if (msg.type !== 'atlas-open-sheet') {
      return;
    }

    if (!IS_TOP_WINDOW) {
      return;
    }

    try {
      chrome.storage.sync.get(['atlasBaseUrl', 'atlasExcludedDomains'], (data) => {
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

  chrome.storage.sync.get(['atlasBaseUrl', 'atlasExcludedDomains'], (data) => {
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
    });

    installMediaReactionOverlay({
      root,
      showToast,
      sendMessageSafe,
      isSheetOpen: () => false,
      chooseDialog,
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
    let debugEnabled = false;
    let activeDebug: { url: string; reactionType: string } | null = null;
    const debugButton = makeButton('Debug: off', () => {
      debugEnabled = !debugEnabled;
      debugButton.textContent = debugEnabled ? 'Debug: on' : 'Debug: off';

      if (!debugEnabled) {
        activeDebug = null;
      } else if (!activeDebug && items.length > 0) {
        activeDebug = { url: items[0].url, reactionType: 'like' };
      }

      renderList();
      setReady(summaryText());
    });
    const selectAll = makeButton('Select all', () => setAllSelected(true));
    const selectNone = makeButton('Select none', () => setAllSelected(false));

    const spacer = document.createElement('span');
    spacer.className = 'spacer';

    const queue = makeButton('Queue selected', () => queueSelected(), {
      primary: true,
    });

    toolbar.appendChild(refresh);
    toolbar.appendChild(checkAtlas);
    toolbar.appendChild(debugButton);
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
    let markerSyncTimer: number | null = null;
    // Enable hotkeys immediately after UI mounts; event delegation makes it work for dynamic content too.
    const hotkeysEnabled = true;
    let hotkeysHintShown = false;

    toggle.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      openModal();
    });

    overlay.addEventListener('click', () => closeModal());
    close.addEventListener('click', () => closeModal());

    document.addEventListener('keydown', (event) => {
      const key = event.key.toLowerCase();
      if (event.altKey && event.shiftKey && key === 'a') {
        event.preventDefault();
        if (!root.classList.contains(OPEN_CLASS)) {
          openModal();
        }
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

    function closeModal() {
      root.classList.remove(OPEN_CLASS);
    }

    openSheet = openModal;

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
    });

    installMediaReactionOverlay({
      root,
      showToast,
      sendMessageSafe,
      isSheetOpen: () => root.classList.contains(OPEN_CLASS),
      chooseDialog,
    });

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
      debugButton.disabled = true;
      selectAll.disabled = true;
      selectNone.disabled = true;
    }

    function setReady(text) {
      meta.textContent = text;
      const selectedCount = items.filter((item) => item.selected).length;
      const busy = reactingItemUrl !== null;
      queue.disabled = selectedCount === 0 || busy;
      refresh.disabled = busy;
      checkAtlas.disabled = items.length === 0 || busy;
      debugButton.disabled = items.length === 0;
      selectAll.disabled = items.length === 0 || busy;
      selectNone.disabled = items.length === 0 || busy;
    }

    function refreshList() {
      scanNonce += 1;
      const currentScan = scanNonce;
      items = [];
      if (debugEnabled) {
        activeDebug = null;
      }
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
        }));

        if (debugEnabled && !activeDebug && items.length > 0) {
          activeDebug = { url: items[0].url, reactionType: 'like' };
        }

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
      const isBusy = reactingItemUrl !== null;
      for (const reaction of REACTIONS) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `atlas-downloader-reaction-btn ${reaction.className}${
          currentReaction === reaction.type ? ' active' : ''
        }${item.reactionPending === reaction.type ? ' pending' : ''}`.trim();
        button.setAttribute('aria-label', reaction.label);
        button.title = reaction.label;
        button.replaceChildren(createSvgIcon(reaction.pathDs));
        button.disabled = isBusy;
        button.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();
          if (debugEnabled) {
            activeDebug = { url: item.url, reactionType: reaction.type };
            renderList();
          }
          reactToItem(item, reaction.type);
        });
        reactions.appendChild(button);
      }

      const blacklistButton = document.createElement('button');
      blacklistButton.type = 'button';
      blacklistButton.className = `atlas-downloader-reaction-btn ${BLACKLIST_ACTION.className}${
        item.atlas?.blacklisted ? ' active' : ''
      }${item.reactionPending === BLACKLIST_ACTION.type ? ' pending' : ''}`.trim();
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

      info.appendChild(kind);
      info.appendChild(url);
      info.appendChild(sub);
      info.appendChild(reactions);

      if (debugEnabled && activeDebug?.url === item.url) {
        info.appendChild(renderDebugDetails(item, activeDebug.reactionType));
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

        if (debugEnabled) {
          activeDebug = { url: item.url, reactionType: activeDebug?.reactionType ?? 'like' };
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
        original_url: item.original_url || item.url,
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
        original_url: item.original_url || item.url,
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

    function renderDebugDetails(item, reactionType) {
      const container = document.createElement('details');
      container.className = 'atlas-downloader-debug';
      container.open = true;

      const summary = document.createElement('summary');
      summary.textContent = 'Debug payload';
      container.appendChild(summary);

      const pre = document.createElement('pre');
      pre.textContent = JSON.stringify(
        {
          react: buildReactionPayload(item, reactionType),
          download: buildDownloadPayload(item),
        },
        null,
        2
      );
      container.appendChild(pre);

      return container;
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
      const urls = items.map((item) => item.url).filter(Boolean);
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
        const byUrl = new Map(results.map((r) => [r.url, r]));

        let existsCount = 0;
        let downloadedCount = 0;
        for (const item of items) {
          const match = byUrl.get(item.url);
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
        reactingItemUrl = item.url;
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

          atlasStatusCache.set(item.url, {
            exists: item.atlas.exists,
            downloaded: item.atlas.downloaded,
            blacklisted: item.atlas.blacklisted,
            reactionType: item.atlas.reaction?.type ? String(item.atlas.reaction.type) : null,
            ts: Date.now(),
          });

          if (item.atlas.exists && !item.atlas.downloaded && type !== 'dislike') {
            item.status = 'Queued';
            item.statusClass = 'queued';
          } else {
            item.status = '';
            item.statusClass = '';
          }
          renderList();
          setReady(summaryText());
          applyPageMarkers(items);

          if (options.closeOnSuccess) {
            closeModal();
          }

          if (item.atlas.exists && !item.atlas.downloaded && type !== 'dislike') {
            pollUntilDownloaded([item.url], 0);
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
        }).then((choice) => {
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
        reactingItemUrl = item.url;
        renderList();
        setReady(summaryText());

        sendMessageSafe(
          {
            type: 'atlas-delete-download',
            payload: {
              url: item.url,
              original_url: item.original_url || item.url,
              tag_name: item.tag_name,
              download_via: item.download_via || null,
            },
          },
          (response) => {
            item.reactionPending = null;
            reactingItemUrl = null;

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
              reaction: item.atlas?.reaction ?? null,
            };
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
          const urlsToPoll = [];
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
                urlsToPoll.push(item.url);
              } else {
                item.status = '';
                item.statusClass = '';
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

            if (urlsToPoll.length > 0) {
              pollUntilDownloaded(urlsToPoll, 0);
            }
          }
        );
      }
    }

    function pollUntilDownloaded(urls, attempt) {
      if (attempt > 15) {
        return;
      }

      setTimeout(() => {
        sendMessageSafe({ type: 'atlas-check-batch', urls }, (response) => {
          if (!response || !response.ok) {
            pollUntilDownloaded(urls, attempt + 1);
            return;
          }

          const results = Array.isArray(response.data?.results) ? response.data.results : [];
          const byUrl = new Map(results.map((r) => [r.url, r]));

          let remaining = 0;
          for (const item of items) {
            const match = byUrl.get(item.url);
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

            if (!match.downloaded) {
              remaining += 1;
            } else if (item.status === 'Queued') {
              item.status = '';
              item.statusClass = '';
            }
          }

          renderList();
          setReady(summaryText());
          applyPageMarkers(items);

          if (remaining > 0) {
            pollUntilDownloaded(urls, attempt + 1);
          }
        });
      }, 2000);
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
            .find((value) => Boolean(value)) ?? null;
        if (!status) {
          continue;
        }

        node.setAttribute('data-atlas-marked', '1');
        if (status.blacklisted) {
          node.setAttribute('data-atlas-state', 'blacklisted');
        } else if (status.reactionType) {
          node.setAttribute('data-atlas-state', 'reacted');
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

    function collectLookupKeysForNode(node: Element): string[] {
      const keys = new Set<string>();

      const mediaUrl = (() => {
        if (node instanceof HTMLImageElement) {
          return safeUrl(node.currentSrc) || safeUrl(node.src) || '';
        }
        if (node instanceof HTMLVideoElement) {
          return getVideoUrl(node) || '';
        }
        if (node instanceof HTMLAnchorElement) {
          return safeUrl(node.href) || '';
        }
        return '';
      })();
      if (mediaUrl) {
        keys.add(mediaUrl);
      }

      const anchorHref = node.closest('a[href]')?.getAttribute('href') ?? '';
      const anchorUrl = safeUrl(anchorHref);
      if (anchorUrl) {
        keys.add(anchorUrl);
      }

      const pageUrl = safeUrl(window.location.href);
      if (pageUrl) {
        keys.add(pageUrl);
      }

      return [...keys];
    }

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

          const item = buildItemFromElement(element);
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

  function buildItemFromElement(element) {
    if (!(element instanceof Element)) {
      return null;
    }

    if (element.tagName === 'IMG') {
      const img = element;
      const width = img.naturalWidth || img.width || img.clientWidth || null;
      const height = img.naturalHeight || img.height || img.clientHeight || null;
      if (width && height && (width < MIN_SIZE || height < MIN_SIZE)) {
        return null;
      }

      const rawSrc = (img.currentSrc || img.src || img.getAttribute('src') || '').trim();
      const url = safeUrl(rawSrc);
      if (!url) {
        const fallback = safeUrl(document.referrer) || '';
        if (!fallback || (!rawSrc.toLowerCase().startsWith('blob:') && !rawSrc.toLowerCase().startsWith('data:'))) {
          return null;
        }

        return {
          tag_name: 'img',
          url: fallback,
          original_url: fallback,
          referrer_url: rawSrc,
          preview_url: '',
          width,
          height,
          alt: img.alt || '',
        };
      }

      return {
        tag_name: 'img',
        url,
        original_url: url,
        referrer_url: window.location.href,
        preview_url: url,
        width,
        height,
        alt: img.alt || '',
      };
    }

    if (element.tagName === 'VIDEO') {
      const url = getVideoUrl(element);
      if (!url) {
        const video = element as HTMLVideoElement;
        const rawSrc = (video.currentSrc || video.src || '').trim().toLowerCase();
        if (rawSrc.startsWith('blob:') || rawSrc.startsWith('data:')) {
          const pageUrl = window.location.href;
          return {
            tag_name: 'video',
            url: pageUrl,
            original_url: `${pageUrl}#atlas-ext-video=${Date.now()}-${Math.random().toString(16).slice(2)}`,
            referrer_url: pageUrl,
            preview_url: video.poster || '',
            width: video.videoWidth || video.clientWidth || null,
            height: video.videoHeight || video.clientHeight || null,
            alt: '',
            download_via: 'yt-dlp',
          };
        }
        return null;
      }

      return {
        tag_name: 'video',
        url,
        original_url: url,
        referrer_url: window.location.href,
        preview_url: element.poster || '',
        width: element.videoWidth || element.clientWidth || null,
        height: element.videoHeight || element.clientHeight || null,
        alt: '',
      };
    }

    return null;
  }

  function buildDirectPageCandidate() {
    const locationUrl = (window.location.href || '').trim();
    if (!locationUrl) {
      return null;
    }

    const lowerLocation = locationUrl.toLowerCase();
    const mediaExtMatch = /\.(jpg|jpeg|png|gif|webp|bmp|svg|mp4|webm|mov|m4v|mkv)(\?|#|$)/i.test(
      lowerLocation
    );
    const mimeHint =
      (document.contentType || '').startsWith('image/') || (document.contentType || '').startsWith('video/');

    if (!mediaExtMatch && !mimeHint) {
      return null;
    }

    if (lowerLocation.startsWith('http://') || lowerLocation.startsWith('https://')) {
      return {
        tag_name: lowerLocation.match(/\.(mp4|webm|mov|m4v|mkv)(\?|#|$)/i) ? 'video' : 'img',
        url: locationUrl,
        original_url: locationUrl,
        referrer_url: locationUrl,
        preview_url: locationUrl,
        width: null,
        height: null,
        alt: '',
      };
    }

    if (lowerLocation.startsWith('blob:') || lowerLocation.startsWith('data:')) {
      const fallback = safeUrl(document.referrer) || '';
      if (!fallback) {
        return null;
      }

      return {
        tag_name: 'img',
        url: fallback,
        original_url: fallback,
        referrer_url: locationUrl,
        preview_url: '',
        width: null,
        height: null,
        alt: '',
      };
    }

    return null;
  }

  function getVideoUrl(video) {
    const direct = safeUrl(video.currentSrc) || safeUrl(video.src) || safeUrl(video.getAttribute('src'));
    if (direct) {
      return direct;
    }

    const source = video.querySelector('source[src]');
    const sourceUrl = source ? safeUrl(source.src || source.getAttribute('src')) : '';
    if (sourceUrl) {
      return sourceUrl;
    }

    const dataStoreUrl = resolveDataStoreUrl(video);
    if (dataStoreUrl) {
      return dataStoreUrl;
    }

    return resolveMetaVideoUrl();
  }

  function resolveMetaVideoUrl() {
    const selectors = [
      'meta[property="og:video"]',
      'meta[property="og:video:url"]',
      'meta[property="og:video:secure_url"]',
      'meta[name="twitter:player:stream"]',
      'meta[name="twitter:player:stream:url"]',
    ];

    for (const selector of selectors) {
      const tag = document.querySelector(selector);
      const content = tag?.getAttribute('content');
      const url = safeUrl(content || '');
      if (url) {
        return url;
      }
    }

    return '';
  }

  function resolveDataStoreUrl(element) {
    let node = element;
    let depth = 0;

    while (node && depth < 8) {
      const dataStore = node.getAttribute?.('data-store');
      if (dataStore) {
        const parsed = parseMaybeJson(dataStore);
        const url = findPlayableUrl(parsed, 0);
        if (url) {
          return url;
        }
      }

      node = node.parentElement;
      depth += 1;
    }

    return '';
  }

  function parseMaybeJson(value) {
    if (!value) {
      return null;
    }

    const decoded = decodeHtmlEntities(value);
    try {
      return JSON.parse(decoded);
    } catch {
      return null;
    }
  }

  function decodeHtmlEntities(value) {
    if (!value || typeof value !== 'string') {
      return '';
    }

    return value
      .replace(/&quot;/g, '"')
      .replace(/&#34;/g, '"')
      .replace(/&amp;/g, '&')
      .replace(/&#38;/g, '&')
      .replace(/&lt;/g, '<')
      .replace(/&gt;/g, '>');
  }

  function findPlayableUrl(value, depth) {
    if (!value || depth > 4) {
      return '';
    }

    if (typeof value === 'string') {
      return value.startsWith('http') ? value : '';
    }

    if (Array.isArray(value)) {
      for (const entry of value) {
        const found = findPlayableUrl(entry, depth + 1);
        if (found) {
          return found;
        }
      }
      return '';
    }

    if (typeof value !== 'object') {
      return '';
    }

    const directKeys = [
      'hd_src',
      'sd_src',
      'playable_url',
      'playable_url_quality_hd',
      'playable_url_quality_sd',
    ];

    for (const key of directKeys) {
      const candidate = value[key];
      if (typeof candidate === 'string' && candidate.startsWith('http')) {
        return candidate;
      }
    }

    for (const key of Object.keys(value)) {
      const found = findPlayableUrl(value[key], depth + 1);
      if (found) {
        return found;
      }
    }

    return '';
  }

  function createToastFn(container) {
    return function showToast(message, tone: 'info' | 'danger' = 'info') {
      const toast = document.createElement('div');
      toast.className = `atlas-downloader-toast${tone === 'danger' ? ' danger' : ''}`;
      toast.textContent = message;
      container.appendChild(toast);

      requestAnimationFrame(() => toast.classList.add('show'));

      setTimeout(() => {
        toast.classList.remove('show');
        toast.addEventListener('transitionend', () => toast.remove(), { once: true });
      }, 2600);
    };
  }

  function createDialogChooser(root: HTMLElement) {
    return (options: {
      title: string;
      message: string;
      confirmLabel: string;
      cancelLabel?: string;
      alternateLabel?: string;
      danger?: boolean;
    }): Promise<'confirm' | 'cancel' | 'alternate'> =>
      new Promise((resolve) => {
        const backdrop = document.createElement('div');
        backdrop.className = 'atlas-downloader-dialog-backdrop';

        const panel = document.createElement('div');
        panel.className = `atlas-downloader-dialog${options.danger ? ' danger' : ''}`.trim();
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        panel.setAttribute('aria-label', options.title);

        const titleNode = document.createElement('h3');
        titleNode.className = 'atlas-downloader-dialog-title';
        titleNode.textContent = options.title;

        const messageNode = document.createElement('p');
        messageNode.className = 'atlas-downloader-dialog-message';
        messageNode.textContent = options.message;

        const actions = document.createElement('div');
        actions.className = 'atlas-downloader-dialog-actions';

        const finish = (result: 'confirm' | 'cancel' | 'alternate') => {
          backdrop.remove();
          resolve(result);
        };

        if (options.alternateLabel) {
          const alternate = document.createElement('button');
          alternate.type = 'button';
          alternate.className = 'atlas-downloader-dialog-btn secondary';
          alternate.textContent = options.alternateLabel;
          alternate.addEventListener('click', () => finish('alternate'));
          actions.appendChild(alternate);
        }

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'atlas-downloader-dialog-btn';
        cancel.textContent = options.cancelLabel || 'Cancel';
        cancel.addEventListener('click', () => finish('cancel'));
        actions.appendChild(cancel);

        const confirm = document.createElement('button');
        confirm.type = 'button';
        confirm.className = `atlas-downloader-dialog-btn primary${options.danger ? ' danger' : ''}`.trim();
        confirm.textContent = options.confirmLabel;
        confirm.addEventListener('click', () => finish('confirm'));
        actions.appendChild(confirm);

        panel.appendChild(titleNode);
        panel.appendChild(messageNode);
        panel.appendChild(actions);
        backdrop.appendChild(panel);
        root.appendChild(backdrop);

        requestAnimationFrame(() => {
          confirm.focus();
        });
      });
  }

  function ensurePageMarkerStyles() {
    if (document.getElementById(PAGE_MARKER_STYLE_ID)) {
      return;
    }

    const style = document.createElement('style');
    style.id = PAGE_MARKER_STYLE_ID;
    style.textContent = `
[data-atlas-marked="1"][data-atlas-state="exists"] {
  outline: 2px solid rgba(148, 163, 184, 0.5) !important;
  outline-offset: 2px !important;
}
[data-atlas-marked="1"][data-atlas-state="downloaded"] {
  outline: 2px solid rgba(34, 197, 94, 0.85) !important;
  outline-offset: 2px !important;
}
[data-atlas-marked="1"][data-atlas-state="blacklisted"] {
  outline: 2px solid rgba(239, 68, 68, 0.9) !important;
  outline-offset: 2px !important;
}
[data-atlas-marked="1"][data-atlas-state="reacted"][data-atlas-reaction="love"] {
  outline: 2px solid rgba(239, 68, 68, 0.9) !important;
  outline-offset: 2px !important;
}
[data-atlas-marked="1"][data-atlas-state="reacted"][data-atlas-reaction="like"] {
  outline: 2px solid rgba(56, 189, 248, 0.9) !important;
  outline-offset: 2px !important;
}
[data-atlas-marked="1"][data-atlas-state="reacted"][data-atlas-reaction="funny"] {
  outline: 2px solid rgba(234, 179, 8, 0.95) !important;
  outline-offset: 2px !important;
}
[data-atlas-marked="1"][data-atlas-state="reacted"][data-atlas-reaction="dislike"] {
  outline: 2px solid rgba(71, 85, 105, 0.95) !important;
  outline-offset: 2px !important;
}
`;

    (document.head || document.documentElement).appendChild(style);
  }

  function stripHash(value: string): string {
    const hashPos = value.indexOf('#');
    if (hashPos === -1) {
      return value;
    }
    return value.slice(0, hashPos);
  }

  function parseExcludedDomains(value) {
    if (!value || typeof value !== 'string') {
      return [];
    }

    return value
      .split(/[\n,]/g)
      .map((entry) => entry.trim())
      .filter((entry) => entry && !entry.startsWith('#'))
      .map((entry) => {
        const wildcard = entry.startsWith('*.') ? entry.slice(2) : entry;
        return wildcard.toLowerCase();
      })
      .map((entry) => resolveHost(entry) || entry.replace(/^\.+/, '').trim())
      .filter(Boolean);
  }

  function isHostExcluded(currentHost, excludedHosts) {
    const current = (currentHost || '').toLowerCase();
    if (!current) {
      return false;
    }

    for (const host of excludedHosts) {
      if (isHostMatch(current, host)) {
        return true;
      }
    }

    return false;
  }

  function resolveHost(value) {
    if (!value || typeof value !== 'string') {
      return '';
    }

    const trimmed = value.trim();
    if (!trimmed) {
      return '';
    }

    const withScheme = /^https?:\/\//i.test(trimmed) ? trimmed : `https://${trimmed}`;
    try {
      return new URL(withScheme).hostname;
    } catch {
      return '';
    }
  }

  function isHostMatch(current, base) {
    if (!current || !base) {
      return false;
    }

    return current === base || current.endsWith(`.${base}`);
  }

  function safeUrl(value) {
    if (!value || typeof value !== 'string') {
      return '';
    }

    const trimmed = value.trim();
    if (!trimmed) {
      return '';
    }

    const lowered = trimmed.toLowerCase();
    if (
      lowered.startsWith('blob:') ||
      lowered.startsWith('data:') ||
      lowered.startsWith('chrome-extension:') ||
      lowered.startsWith('moz-extension:') ||
      lowered.startsWith('safari-extension:')
    ) {
      return '';
    }

    return trimmed;
  }

  function installHotkeys(options: {
    showToast: (message: string, tone?: 'info' | 'danger') => void;
    sendMessageSafe: (message: unknown, callback: (response: unknown) => void) => void;
    isSheetOpen: () => boolean;
    chooseDialog: (options: {
      title: string;
      message: string;
      confirmLabel: string;
      cancelLabel?: string;
      alternateLabel?: string;
      danger?: boolean;
    }) => Promise<'confirm' | 'cancel' | 'alternate'>;
    enabled?: boolean;
    setHintShown?: (value: boolean) => void;
    getHintShown?: () => boolean;
  }) {
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
      reactionType: string | null
    ) => {
      window.dispatchEvent(
        new CustomEvent('atlas-shortcut-reaction-state', {
          detail: {
            media,
            pending,
            reactionType,
          },
        })
      );
    };

    const isOwnUiEvent = (event: MouseEvent) => {
      const path = typeof event.composedPath === 'function' ? event.composedPath() : [];
      return path.some((p) => p instanceof HTMLElement && p.id === ROOT_ID);
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

        const target = e.target instanceof Element ? e.target : null;
        const media = target?.closest?.('img, video') ?? null;
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

        const target = e.target instanceof Element ? e.target : null;
        const media = target?.closest?.('img, video') ?? null;
        if (!media) return;

        const reactionType = e.button === 0 ? 'like' : e.button === 1 ? 'love' : null;
        if (!reactionType) return;

        maybeHint();

        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        const item = buildItemFromElement(media);
        if (!item) {
          if (media instanceof HTMLVideoElement) {
            const rawSrc = (media.currentSrc || media.src || '').trim().toLowerCase();
            if (rawSrc.startsWith('blob:') || rawSrc.startsWith('data:')) {
              // Fallback: send the page URL and let Atlas resolve/download via yt-dlp.
              // Make it unique per trigger so multiple videos on the same page don't collide.
              const pageUrl = window.location.href;
              const uniqueUrl = `${pageUrl}#atlas-ext-video=${Date.now()}-${Math.random().toString(16).slice(2)}`;
              const payload = {
                type: reactionType,
                url: pageUrl,
                original_url: uniqueUrl,
                referrer_url: pageUrl,
                page_title: limitString(document.title, MAX_METADATA_LEN),
                tag_name: 'video',
                width: media.videoWidth || media.clientWidth || null,
                height: media.videoHeight || media.clientHeight || null,
                alt: '',
                preview_url: media.poster || '',
                source: sourceFromMediaUrl(pageUrl),
                download_via: 'yt-dlp',
              };

                fetchAtlasStatus(options.sendMessageSafe, payload.url, (status) => {
                  if (status?.downloaded) {
                  options
                    .chooseDialog({
                      title: 'Already downloaded',
                      message: 'Re-download before updating the reaction?',
                      confirmLabel: 'Re-download',
                      cancelLabel: 'Keep existing file',
                    })
                    .then((choice) => {
                      emitShortcutReactionState(media, true, reactionType);
                      if (choice === 'confirm') {
                        payload.force_download = true;
                      }

                      options.sendMessageSafe({ type: 'atlas-react', payload }, (response) => {
                        if (!response || !response.ok) {
                          emitShortcutReactionState(media, false, null);
                          options.showToast(response?.error || 'Reaction failed.', 'danger');
                          return;
                        }

                        const data = response.data || null;
                        const file = data?.file || null;
                        const newReactionType = data?.reaction?.type
                          ? String(data.reaction.type)
                          : reactionType;
                        atlasStatusCache.set(payload.url, {
                          exists: Boolean(file),
                          downloaded: Boolean(file?.downloaded),
                          blacklisted: Boolean(file?.blacklisted_at),
                          reactionType: newReactionType,
                          ts: Date.now(),
                        });
                        emitShortcutReactionState(media, false, newReactionType);

                        options.showToast(`Reacted (${reactionType}). Resolving video in Atlas…`);
                      });
                    });
                  return;
                }
                emitShortcutReactionState(media, true, reactionType);
                options.sendMessageSafe({ type: 'atlas-react', payload }, (response) => {
                  if (!response || !response.ok) {
                    emitShortcutReactionState(media, false, null);
                    options.showToast(response?.error || 'Reaction failed.', 'danger');
                    return;
                  }

                  const data = response.data || null;
                  const file = data?.file || null;
                  const newReactionType = data?.reaction?.type
                    ? String(data.reaction.type)
                    : reactionType;
                  atlasStatusCache.set(payload.url, {
                    exists: Boolean(file),
                    downloaded: Boolean(file?.downloaded),
                    blacklisted: Boolean(file?.blacklisted_at),
                    reactionType: newReactionType,
                    ts: Date.now(),
                  });
                  emitShortcutReactionState(media, false, newReactionType);

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
          original_url: item.url,
          referrer_url: window.location.href,
          page_title: limitString(document.title, MAX_METADATA_LEN),
          tag_name: item.tag_name,
          width: item.width,
          height: item.height,
          alt: limitString(item.alt || '', MAX_METADATA_LEN),
          preview_url: item.preview_url || '',
          source: sourceFromMediaUrl(item.url),
        };

        fetchAtlasStatus(options.sendMessageSafe, payload.url, (status) => {
          if (status?.downloaded) {
            options
              .chooseDialog({
                title: 'Already downloaded',
                message: 'Re-download before updating the reaction?',
                confirmLabel: 'Re-download',
                cancelLabel: 'Keep existing file',
              })
              .then((choice) => {
                emitShortcutReactionState(media, true, reactionType);
                if (choice === 'confirm') {
                  payload.force_download = true;
                }

                options.sendMessageSafe({ type: 'atlas-react', payload }, (response) => {
                  if (!response || !response.ok) {
                    emitShortcutReactionState(media, false, null);
                    options.showToast(response?.error || 'Reaction failed.', 'danger');
                    return;
                  }

                  const data = response.data || null;
                  const file = data?.file || null;
                  const newReactionType = data?.reaction?.type
                    ? String(data.reaction.type)
                    : reactionType;
                  atlasStatusCache.set(payload.url, {
                    exists: Boolean(file),
                    downloaded: Boolean(file?.downloaded),
                    blacklisted: Boolean(file?.blacklisted_at),
                    reactionType: newReactionType,
                    ts: Date.now(),
                  });
                  emitShortcutReactionState(media, false, newReactionType);

                  options.showToast(`Reacted (${reactionType}). Queued download in Atlas.`);
                });
              });
            return;
          }
          emitShortcutReactionState(media, true, reactionType);
          options.sendMessageSafe({ type: 'atlas-react', payload }, (response) => {
            if (!response || !response.ok) {
              emitShortcutReactionState(media, false, null);
              options.showToast(response?.error || 'Reaction failed.', 'danger');
              return;
            }

            const data = response.data || null;
            const file = data?.file || null;
            const newReactionType = data?.reaction?.type
              ? String(data.reaction.type)
              : reactionType;
            atlasStatusCache.set(payload.url, {
              exists: Boolean(file),
              downloaded: Boolean(file?.downloaded),
              blacklisted: Boolean(file?.blacklisted_at),
              reactionType: newReactionType,
              ts: Date.now(),
            });
            emitShortcutReactionState(media, false, newReactionType);

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

        const target = e.target instanceof Element ? e.target : null;
        const media = target?.closest?.('img, video') ?? null;
        if (!media) return;

        maybeHint();

        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        const item = buildItemFromElement(media);
        if (!item) {
          if (media instanceof HTMLVideoElement) {
            const rawSrc = (media.currentSrc || media.src || '').trim().toLowerCase();
            if (rawSrc.startsWith('blob:') || rawSrc.startsWith('data:')) {
              const pageUrl = window.location.href;
              const uniqueUrl = `${pageUrl}#atlas-ext-video=${Date.now()}-${Math.random().toString(16).slice(2)}`;
              const payload = {
                type: 'dislike',
                url: pageUrl,
                original_url: uniqueUrl,
                referrer_url: pageUrl,
                page_title: limitString(document.title, MAX_METADATA_LEN),
                tag_name: 'video',
                width: media.videoWidth || media.clientWidth || null,
                height: media.videoHeight || media.clientHeight || null,
                alt: '',
                preview_url: media.poster || '',
                source: sourceFromMediaUrl(pageUrl),
                download_via: 'yt-dlp',
              };

	              emitShortcutReactionState(media, true, 'dislike');
	              options.sendMessageSafe({ type: 'atlas-react', payload }, (response) => {
	                if (!response || !response.ok) {
	                  emitShortcutReactionState(media, false, null);
	                  options.showToast(response?.error || 'Reaction failed.', 'danger');
	                  return;
	                }
	                emitShortcutReactionState(media, false, 'dislike');
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
          original_url: item.url,
          referrer_url: window.location.href,
          page_title: limitString(document.title, MAX_METADATA_LEN),
          tag_name: item.tag_name,
          width: item.width,
          height: item.height,
          alt: limitString(item.alt || '', MAX_METADATA_LEN),
          preview_url: item.preview_url || '',
          source: sourceFromMediaUrl(item.url),
        };

	        emitShortcutReactionState(media, true, 'dislike');
	        options.sendMessageSafe({ type: 'atlas-react', payload }, (response) => {
	          if (!response || !response.ok) {
	            emitShortcutReactionState(media, false, null);
	            options.showToast(response?.error || 'Reaction failed.', 'danger');
	            return;
	          }
	          emitShortcutReactionState(media, false, 'dislike');
	          options.showToast('Disliked.');
	        });
      },
      true
    );
  }

  function clamp(value: number, min: number, max: number) {
    if (value < min) return min;
    if (value > max) return max;
    return value;
  }

  function buildOverlayReactionPayload(media: Element, reactionType: string) {
    const item = buildItemFromElement(media);
    if (item) {
      return {
        type: reactionType,
        url: item.url,
        original_url: item.url,
        referrer_url: window.location.href,
        page_title: limitString(document.title, MAX_METADATA_LEN),
        tag_name: item.tag_name,
        width: item.width,
        height: item.height,
        alt: limitString(item.alt || '', MAX_METADATA_LEN),
        preview_url: item.preview_url || '',
        source: sourceFromMediaUrl(item.url),
      };
    }

    if (media instanceof HTMLVideoElement) {
      const rawSrc = (media.currentSrc || media.src || '').trim().toLowerCase();
      if (rawSrc.startsWith('blob:') || rawSrc.startsWith('data:')) {
        const pageUrl = window.location.href;
        const uniqueUrl = `${pageUrl}#atlas-ext-video=${Date.now()}-${Math.random()
          .toString(16)
          .slice(2)}`;

        return {
          type: reactionType,
          url: pageUrl,
          original_url: uniqueUrl,
          referrer_url: pageUrl,
          page_title: limitString(document.title, MAX_METADATA_LEN),
          tag_name: 'video',
          width: media.videoWidth || media.clientWidth || null,
          height: media.videoHeight || media.clientHeight || null,
          alt: '',
          preview_url: media.poster || '',
          source: sourceFromMediaUrl(pageUrl),
          download_via: 'yt-dlp',
        };
      }
    }

    return null;
  }

  function installMediaReactionOverlay(options: {
    root: HTMLElement;
    showToast: (message: string, tone?: 'info' | 'danger') => void;
    sendMessageSafe: (message: unknown, callback: (response: unknown) => void) => void;
    isSheetOpen: () => boolean;
    chooseDialog: (options: {
      title: string;
      message: string;
      confirmLabel: string;
      cancelLabel?: string;
      alternateLabel?: string;
      danger?: boolean;
    }) => Promise<'confirm' | 'cancel' | 'alternate'>;
  }) {
    const toolbar = document.createElement('div');
    toolbar.className = 'atlas-downloader-media-toolbar';
    toolbar.setAttribute('role', 'toolbar');
    toolbar.setAttribute('aria-label', 'Atlas reactions');

    let activeMedia: Element | null = null;
    let activeKey: string | null = null;
    let hideTimer: number | null = null;
    let toolbarBusy = false;
    let pointerX = -1;
    let pointerY = -1;
    let hoverDetectTimer: number | null = null;

    const buttonsByType = new Map<string, HTMLButtonElement>();
    const setToolbarBusy = (busy: boolean, pendingType: string | null = null) => {
      toolbarBusy = busy;
      for (const [type, button] of buttonsByType.entries()) {
        button.disabled = busy;
        button.classList.toggle('pending', busy && pendingType === type);
      }
    };
    const setToolbarActive = (reactionType: string | null) => {
      for (const reaction of [...REACTIONS, BLACKLIST_ACTION]) {
        const btn = buttonsByType.get(reaction.type);
        if (!btn) continue;
        const isActive = reaction.type === BLACKLIST_ACTION.type
          ? reactionType === 'dislike'
          : reactionType === reaction.type;
        btn.classList.toggle('active', isActive);
      }
    };

    const cancelHide = () => {
      if (hideTimer) {
        window.clearTimeout(hideTimer);
        hideTimer = null;
      }
    };

    const hide = () => {
      if (toolbarBusy) {
        return;
      }
      activeMedia = null;
      activeKey = null;
      toolbar.classList.remove('open');
      toolbar.style.left = '';
      toolbar.style.top = '';
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
        const payload = buildOverlayReactionPayload(activeMedia, reactionType);
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
                options.showToast(response?.error || 'Reaction failed.', 'danger');
                return;
              }

              const data = response.data || null;
              const file = data?.file || null;
              const newReactionType =
                data?.reaction?.type
                  ? String(data.reaction.type)
                  : reaction.type === BLACKLIST_ACTION.type
                    ? 'dislike'
                    : reaction.type;

              if (checkKey) {
                atlasStatusCache.set(checkKey, {
                  exists: Boolean(file),
                  downloaded: Boolean(file?.downloaded),
                  blacklisted: Boolean(file?.blacklisted_at),
                  reactionType: newReactionType,
                  ts: Date.now(),
                });
              }

              setToolbarActive(newReactionType);

              if (payload.download_via === 'yt-dlp') {
                options.showToast(`Reacted (${reaction.label}). Resolving video in Atlas…`);
                return;
              }

              options.showToast(`Reacted (${reaction.label}). Queued download in Atlas.`);
            }
          );
        };

        fetchAtlasStatus(options.sendMessageSafe, checkKey, (status) => {
          if (reactionType !== 'dislike' && status?.downloaded) {
            options
              .chooseDialog({
                title: 'Already downloaded',
                message: 'Re-download before updating the reaction?',
                confirmLabel: 'Re-download',
                cancelLabel: 'Keep existing file',
              })
              .then((choice) => {
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
      return composedPath.some((p) => p instanceof HTMLElement && p.id === ROOT_ID);
    };

    const updatePosition = () => {
      if (!activeMedia) return;

      const rect = activeMedia.getBoundingClientRect();
      if (!Number.isFinite(rect.left) || rect.width <= 0 || rect.height <= 0) {
        hide();
        return;
      }

      const top = clamp(rect.top + 8, 8, window.innerHeight - 8);
      const left = clamp(rect.right - 8, 8, window.innerWidth - 8);
      toolbar.style.top = `${top}px`;
      toolbar.style.left = `${left}px`;
    };

    const showFor = (media: Element) => {
      if (options.isSheetOpen()) {
        hide();
        return;
      }

      // Validate this media has a usable URL (or is a supported video fallback) before showing.
      const previewPayload = buildOverlayReactionPayload(media, 'like');
      if (!previewPayload) {
        hide();
        return;
      }

      activeMedia = media;
      activeKey = previewPayload.url || null;
      toolbar.classList.add('open');
      updatePosition();

      setToolbarActive(null);
      if (activeKey) {
        const cached = getCachedAtlasStatus(activeKey);
        if (cached) {
          setToolbarActive(cached.reactionType);
        } else {
          const keyAtRequest = activeKey;
          fetchAtlasStatus(options.sendMessageSafe, keyAtRequest, (status) => {
            if (!status) return;
            if (activeKey !== keyAtRequest) return;
            setToolbarActive(status.reactionType);
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
      const underPointer = document.elementFromPoint(pointerX, pointerY);
      if (!(underPointer instanceof Element)) {
        return;
      }
      if (underPointer.closest?.(`#${ROOT_ID}`)) {
        return;
      }
      const media = underPointer.closest?.('img, video') ?? null;
      if (!media) {
        return;
      }
      cancelHide();
      showFor(media);
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

        const media = event.target.closest?.('img, video') ?? null;
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
          setToolbarActive(reactionType === 'blacklist' ? 'dislike' : reactionType);
        }
      },
      true
    );
    document.addEventListener(
      'pointermove',
      (event) => {
        pointerX = event.clientX;
        pointerY = event.clientY;
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
    window.addEventListener('blur', hide);
    window.addEventListener('focus', () => scheduleDetectMediaUnderPointer(40), true);
    const observer = new MutationObserver(() => {
      scheduleDetectMediaUnderPointer(60);
    });
    observer.observe(document.documentElement, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['src', 'srcset', 'style', 'class'],
    });
  }
})();
