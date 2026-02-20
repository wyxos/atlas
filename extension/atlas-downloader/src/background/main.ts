import Pusher from 'pusher-js/worker';

type AtlasSettings = {
  atlasBaseUrl?: string;
  atlasToken?: string;
  atlasExcludedDomains?: string;
};

type ChromeRuntime = {
  onMessage: {
    addListener: (
      callback: (
        message: unknown,
        sender: unknown,
        sendResponse: (response: unknown) => void
      ) => void | boolean
    ) => void;
  };
  openOptionsPage: () => void;
  onInstalled: {
    addListener: (callback: () => void) => void;
  };
  onStartup: {
    addListener: (callback: () => void) => void;
  };
  reload: () => void;
  requestUpdateCheck?: (callback: (status: string) => void) => void;
  getURL: (path: string) => string;
  sendMessage: (message: unknown) => void;
};

type ChromeStorageSync = {
  get: (keys: string[]) => Promise<AtlasSettings>;
  set: (items: Partial<AtlasSettings>) => Promise<void>;
};

type ChromeStorageChangedValue = {
  oldValue?: unknown;
  newValue?: unknown;
};

type ChromeStorageArea = {
  onChanged: {
    addListener: (
      callback: (
        changes: Record<string, ChromeStorageChangedValue>,
        areaName: string
      ) => void
    ) => void;
  };
};

type ChromeTab = {
  id?: number;
  url?: string;
  active?: boolean;
};

type ChromeTabs = {
  sendMessage: (tabId: number, message: unknown) => void;
  create: (createProperties: { url: string }) => void;
  get: (tabId: number) => Promise<ChromeTab>;
  query: (queryInfo: { active?: boolean; lastFocusedWindow?: boolean }) => Promise<ChromeTab[]>;
  onActivated: {
    addListener: (callback: (activeInfo: { tabId: number }) => void) => void;
  };
  onUpdated: {
    addListener: (
      callback: (
        tabId: number,
        changeInfo: { status?: string; url?: string },
        tab: ChromeTab
      ) => void
    ) => void;
  };
};

type ChromeAction = {
  onClicked: {
    addListener: (callback: (tab: ChromeTab | undefined) => void) => void;
  };
  setIcon: (details: {
    tabId?: number;
    path?: Record<string | number, string>;
    imageData?: Record<string | number, ImageData>;
  }) => void;
  setBadgeText: (details: { tabId?: number; text: string }) => void;
  setBadgeBackgroundColor: (details: { tabId?: number; color: string }) => void;
  setTitle: (details: { tabId?: number; title: string }) => void;
};

type ChromeContextMenus = {
  create: (createProperties: {
    id: string;
    title: string;
    contexts: string[];
  }) => void;
  onClicked: {
    addListener: (
      callback: (info: { menuItemId: string | number }, tab?: ChromeTab) => void
    ) => void;
  };
};

type ChromeApi = {
  runtime: ChromeRuntime;
  storage: {
    sync: ChromeStorageSync;
    onChanged: ChromeStorageArea['onChanged'];
  };
  tabs: ChromeTabs;
  action: ChromeAction;
  contextMenus: ChromeContextMenus;
};

declare const chrome: ChromeApi;

const SETTINGS_KEYS = ['atlasBaseUrl', 'atlasToken'];
const REQUEST_TIMEOUT_MS = 25_000;
const DEFAULT_ACTION_TITLE = 'Atlas Downloader';
const DEFAULT_ICON_PATHS: Record<number, string> = {
  16: 'icon-16.png',
  32: 'icon-32.png',
  48: 'icon-48.png',
};
const RED_TINT = 'rgba(220, 38, 38, 0.78)';

const MENU_OPEN_OPTIONS = 'atlas-open-options';
const MENU_OPEN_SITE = 'atlas-open-site';
const MENU_BLACKLIST_DOMAIN = 'atlas-blacklist-domain';
const MENU_RELOAD_EXTENSION = 'atlas-reload-extension';
const MESSAGE_REALTIME_STATUS_REQUEST = 'atlas-realtime-status-request';
const MESSAGE_REALTIME_STATUS_CHANGED = 'atlas-realtime-status-changed';
const SOCKET_EVENT_NAMES = [
  'DownloadTransferCreated',
  'DownloadTransferQueued',
  'DownloadTransferProgressUpdated',
] as const;
const SOCKET_META_EVENT_NAMES = [
  'pusher:subscription_succeeded',
  'pusher:subscription_error',
] as const;

type RealtimeConnectionState =
  | 'not-configured'
  | 'loading'
  | 'connecting'
  | 'connected'
  | 'reconnecting'
  | 'error';

type RealtimeConfig = {
  key: string;
  wsHost: string;
  wsPort: number;
  wssPort: number;
  forceTLS: boolean;
  enabledTransports: string[];
  authEndpoint: string;
  channel: string;
};

type DownloadRealtimeEvent = {
  event: string;
  transferId: number | null;
  status: string | null;
  percent: number | null;
  original: string | null;
  referrer_url: string | null;
  finished_at: string | null;
  failed_at: string | null;
  downloaded: boolean;
  failed: boolean;
};

type RealtimeConnectionStatus = {
  state: RealtimeConnectionState;
  message: string;
  channel: string | null;
  host: string | null;
  updatedAt: number;
};

let excludedIconImageDataPromise: Promise<Record<number, ImageData> | null> | null = null;
let realtimeClient: Pusher | null = null;
let realtimeChannel: Pusher.Channel | null = null;
let realtimeConfigSignature = '';
let realtimeReconnectTimer: ReturnType<typeof setTimeout> | null = null;
let realtimeReconnectAttempts = 0;
let realtimeStatus: RealtimeConnectionStatus = {
  state: 'not-configured',
  message: 'Set Atlas base URL and extension token to enable realtime updates.',
  channel: null,
  host: null,
  updatedAt: Date.now(),
};

chrome.runtime.onInstalled.addListener(() => {
  // Right click on the extension toolbar icon shows this menu (in addition to Chrome's built-ins).
  try {
    chrome.contextMenus.create({
      id: MENU_OPEN_OPTIONS,
      title: 'Options',
      contexts: ['action'],
    });
    chrome.contextMenus.create({
      id: MENU_OPEN_SITE,
      title: 'Open Atlas',
      contexts: ['action'],
    });
    chrome.contextMenus.create({
      id: MENU_BLACKLIST_DOMAIN,
      title: 'Blacklist this domain',
      contexts: ['action'],
    });
    chrome.contextMenus.create({
      id: MENU_RELOAD_EXTENSION,
      title: 'Reload extension (check updates)',
      contexts: ['action'],
    });
  } catch {
    // Some Chromium builds may not support the "action" context; failing silently is fine.
  }

  void refreshActionForActiveTab();
  void ensureRealtimeConnection(true);
});

chrome.runtime.onStartup.addListener(() => {
  void refreshActionForActiveTab();
  void ensureRealtimeConnection(true);
});

chrome.contextMenus.onClicked.addListener((info, tab) => {
  if (info.menuItemId === MENU_OPEN_OPTIONS) {
    chrome.runtime.openOptionsPage();
    return;
  }

  if (info.menuItemId === MENU_OPEN_SITE) {
    chrome.storage.sync.get(['atlasBaseUrl']).then((settings) => {
      const baseUrl = normalizeBaseUrl(settings.atlasBaseUrl || '');
      if (!baseUrl) {
        chrome.runtime.openOptionsPage();
        return;
      }

      // Opening a new tab doesn't require tab permission.
      chrome.tabs.create({ url: baseUrl });
    });
    return;
  }

  if (info.menuItemId === MENU_BLACKLIST_DOMAIN) {
    void blacklistDomainFromTab(tab);
    return;
  }

  if (info.menuItemId === MENU_RELOAD_EXTENSION) {
    reloadExtension();
  }
});

chrome.action.onClicked.addListener((tab) => {
  const tabId = tab?.id;
  if (!tabId) {
    return;
  }

  // Left click on the toolbar icon asks the content script to open the sheet.
  chrome.tabs.sendMessage(tabId, { type: 'atlas-open-sheet' });
});

chrome.tabs.onActivated.addListener((activeInfo) => {
  void refreshActionForTabId(activeInfo.tabId);
});

chrome.tabs.onUpdated.addListener((tabId, changeInfo, tab) => {
  if (!tab?.active && typeof changeInfo.url !== 'string' && changeInfo.status !== 'complete') {
    return;
  }

  void refreshActionForTab(tabId, tab?.url || changeInfo.url || '');
});

chrome.storage.onChanged.addListener((changes, areaName) => {
  if (areaName !== 'sync') {
    return;
  }

  if (
    Object.prototype.hasOwnProperty.call(changes, 'atlasBaseUrl')
    || Object.prototype.hasOwnProperty.call(changes, 'atlasToken')
  ) {
    void ensureRealtimeConnection(true);
  }

  if (!Object.prototype.hasOwnProperty.call(changes, 'atlasExcludedDomains')) {
    return;
  }

  void refreshActionForActiveTab();
});

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  const messageType = resolveMessageType(message);
  if (messageType === MESSAGE_REALTIME_STATUS_REQUEST) {
    sendResponse({
      ok: true,
      status: realtimeStatus,
    });
    return;
  }

  const request = message as {
    payload?: unknown;
    payloads?: unknown;
    type?: string;
    urls?: unknown;
  };

  if (
    !request
    || (request.type !== 'atlas-download' &&
      request.type !== 'atlas-download-batch' &&
      request.type !== 'atlas-check-batch' &&
      request.type !== 'atlas-react' &&
      request.type !== 'atlas-delete-download')
  ) {
    return;
  }

  const promise =
    request.type === 'atlas-download-batch'
      ? handleDownloadBatch(request.payloads)
      : request.type === 'atlas-check-batch'
        ? handleCheckBatch(request.urls)
        : request.type === 'atlas-delete-download'
          ? handleDeleteDownload(request.payload)
          : request.type === 'atlas-react'
            ? handleReact(request.payload)
            : handleDownload(request.payload);

  promise
    .then((result) => sendResponse(result))
    .catch((error) => {
      sendResponse({
        ok: false,
        error: error && error.message ? error.message : 'Unexpected error',
      });
    });

  return true;
});

void refreshActionForActiveTab();
void ensureRealtimeConnection();

async function blacklistDomainFromTab(tab?: ChromeTab) {
  const tabId = tab?.id;
  const tabUrl = typeof tab?.url === 'string' ? tab.url : '';
  const host = resolveHost(tabUrl);
  if (!host) {
    return;
  }

  const settings = await chrome.storage.sync.get(['atlasExcludedDomains']);
  const excludedHosts = parseExcludedDomains(settings.atlasExcludedDomains || '');
  if (!isHostExcluded(host, excludedHosts)) {
    excludedHosts.push(host);
    const next = [...new Set(excludedHosts)].sort((a, b) => a.localeCompare(b));
    await chrome.storage.sync.set({ atlasExcludedDomains: next.join('\n') });
  }

  if (tabId) {
    await refreshActionForTab(tabId, tabUrl);
  }
}

function reloadExtension() {
  let didReload = false;

  const runReload = () => {
    if (didReload) {
      return;
    }

    didReload = true;
    chrome.runtime.reload();
  };

  try {
    if (typeof chrome.runtime.requestUpdateCheck === 'function') {
      chrome.runtime.requestUpdateCheck(() => {
        runReload();
      });

      setTimeout(runReload, 1500);
      return;
    }
  } catch {
    // Fall through to direct reload.
  }

  runReload();
}

async function refreshActionForActiveTab() {
  try {
    const tabs = await chrome.tabs.query({ active: true, lastFocusedWindow: true });
    const activeTab = tabs[0];
    if (!activeTab?.id) {
      return;
    }

    await refreshActionForTab(activeTab.id, activeTab.url || '');
  } catch {
    // Ignore transient tab/query failures.
  }
}

async function refreshActionForTabId(tabId: number) {
  try {
    const tab = await chrome.tabs.get(tabId);
    await refreshActionForTab(tabId, tab?.url || '');
  } catch {
    // Ignore tabs that disappear mid-refresh.
  }
}

async function refreshActionForTab(tabId: number, tabUrl: string) {
  const host = resolveHost(tabUrl);
  if (!host) {
    setActionDefault(tabId);
    return;
  }

  let isExcluded = false;
  try {
    const settings = await chrome.storage.sync.get(['atlasExcludedDomains']);
    const excludedHosts = parseExcludedDomains(settings.atlasExcludedDomains || '');
    isExcluded = isHostExcluded(host, excludedHosts);
  } catch {
    isExcluded = false;
  }

  if (isExcluded) {
    await setActionExcluded(tabId);
    return;
  }

  setActionDefault(tabId);
}

function setActionDefault(tabId: number) {
  chrome.action.setIcon({ tabId, path: DEFAULT_ICON_PATHS });
  chrome.action.setBadgeText({ tabId, text: '' });
  chrome.action.setTitle({ tabId, title: DEFAULT_ACTION_TITLE });
}

async function setActionExcluded(tabId: number) {
  const redIcon = await getExcludedIconImageData();
  if (redIcon) {
    chrome.action.setIcon({
      tabId,
      imageData: {
        16: redIcon[16],
        32: redIcon[32],
        48: redIcon[48],
      },
    });
    chrome.action.setBadgeText({ tabId, text: '' });
  } else {
    // Fallback for environments that don't support dynamic icon imageData.
    chrome.action.setIcon({ tabId, path: DEFAULT_ICON_PATHS });
    chrome.action.setBadgeBackgroundColor({ tabId, color: '#dc2626' });
    chrome.action.setBadgeText({ tabId, text: 'OFF' });
  }

  chrome.action.setTitle({ tabId, title: 'Atlas Downloader (domain excluded)' });
}

async function getExcludedIconImageData(): Promise<Record<number, ImageData> | null> {
  if (excludedIconImageDataPromise) {
    return excludedIconImageDataPromise;
  }

  excludedIconImageDataPromise = buildExcludedIconImageData();
  return excludedIconImageDataPromise;
}

async function buildExcludedIconImageData(): Promise<Record<number, ImageData> | null> {
  if (typeof OffscreenCanvas === 'undefined' || typeof createImageBitmap !== 'function') {
    return null;
  }

  try {
    const result: Record<number, ImageData> = {};
    for (const size of [16, 32, 48]) {
      const iconPath = DEFAULT_ICON_PATHS[size];
      const response = await fetch(chrome.runtime.getURL(iconPath));
      if (!response.ok) {
        return null;
      }

      const blob = await response.blob();
      const bitmap = await createImageBitmap(blob);
      const canvas = new OffscreenCanvas(size, size);
      const ctx = canvas.getContext('2d');
      if (!ctx) {
        bitmap.close?.();
        return null;
      }

      ctx.clearRect(0, 0, size, size);
      ctx.drawImage(bitmap, 0, 0, size, size);
      bitmap.close?.();
      ctx.globalCompositeOperation = 'source-atop';
      ctx.fillStyle = RED_TINT;
      ctx.fillRect(0, 0, size, size);
      ctx.globalCompositeOperation = 'source-over';
      result[size] = ctx.getImageData(0, 0, size, size);
    }

    return result;
  } catch {
    return null;
  }
}

function parseExcludedDomains(value: string): string[] {
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

function resolveHost(value: string): string {
  if (!value || typeof value !== 'string') {
    return '';
  }

  const trimmed = value.trim();
  if (!trimmed) {
    return '';
  }

  const withScheme = /^https?:\/\//i.test(trimmed) ? trimmed : `https://${trimmed}`;
  try {
    return new URL(withScheme).hostname.toLowerCase();
  } catch {
    return '';
  }
}

function isHostExcluded(currentHost: string, excludedHosts: string[]): boolean {
  const current = (currentHost || '').toLowerCase();
  if (!current) {
    return false;
  }

  for (const host of excludedHosts) {
    if (!host) {
      continue;
    }

    if (current === host || current.endsWith(`.${host}`)) {
      return true;
    }
  }

  return false;
}

async function ensureRealtimeConnection(forceRestart = false) {
  const settings = await chrome.storage.sync.get(SETTINGS_KEYS);
  const baseUrl = normalizeBaseUrl(settings.atlasBaseUrl || '');
  const token = (settings.atlasToken || '').trim();

  if (!baseUrl || !token) {
    teardownRealtimeConnection();
    updateRealtimeStatus({
      state: 'not-configured',
      message: 'Set Atlas base URL and extension token to enable realtime updates.',
      channel: null,
      host: null,
    });
    return;
  }

  const baseHost = resolveHost(baseUrl);
  updateRealtimeStatus({
    state: 'loading',
    message: 'Loading realtime configuration from Atlas.',
    channel: null,
    host: baseHost,
  });

  const realtimeConfig = await fetchRealtimeConfig(baseUrl, token);
  if (!realtimeConfig) {
    scheduleRealtimeReconnect('Could not load realtime configuration from Atlas.', {
      channel: null,
      host: baseHost,
    });
    return;
  }

  const signature = JSON.stringify({
    baseUrl,
    token,
    key: realtimeConfig.key,
    wsHost: realtimeConfig.wsHost,
    wsPort: realtimeConfig.wsPort,
    wssPort: realtimeConfig.wssPort,
    forceTLS: realtimeConfig.forceTLS,
    authEndpoint: realtimeConfig.authEndpoint,
    channel: realtimeConfig.channel,
  });

  if (!forceRestart && realtimeClient && realtimeConfigSignature === signature) {
    return;
  }

  teardownRealtimeConnection();
  realtimeConfigSignature = signature;
  updateRealtimeStatus({
    state: 'connecting',
    message: `Connecting to channel ${realtimeConfig.channel}.`,
    channel: realtimeConfig.channel,
    host: realtimeConfig.wsHost,
  });

  const client = new Pusher(realtimeConfig.key, {
    wsHost: realtimeConfig.wsHost,
    wsPort: realtimeConfig.wsPort,
    wssPort: realtimeConfig.wssPort,
    forceTLS: realtimeConfig.forceTLS,
    enabledTransports: (realtimeConfig.enabledTransports || ['ws', 'wss']) as ('ws' | 'wss')[],
    authEndpoint: realtimeConfig.authEndpoint,
    auth: {
      headers: {
        'X-Atlas-Extension-Token': token,
      },
    },
    cluster: 'mt1',
  });

  client.connection.bind('connected', () => {
    realtimeReconnectAttempts = 0;
    if (realtimeReconnectTimer) {
      clearTimeout(realtimeReconnectTimer);
      realtimeReconnectTimer = null;
    }
    updateRealtimeStatus({
      state: 'connected',
      message: `Connected to channel ${realtimeConfig.channel}.`,
      channel: realtimeConfig.channel,
      host: realtimeConfig.wsHost,
    });
  });

  client.connection.bind('disconnected', () => {
    scheduleRealtimeReconnect('Realtime connection lost.', {
      channel: realtimeConfig.channel,
      host: realtimeConfig.wsHost,
    });
  });

  client.connection.bind('error', () => {
    scheduleRealtimeReconnect('Realtime connection error.', {
      channel: realtimeConfig.channel,
      host: realtimeConfig.wsHost,
    });
  });

  const channel = client.subscribe(realtimeConfig.channel);
  channel.bind('pusher:subscription_succeeded', () => {
    updateRealtimeStatus({
      state: 'connected',
      message: `Subscribed to ${realtimeConfig.channel}.`,
      channel: realtimeConfig.channel,
      host: realtimeConfig.wsHost,
    });
  });
  channel.bind('pusher:subscription_error', () => {
    scheduleRealtimeReconnect('Realtime channel subscription failed.', {
      channel: realtimeConfig.channel,
      host: realtimeConfig.wsHost,
    });
  });
  for (const eventName of SOCKET_EVENT_NAMES) {
    channel.bind(eventName, (payload: unknown) => {
      const normalized = normalizeRealtimeEvent(eventName, payload);
      if (!normalized) {
        return;
      }

      void broadcastDownloadEvent(normalized);
    });
  }

  realtimeClient = client;
  realtimeChannel = channel;
}

function teardownRealtimeConnection() {
  if (realtimeReconnectTimer) {
    clearTimeout(realtimeReconnectTimer);
    realtimeReconnectTimer = null;
  }

  if (realtimeChannel) {
    for (const eventName of [...SOCKET_EVENT_NAMES, ...SOCKET_META_EVENT_NAMES]) {
      realtimeChannel.unbind(eventName);
    }
  }

  if (realtimeClient) {
    realtimeClient.connection.unbind('connected');
    realtimeClient.connection.unbind('disconnected');
    realtimeClient.connection.unbind('error');
    realtimeClient.disconnect();
  }

  realtimeClient = null;
  realtimeChannel = null;
  realtimeConfigSignature = '';
}

function scheduleRealtimeReconnect(
  reason = 'Realtime connection unavailable.',
  context: { channel: string | null; host: string | null } = {
    channel: realtimeStatus.channel,
    host: realtimeStatus.host,
  },
) {
  if (realtimeReconnectTimer) {
    return;
  }

  const exponent = Math.min(realtimeReconnectAttempts, 5);
  const delayMs = Math.min(30_000, 1_000 * 2 ** exponent);
  realtimeReconnectAttempts += 1;
  updateRealtimeStatus({
    state: 'reconnecting',
    message: `${reason} Retrying in ${Math.round(delayMs / 1000)}s.`,
    channel: context.channel,
    host: context.host,
  });
  realtimeReconnectTimer = setTimeout(() => {
    realtimeReconnectTimer = null;
    void ensureRealtimeConnection(true);
  }, delayMs);
}

function resolveMessageType(message: unknown): string | null {
  if (!message || typeof message !== 'object') {
    return null;
  }

  const type = (message as { type?: unknown }).type;
  return typeof type === 'string' ? type : null;
}

function updateRealtimeStatus(
  next: Omit<RealtimeConnectionStatus, 'updatedAt'>,
): void {
  const changed =
    realtimeStatus.state !== next.state
    || realtimeStatus.message !== next.message
    || realtimeStatus.channel !== next.channel
    || realtimeStatus.host !== next.host;

  realtimeStatus = {
    ...next,
    updatedAt: Date.now(),
  };

  if (!changed) {
    return;
  }

  try {
    chrome.runtime.sendMessage({
      type: MESSAGE_REALTIME_STATUS_CHANGED,
      status: realtimeStatus,
    });
  } catch {
    // Ignore when no options page is listening.
  }
}

async function fetchRealtimeConfig(baseUrl: string, token: string): Promise<RealtimeConfig | null> {
  let response: Response;
  try {
    response = await fetchWithTimeout(`${baseUrl}/api/extension/realtime`, {
      method: 'GET',
      headers: {
        Accept: 'application/json',
        ...(token ? { 'X-Atlas-Extension-Token': token } : {}),
      },
    });
  } catch {
    return null;
  }

  const data = await safeJson(response);
  if (!response.ok || !data || typeof data !== 'object') {
    return null;
  }

  const config = data as Partial<RealtimeConfig>;
  if (
    typeof config.key !== 'string'
    || typeof config.wsHost !== 'string'
    || typeof config.wsPort !== 'number'
    || typeof config.wssPort !== 'number'
    || typeof config.forceTLS !== 'boolean'
    || !Array.isArray(config.enabledTransports)
    || typeof config.authEndpoint !== 'string'
    || typeof config.channel !== 'string'
  ) {
    return null;
  }

  return {
    key: config.key,
    wsHost: config.wsHost,
    wsPort: config.wsPort,
    wssPort: config.wssPort,
    forceTLS: config.forceTLS,
    enabledTransports: config.enabledTransports.filter((value): value is string => typeof value === 'string'),
    authEndpoint: config.authEndpoint,
    channel: config.channel,
  };
}

function normalizeRealtimeEvent(eventName: string, payload: unknown): DownloadRealtimeEvent | null {
  if (!payload || typeof payload !== 'object') {
    return null;
  }

  const value = payload as Record<string, unknown>;
  const transferIdCandidate = value.downloadTransferId ?? value.id;
  const transferId = typeof transferIdCandidate === 'number'
    ? transferIdCandidate
    : Number.isFinite(Number(transferIdCandidate))
      ? Number(transferIdCandidate)
      : null;

  const status = typeof value.status === 'string' ? value.status : null;
  const percentCandidate = value.percent;
  const percent = typeof percentCandidate === 'number'
    ? percentCandidate
    : Number.isFinite(Number(percentCandidate))
      ? Number(percentCandidate)
      : null;
  const original = typeof value.original === 'string'
    ? value.original
    : typeof value.url === 'string'
      ? value.url
      : null;
  const referrerUrl = typeof value.referrer_url === 'string' ? value.referrer_url : null;
  const finishedAt = typeof value.finished_at === 'string' ? value.finished_at : null;
  const failedAt = typeof value.failed_at === 'string' ? value.failed_at : null;

  const downloaded = status === 'completed' || Boolean(finishedAt) || percent === 100;
  const failed = status === 'failed' || status === 'canceled' || Boolean(failedAt);
  if (!transferId && !original && !referrerUrl) {
    return null;
  }

  return {
    event: eventName,
    transferId,
    status,
    percent,
    original,
    referrer_url: referrerUrl,
    finished_at: finishedAt,
    failed_at: failedAt,
    downloaded,
    failed,
  };
}

async function broadcastDownloadEvent(payload: DownloadRealtimeEvent): Promise<void> {
  let tabs: ChromeTab[] = [];
  try {
    tabs = await chrome.tabs.query({});
  } catch {
    return;
  }

  for (const tab of tabs) {
    if (!tab?.id) {
      continue;
    }

    try {
      chrome.tabs.sendMessage(tab.id, {
        type: 'atlas-download-event',
        payload,
      });
    } catch {
      // Ignore tabs without a matching content script.
    }
  }
}

async function handleDownload(payload: unknown) {
  const settings = await chrome.storage.sync.get(SETTINGS_KEYS);
  return handleDownloadWithSettings(payload, settings);
}

async function handleReact(payload: unknown) {
  const settings = await chrome.storage.sync.get(SETTINGS_KEYS);
  return handleReactWithSettings(payload, settings);
}

async function handleDeleteDownload(payload: unknown) {
  const settings = await chrome.storage.sync.get(SETTINGS_KEYS);
  return handleDeleteDownloadWithSettings(payload, settings);
}

async function handleCheckBatch(urls: unknown) {
  const settings = await chrome.storage.sync.get(SETTINGS_KEYS);
  const baseUrl = normalizeBaseUrl(settings.atlasBaseUrl || '');
  const token = (settings.atlasToken || '').trim();

  if (!baseUrl) {
    return {
      ok: false,
      error: 'Atlas base URL is not set. Open extension options to configure it.',
    };
  }

  const list = Array.isArray(urls)
    ? urls.filter((u) => typeof u === 'string' && u.trim() !== '')
    : [];

  if (list.length === 0) {
    return { ok: true, data: { results: [] } };
  }

  const results = [];
  for (let i = 0; i < list.length; i += 200) {
    const chunk = list.slice(i, i + 200);

    let response: Response;
    try {
      response = await fetchWithTimeout(`${baseUrl}/api/extension/files/check`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          ...(token ? { 'X-Atlas-Extension-Token': token } : {}),
        },
        body: JSON.stringify({ urls: chunk }),
      });
    } catch (error) {
      return {
        ok: false,
        error: networkErrorMessage(error),
      };
    }

    const data = await safeJson(response);
    if (response.ok && data === null) {
      return {
        ok: false,
        error: 'Atlas returned a non-JSON response. Check your base URL/token.',
        status: response.status,
      };
    }
    if (!response.ok) {
      return {
        ok: false,
        error:
          data && data.message ? data.message : `Request failed (${response.status}).`,
        status: response.status,
        data,
      };
    }

    if (Array.isArray(data?.results)) {
      results.push(...data.results);
    }
  }

  return {
    ok: true,
    data: { results },
  };
}

async function handleDownloadBatch(payloads: unknown) {
  const settings = await chrome.storage.sync.get(SETTINGS_KEYS);
  const list = Array.isArray(payloads) ? payloads : [];

  if (list.length === 0) {
    return { ok: true, results: [] };
  }

  const results = [];
  for (const payload of list) {
    // Run sequentially to avoid overwhelming Atlas / remote hosts.
    // Atlas itself will queue the downloads; this just submits requests.
    results.push(await handleDownloadWithSettings(payload, settings));
  }

  const allOk = results.every((r) => r && r.ok);
  return {
    ok: allOk,
    results,
    ...(allOk ? {} : { error: 'One or more requests failed.' }),
  };
}

async function handleDownloadWithSettings(payload: unknown, settings: AtlasSettings) {
  const baseUrl = normalizeBaseUrl(settings.atlasBaseUrl || '');
  const token = (settings.atlasToken || '').trim();

  if (!baseUrl) {
    return {
      ok: false,
      error: 'Atlas base URL is not set. Open extension options to configure it.',
    };
  }

  let response: Response;
  try {
    response = await fetchWithTimeout(`${baseUrl}/api/extension/files`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...(token ? { 'X-Atlas-Extension-Token': token } : {}),
      },
      body: JSON.stringify(payload),
    });
  } catch (error) {
    return {
      ok: false,
      error: networkErrorMessage(error),
    };
  }

  const data = await safeJson(response);
  if (response.ok && data === null) {
    return {
      ok: false,
      error: 'Atlas returned a non-JSON response. Check your base URL/token.',
      status: response.status,
    };
  }
  if (!response.ok) {
    return {
      ok: false,
      error: data && data.message ? data.message : `Request failed (${response.status}).`,
      status: response.status,
      data,
    };
  }

  return {
    ok: true,
    data,
  };
}

async function handleReactWithSettings(payload: unknown, settings: AtlasSettings) {
  const baseUrl = normalizeBaseUrl(settings.atlasBaseUrl || '');
  const token = (settings.atlasToken || '').trim();

  if (!baseUrl) {
    return {
      ok: false,
      error: 'Atlas base URL is not set. Open extension options to configure it.',
    };
  }

  let response: Response;
  try {
    response = await fetchWithTimeout(`${baseUrl}/api/extension/files/react`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...(token ? { 'X-Atlas-Extension-Token': token } : {}),
      },
      body: JSON.stringify(payload),
    });
  } catch (error) {
    return {
      ok: false,
      error: networkErrorMessage(error),
    };
  }

  const data = await safeJson(response);
  if (response.ok && data === null) {
    return {
      ok: false,
      error: 'Atlas returned a non-JSON response. Check your base URL/token.',
      status: response.status,
    };
  }
  if (!response.ok) {
    return {
      ok: false,
      error: data && data.message ? data.message : `Request failed (${response.status}).`,
      status: response.status,
      data,
    };
  }

  return {
    ok: true,
    data,
  };
}

async function handleDeleteDownloadWithSettings(payload: unknown, settings: AtlasSettings) {
  const baseUrl = normalizeBaseUrl(settings.atlasBaseUrl || '');
  const token = (settings.atlasToken || '').trim();

  if (!baseUrl) {
    return {
      ok: false,
      error: 'Atlas base URL is not set. Open extension options to configure it.',
    };
  }

  let response: Response;
  try {
    response = await fetchWithTimeout(`${baseUrl}/api/extension/files/delete-download`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...(token ? { 'X-Atlas-Extension-Token': token } : {}),
      },
      body: JSON.stringify(payload),
    });
  } catch (error) {
    return {
      ok: false,
      error: networkErrorMessage(error),
    };
  }

  const data = await safeJson(response);
  if (response.ok && data === null) {
    return {
      ok: false,
      error: 'Atlas returned a non-JSON response. Check your base URL/token.',
      status: response.status,
    };
  }
  if (!response.ok) {
    return {
      ok: false,
      error: data && data.message ? data.message : `Request failed (${response.status}).`,
      status: response.status,
      data,
    };
  }

  return {
    ok: true,
    data,
  };
}

function normalizeBaseUrl(input: string) {
  const trimmed = input.trim();
  if (!trimmed) {
    return '';
  }

  const withScheme = /^https?:\/\//i.test(trimmed)
    ? trimmed
    : `https://${trimmed}`;

  return withScheme.replace(/\/+$/, '');
}

async function safeJson(response: Response): Promise<unknown> {
  try {
    return await response.json();
  } catch {
    return null;
  }
}

async function fetchWithTimeout(input: string, init: RequestInit): Promise<Response> {
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

  try {
    return await fetch(input, {
      ...init,
      signal: controller.signal,
    });
  } finally {
    clearTimeout(timeoutId);
  }
}

function networkErrorMessage(error: unknown): string {
  if (error instanceof DOMException && error.name === 'AbortError') {
    return 'Atlas request timed out. Please try again.';
  }

  if (error instanceof Error && error.message) {
    return `Atlas request failed: ${error.message}`;
  }

  return 'Atlas request failed. Please try again.';
}
