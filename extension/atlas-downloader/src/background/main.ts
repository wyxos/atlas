type AtlasSettings = {
  atlasBaseUrl?: string;
  atlasToken?: string;
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
};

type ChromeStorageSync = {
  get: (keys: string[]) => Promise<AtlasSettings>;
};

type ChromeTabs = {
  sendMessage: (tabId: number, message: unknown) => void;
  create: (createProperties: { url: string }) => void;
};

type ChromeAction = {
  onClicked: {
    addListener: (callback: (tab: { id?: number } | undefined) => void) => void;
  };
};

type ChromeContextMenus = {
  create: (createProperties: {
    id: string;
    title: string;
    contexts: string[];
  }) => void;
  onClicked: {
    addListener: (
      callback: (info: { menuItemId: string | number }, tab?: { id?: number }) => void
    ) => void;
  };
};

type ChromeApi = {
  runtime: ChromeRuntime;
  storage: {
    sync: ChromeStorageSync;
  };
  tabs: ChromeTabs;
  action: ChromeAction;
  contextMenus: ChromeContextMenus;
};

declare const chrome: ChromeApi;

const SETTINGS_KEYS = ['atlasBaseUrl', 'atlasToken'];

chrome.runtime.onInstalled.addListener(() => {
  // Right click on the extension toolbar icon shows this menu (in addition to Chrome's built-ins).
  try {
    chrome.contextMenus.create({
      id: 'atlas-open-options',
      title: 'Options',
      contexts: ['action'],
    });
    chrome.contextMenus.create({
      id: 'atlas-open-site',
      title: 'Open Atlas',
      contexts: ['action'],
    });
  } catch {
    // Some Chromium builds may not support the "action" context; failing silently is fine.
  }
});

chrome.contextMenus.onClicked.addListener((info) => {
  if (info.menuItemId === 'atlas-open-options') {
    chrome.runtime.openOptionsPage();
    return;
  }

  if (info.menuItemId === 'atlas-open-site') {
    chrome.storage.sync.get(['atlasBaseUrl']).then((settings) => {
      const baseUrl = normalizeBaseUrl(settings.atlasBaseUrl || '');
      if (!baseUrl) {
        chrome.runtime.openOptionsPage();
        return;
      }

      // Opening a new tab doesn't require tab permission.
      chrome.tabs.create({ url: baseUrl });
    });
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

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  if (
    !message ||
    (message.type !== 'atlas-download' &&
      message.type !== 'atlas-download-batch' &&
      message.type !== 'atlas-check-batch' &&
      message.type !== 'atlas-react')
  ) {
    return;
  }

  const promise =
    message.type === 'atlas-download-batch'
      ? handleDownloadBatch(message.payloads)
      : message.type === 'atlas-check-batch'
        ? handleCheckBatch(message.urls)
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

async function handleDownload(payload: unknown) {
  const settings = await chrome.storage.sync.get(SETTINGS_KEYS);
  return handleDownloadWithSettings(payload, settings);
}

async function handleReact(payload: unknown) {
  const settings = await chrome.storage.sync.get(SETTINGS_KEYS);
  return handleReactWithSettings(payload, settings);
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

    const response = await fetch(`${baseUrl}/api/extension/files/check`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        ...(token ? { 'X-Atlas-Extension-Token': token } : {}),
      },
      body: JSON.stringify({ urls: chunk }),
    });

    const data = await safeJson(response);
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

  const response = await fetch(`${baseUrl}/api/extension/files`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { 'X-Atlas-Extension-Token': token } : {}),
    },
    body: JSON.stringify(payload),
  });

  const data = await safeJson(response);
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

  const response = await fetch(`${baseUrl}/api/extension/files/react`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { 'X-Atlas-Extension-Token': token } : {}),
    },
    body: JSON.stringify(payload),
  });

  const data = await safeJson(response);
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
