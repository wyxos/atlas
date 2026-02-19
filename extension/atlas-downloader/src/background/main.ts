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
  query: (queryInfo: { active: boolean; lastFocusedWindow: boolean }) => Promise<ChromeTab[]>;
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

let excludedIconImageDataPromise: Promise<Record<number, ImageData> | null> | null = null;

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
});

chrome.runtime.onStartup.addListener(() => {
  void refreshActionForActiveTab();
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

  if (!Object.prototype.hasOwnProperty.call(changes, 'atlasExcludedDomains')) {
    return;
  }

  void refreshActionForActiveTab();
});

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  if (
    !message ||
    (message.type !== 'atlas-download' &&
      message.type !== 'atlas-download-batch' &&
      message.type !== 'atlas-check-batch' &&
      message.type !== 'atlas-react' &&
      message.type !== 'atlas-delete-download')
  ) {
    return;
  }

  const promise =
    message.type === 'atlas-download-batch'
      ? handleDownloadBatch(message.payloads)
      : message.type === 'atlas-check-batch'
        ? handleCheckBatch(message.urls)
        : message.type === 'atlas-delete-download'
          ? handleDeleteDownload(message.payload)
          : message.type === 'atlas-react'
            ? handleReact(message.payload)
            : handleDownload(message.payload);

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
