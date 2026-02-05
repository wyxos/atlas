/* global chrome */
(() => {
  const MIN_SIZE = 450;
  const WRAP_CLASS = 'atlas-download-wrapper';
  const BUTTON_CLASS = 'atlas-download-button';
  const BUTTON_TEXT = 'Atlas';
  const iconUrl = chrome.runtime.getURL('icon.svg');

  injectStyles();
  scan(document);

  const observer = new MutationObserver((mutations) => {
    for (const mutation of mutations) {
      for (const node of mutation.addedNodes) {
        if (!(node instanceof Element)) {
          continue;
        }

        if (node.matches('img, video')) {
          processMedia(node);
        }

        node.querySelectorAll('img, video').forEach(processMedia);
      }
    }
  });

  observer.observe(document.documentElement, { childList: true, subtree: true });

  function scan(root) {
    root.querySelectorAll('img, video').forEach(processMedia);
  }

  function processMedia(element) {
    if (element.dataset.atlasDownloadBound === '1') {
      return;
    }

    if (element.tagName === 'IMG') {
      processImage(element);
      return;
    }

    if (element.tagName === 'VIDEO') {
      attachButton(element);
    }
  }

  function processImage(img) {
    if (!img.complete) {
      img.addEventListener('load', () => processImage(img), { once: true });
      return;
    }

    const width = img.naturalWidth || img.width || img.clientWidth;
    const height = img.naturalHeight || img.height || img.clientHeight;

    if (width >= MIN_SIZE && height >= MIN_SIZE) {
      attachButton(img);
    }
  }

  function attachButton(element) {
    if (element.dataset.atlasDownloadBound === '1') {
      return;
    }

    const wrapper = ensureWrapper(element);
    const button = document.createElement('button');
    button.type = 'button';
    button.className = BUTTON_CLASS;
    button.setAttribute('aria-label', 'Send to Atlas');
    button.innerHTML = `<img src="${iconUrl}" alt="" />`;
    button.title = 'Send to Atlas';

    button.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation();
      sendDownload(element, button);
    });

    wrapper.appendChild(button);
    element.dataset.atlasDownloadBound = '1';
  }

  function ensureWrapper(element) {
    const parent = element.parentElement;
    if (parent && parent.classList.contains(WRAP_CLASS)) {
      return parent;
    }

    const wrapper = document.createElement('span');
    wrapper.className = WRAP_CLASS;

    const display = getComputedStyle(element).display;
    const isBlock = display === 'block' || display === 'flex' || display === 'grid';
    wrapper.style.display = isBlock ? 'block' : 'inline-block';

    if (isBlock) {
      wrapper.style.width = '100%';
    }

    element.parentNode.insertBefore(wrapper, element);
    wrapper.appendChild(element);

    return wrapper;
  }

  function sendDownload(element, button) {
    const payload = buildPayload(element);

    if (!payload.url) {
      showToast('No media URL found.');
      return;
    }

    setButtonState(button, 'Sending...');

    chrome.runtime.sendMessage({ type: 'atlas-download', payload }, (response) => {
      if (!response) {
        setButtonState(button, BUTTON_TEXT, true);
        showToast('Atlas extension did not respond.');
        return;
      }

      if (response.ok) {
        setButtonState(button, 'Queued');
        showToast('Download queued in Atlas.');
      } else {
        setButtonState(button, BUTTON_TEXT, true);
        showToast(response.error || 'Atlas request failed.');
      }
    });
  }

  function buildPayload(element) {
    const tagName = element.tagName.toLowerCase();
    const url = getMediaUrl(element);
    const previewUrl = tagName === 'video' ? element.poster || '' : url;

    return {
      url,
      original_url: url,
      referrer_url: window.location.href,
      page_title: document.title,
      tag_name: tagName,
      width: getMediaWidth(element),
      height: getMediaHeight(element),
      alt: tagName === 'img' ? element.alt || '' : '',
      preview_url: previewUrl || '',
      source: 'Extension',
    };
  }

  function getMediaUrl(element) {
    const direct =
      safeUrl(element.currentSrc) ||
      safeUrl(element.src) ||
      safeUrl(element.getAttribute('src'));
    if (direct) {
      return direct;
    }

    const source = element.querySelector('source[src]');
    const sourceUrl = source ? safeUrl(source.src || source.getAttribute('src')) : '';
    if (sourceUrl) {
      return sourceUrl;
    }

    const dataStoreUrl = resolveDataStoreUrl(element);
    if (dataStoreUrl) {
      return dataStoreUrl;
    }

    return resolveMetaVideoUrl();
  }

  function safeUrl(value) {
    if (!value || typeof value !== 'string') {
      return '';
    }

    const trimmed = value.trim();
    if (!trimmed || trimmed.startsWith('blob:')) {
      return '';
    }

    return trimmed;
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
      if (content) {
        return content;
      }
    }

    return '';
  }

  function resolveDataStoreUrl(element) {
    let node = element;
    let depth = 0;

    while (node && depth < 8) {
      const dataStore = node.getAttribute('data-store');
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

  function getMediaWidth(element) {
    if (element.tagName === 'IMG') {
      return element.naturalWidth || element.width || element.clientWidth || null;
    }

    if (element.tagName === 'VIDEO') {
      return element.videoWidth || element.clientWidth || null;
    }

    return null;
  }

  function getMediaHeight(element) {
    if (element.tagName === 'IMG') {
      return element.naturalHeight || element.height || element.clientHeight || null;
    }

    if (element.tagName === 'VIDEO') {
      return element.videoHeight || element.clientHeight || null;
    }

    return null;
  }

  function setButtonState(button, text, resetLater) {
    if (text === BUTTON_TEXT) {
      button.innerHTML = `<img src="${iconUrl}" alt="" />`;
    } else {
      button.textContent = text;
    }
    button.disabled = text !== BUTTON_TEXT;

    if (resetLater) {
      return;
    }

    if (text !== BUTTON_TEXT) {
      setTimeout(() => {
        button.textContent = BUTTON_TEXT;
        button.disabled = false;
      }, 2000);
    }
  }

  function showToast(message) {
    const toast = document.createElement('div');
    toast.className = 'atlas-download-toast';
    toast.textContent = message;
    document.body.appendChild(toast);

    requestAnimationFrame(() => toast.classList.add('show'));

    setTimeout(() => {
      toast.classList.remove('show');
      toast.addEventListener('transitionend', () => toast.remove(), { once: true });
    }, 2600);
  }

  function injectStyles() {
    if (document.getElementById('atlas-download-styles')) {
      return;
    }

    const style = document.createElement('style');
    style.id = 'atlas-download-styles';
    style.textContent = `
      .${WRAP_CLASS} {
        position: relative;
        max-width: 100%;
        overflow: visible;
      }

      .${BUTTON_CLASS} {
        position: absolute;
        top: 8px;
        right: 8px;
        z-index: 999999;
        border: none;
        border-radius: 18px;
        width: 100px;
        height: 100px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgba(15, 23, 42, 0.9);
        color: #fff;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        opacity: 0.85;
        transition: opacity 0.2s ease, transform 0.2s ease;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.35);
      }

      .${WRAP_CLASS}:hover .${BUTTON_CLASS} {
        opacity: 1;
      }

      .${BUTTON_CLASS}:disabled {
        opacity: 1;
        cursor: default;
      }

      .${BUTTON_CLASS} img {
        width: 64px;
        height: 64px;
        display: block;
      }

      .atlas-download-toast {
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: rgba(15, 23, 42, 0.95);
        color: #fff;
        padding: 10px 14px;
        border-radius: 8px;
        font-size: 13px;
        opacity: 0;
        transform: translateY(6px);
        transition: opacity 0.2s ease, transform 0.2s ease;
        z-index: 1000000;
      }

      .atlas-download-toast.show {
        opacity: 1;
        transform: translateY(0);
      }
    `;
    document.head.appendChild(style);
  }
})();
