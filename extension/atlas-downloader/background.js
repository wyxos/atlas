/* global chrome */
const SETTINGS_KEYS = ['atlasBaseUrl', 'atlasToken'];

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  if (!message || message.type !== 'atlas-download') {
    return;
  }

  handleDownload(message.payload)
    .then((result) => sendResponse(result))
    .catch((error) => {
      sendResponse({
        ok: false,
        error: error && error.message ? error.message : 'Unexpected error',
      });
    });

  return true;
});

async function handleDownload(payload) {
  const settings = await chrome.storage.sync.get(SETTINGS_KEYS);
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

function normalizeBaseUrl(input) {
  const trimmed = input.trim();
  if (!trimmed) {
    return '';
  }

  const withScheme = /^https?:\/\//i.test(trimmed)
    ? trimmed
    : `https://${trimmed}`;

  return withScheme.replace(/\/+$/, '');
}

async function safeJson(response) {
  try {
    return await response.json();
  } catch {
    return null;
  }
}
