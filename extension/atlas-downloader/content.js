/* global chrome */
(() => {
  const MIN_SIZE = 450;
  const ROOT_ID = 'atlas-downloader-root';
  const OPEN_CLASS = 'atlas-open';
  const REACTIONS = [
    {
      type: 'love',
      label: 'Favorite',
      className: 'love',
      icon: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78Z"/></svg>',
    },
    {
      type: 'like',
      label: 'Like',
      className: 'like',
      icon: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10v12"/><path d="M15 5.88 14 10h6.14a2 2 0 0 1 1.94 2.46l-2.34 8.25A2 2 0 0 1 17.82 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.34a2 2 0 0 0 1.79-1.11l3.07-5.89A2 2 0 0 1 15 2a2 2 0 0 1 2 2v1.88Z"/></svg>',
    },
    {
      type: 'dislike',
      label: 'Dislike',
      className: 'dislike',
      icon: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 14V2"/><path d="M9 18.12 10 14H3.86a2 2 0 0 1-1.94-2.46L4.26 3.29A2 2 0 0 1 6.18 2H20a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2.34a2 2 0 0 0-1.79 1.11l-3.07 5.89A2 2 0 0 1 9 22a2 2 0 0 1-2-2v-1.88Z"/></svg>',
    },
    {
      type: 'funny',
      label: 'Funny',
      className: 'funny',
      icon: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 14s1.5 2 4 2 4-2 4-2"/><path d="M9 9h.01"/><path d="M15 9h.01"/><path d="M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z"/></svg>',
    },
  ];

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
  });

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
    style.href = chrome.runtime.getURL('content.css');
    shadow.appendChild(style);

    const root = document.createElement('div');
    root.className = 'atlas-shadow-root';

    const showToast = createToastFn(root);

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

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'atlas-downloader-close';
    close.setAttribute('aria-label', 'Close');
    close.textContent = '×';

    header.appendChild(title);
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

    let items = [];
    let scanNonce = 0;

    toggle.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      openModal();
    });

    overlay.addEventListener('click', () => closeModal());
    close.addEventListener('click', () => closeModal());

    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') {
        return;
      }
      if (!root.classList.contains(OPEN_CLASS)) {
        return;
      }
      closeModal();
    });

    function openModal() {
      root.classList.add(OPEN_CLASS);
      refreshList();
    }

    function closeModal() {
      root.classList.remove(OPEN_CLASS);
    }

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
      queue.disabled = selectedCount === 0;
      refresh.disabled = false;
      checkAtlas.disabled = items.length === 0;
      selectAll.disabled = items.length === 0;
      selectNone.disabled = items.length === 0;
    }

    function refreshList() {
      scanNonce += 1;
      const currentScan = scanNonce;
      items = [];
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
      for (const reaction of REACTIONS) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `atlas-downloader-reaction-btn ${reaction.className}${
          currentReaction === reaction.type ? ' active' : ''
        }${item.reactionPending ? ' pending' : ''}`.trim();
        button.setAttribute('aria-label', reaction.label);
        button.title = reaction.label;
        button.innerHTML = reaction.icon;
        button.disabled = Boolean(item.reactionPending);
        button.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();
          reactToItem(item, reaction.type);
        });
        reactions.appendChild(button);
      }

      info.appendChild(kind);
      info.appendChild(url);
      info.appendChild(sub);
      info.appendChild(reactions);

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

      chrome.runtime.sendMessage({ type: 'atlas-check-batch', urls }, (response) => {
        if (!response) {
          if (!silent) {
            showToast('Atlas extension did not respond.');
          }
          return;
        }

        if (!response.ok) {
          if (!silent) {
            showToast(response.error || 'Atlas check failed.');
          }
          return;
        }

        const results = Array.isArray(response.data?.results) ? response.data.results : [];
        const byUrl = new Map(results.map((r) => [r.url, r]));

        for (const item of items) {
          const match = byUrl.get(item.url);
          if (!match) {
            continue;
          }

          item.atlas = {
            exists: Boolean(match.exists),
            downloaded: Boolean(match.downloaded),
            file_id: match.file_id ?? null,
            reaction: match.reaction ?? null,
          };
        }

        renderList();
        setReady(summaryText());
      });
    }

    function reactToItem(item, type) {
      item.reactionPending = type;
      item.status = 'Reacting…';
      item.statusClass = '';
      renderList();

      const payload = {
        type,
        url: item.url,
        original_url: item.url,
        referrer_url: window.location.href,
        page_title: document.title,
        tag_name: item.tag_name,
        width: item.width,
        height: item.height,
        alt: item.alt || '',
        preview_url: item.preview_url || '',
        source: 'Extension',
      };

      chrome.runtime.sendMessage({ type: 'atlas-react', payload }, (response) => {
        item.reactionPending = null;

        if (!response) {
          item.status = 'No response';
          item.statusClass = 'err';
          renderList();
          setReady(summaryText());
          showToast('Atlas extension did not respond.');
          return;
        }

        if (!response.ok) {
          item.status = response.error || 'Failed';
          item.statusClass = 'err';
          renderList();
          setReady(summaryText());
          showToast(response.error || 'Reaction failed.');
          return;
        }

        const data = response.data || null;
        const file = data?.file || null;

        item.atlas = {
          exists: Boolean(file),
          downloaded: Boolean(file?.downloaded),
          file_id: file?.id ?? null,
          reaction: data?.reaction ?? null,
        };

        item.status = '';
        item.statusClass = '';
        renderList();
        setReady(summaryText());

        if (item.atlas.exists && !item.atlas.downloaded && type !== 'dislike') {
          pollUntilDownloaded([item.url], 0);
        }
      });
    }

    function queueSelected() {
      const selected = items.filter((item) => item.selected);
      if (selected.length === 0) {
        showToast('Select one or more items first.');
        return;
      }

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

      const payloads = selected.map((item) => ({
        url: item.url,
        original_url: item.url,
        referrer_url: window.location.href,
        page_title: document.title,
        tag_name: item.tag_name,
        width: item.width,
        height: item.height,
        alt: item.alt || '',
        preview_url: item.preview_url || '',
        source: 'Extension',
        // Align with Atlas core behavior: reactions dispatch downloads.
        reaction_type: 'like',
      }));

      chrome.runtime.sendMessage(
        { type: 'atlas-download-batch', payloads },
        (response) => {
          if (!response) {
            for (const item of selected) {
              item.status = 'No response';
              item.statusClass = 'err';
            }
            renderList();
            setReady(summaryText());
            showToast('Atlas extension did not respond.');
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
                file_id: file?.id ?? null,
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

          if (response.ok) {
            showToast(`Queued ${selected.length} download(s) in Atlas.`);
          } else {
            showToast(response.error || 'Some requests failed.');
          }

          if (urlsToPoll.length > 0) {
            pollUntilDownloaded(urlsToPoll, 0);
          }
        }
      );
    }

    function pollUntilDownloaded(urls, attempt) {
      if (attempt > 15) {
        return;
      }

      setTimeout(() => {
        chrome.runtime.sendMessage({ type: 'atlas-check-batch', urls }, (response) => {
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

          if (remaining > 0) {
            pollUntilDownloaded(urls, attempt + 1);
          }
        });
      }, 2000);
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

      const url = safeUrl(img.currentSrc) || safeUrl(img.src) || safeUrl(img.getAttribute('src'));
      if (!url) {
        return null;
      }

      return {
        tag_name: 'img',
        url,
        preview_url: url,
        width,
        height,
        alt: img.alt || '',
      };
    }

    if (element.tagName === 'VIDEO') {
      const url = getVideoUrl(element);
      if (!url) {
        return null;
      }

      return {
        tag_name: 'video',
        url,
        preview_url: element.poster || '',
        width: element.videoWidth || element.clientWidth || null,
        height: element.videoHeight || element.clientHeight || null,
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
    return function showToast(message) {
      const toast = document.createElement('div');
      toast.className = 'atlas-downloader-toast';
      toast.textContent = message;
      container.appendChild(toast);

      requestAnimationFrame(() => toast.classList.add('show'));

      setTimeout(() => {
        toast.classList.remove('show');
        toast.addEventListener('transitionend', () => toast.remove(), { once: true });
      }, 2600);
    };
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
})();
