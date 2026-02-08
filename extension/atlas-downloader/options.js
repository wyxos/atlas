/* global chrome */
const status = document.getElementById('status');
const baseUrlInput = document.getElementById('atlasBaseUrl');
const tokenInput = document.getElementById('atlasToken');
const toggleTokenVisibilityButton = document.getElementById('toggleTokenVisibility');
const addDomainInput = document.getElementById('addDomain');
const addDomainButton = document.getElementById('addDomainButton');
const domainsList = document.getElementById('domainsList');
const saveButton = document.getElementById('save');

const state = {
  domains: [],
  editingIndex: null,
};

loadSettings();

saveButton.addEventListener('click', saveSettings);
toggleTokenVisibilityButton.addEventListener('click', toggleTokenVisibility);
addDomainButton.addEventListener('click', addDomainsFromInput);
addDomainInput.addEventListener('keydown', (e) => {
  if (e.key !== 'Enter') return;
  e.preventDefault();
  addDomainsFromInput();
});

function loadSettings() {
  chrome.storage.sync.get(['atlasBaseUrl', 'atlasToken', 'atlasExcludedDomains'], (data) => {
    baseUrlInput.value = data.atlasBaseUrl || '';
    tokenInput.value = data.atlasToken || '';
    state.domains = parseExcludedDomains(data.atlasExcludedDomains || '');
    state.editingIndex = null;
    renderDomains();
  });
}

function saveSettings() {
  const atlasBaseUrl = baseUrlInput.value.trim();
  const atlasToken = tokenInput.value.trim();
  const atlasExcludedDomains = state.domains.join('\n');

  chrome.storage.sync.set({ atlasBaseUrl, atlasToken, atlasExcludedDomains }, () => {
    status.textContent = 'Settings saved.';
    setTimeout(() => {
      status.textContent = '';
    }, 2000);
  });
}

function toggleTokenVisibility() {
  tokenInput.type = tokenInput.type === 'password' ? 'text' : 'password';
}

function addDomainsFromInput() {
  const raw = addDomainInput.value || '';
  const parts = raw
    .split(/[\n, ]+/g)
    .map((v) => v.trim())
    .filter(Boolean);

  let added = 0;
  for (const part of parts) {
    const normalized = normalizeDomain(part);
    if (!normalized) continue;
    if (state.domains.includes(normalized)) continue;
    state.domains.push(normalized);
    added += 1;
  }

  state.domains.sort();
  state.editingIndex = null;
  addDomainInput.value = '';
  renderDomains();

  if (added > 0) {
    status.textContent = `Added ${added} domain${added === 1 ? '' : 's'}.`;
    setTimeout(() => {
      status.textContent = '';
    }, 1500);
  }
}

function renderDomains() {
  domainsList.replaceChildren();

  if (state.domains.length === 0) {
    const empty = document.createElement('li');
    empty.className = 'domain-empty';
    empty.textContent = 'No excluded domains.';
    domainsList.appendChild(empty);
    return;
  }

  for (let i = 0; i < state.domains.length; i += 1) {
    const value = state.domains[i];
    const li = document.createElement('li');

    if (state.editingIndex === i) {
      const input = document.createElement('input');
      input.className = 'input';
      input.value = value;
      input.setAttribute('aria-label', 'Edit domain');

      const actions = document.createElement('div');
      actions.className = 'domain-actions';

      const save = makeSmallIconButton('Save', iconCheck(), () => {
        const next = normalizeDomain(input.value);
        if (!next) {
          state.editingIndex = null;
          renderDomains();
          return;
        }

        state.domains.splice(i, 1);
        if (!state.domains.includes(next)) {
          state.domains.push(next);
        }
        state.domains.sort();
        state.editingIndex = null;
        renderDomains();
      });

      const cancel = makeSmallIconButton('Cancel', iconX(), () => {
        state.editingIndex = null;
        renderDomains();
      });

      actions.appendChild(save);
      actions.appendChild(cancel);

      li.appendChild(input);
      li.appendChild(actions);
      domainsList.appendChild(li);
      continue;
    }

    const label = document.createElement('div');
    label.className = 'domain-value';
    label.textContent = value;
    label.title = value;

    const actions = document.createElement('div');
    actions.className = 'domain-actions';

    const edit = makeSmallIconButton('Edit', iconEdit(), () => {
      state.editingIndex = i;
      renderDomains();
      // Focus after rerender.
      requestAnimationFrame(() => {
        const field = domainsList.querySelector('input');
        field?.focus();
        field?.select();
      });
    });

    const del = makeSmallIconButton('Delete', iconTrash(), () => {
      state.domains.splice(i, 1);
      state.editingIndex = null;
      renderDomains();
    });

    actions.appendChild(edit);
    actions.appendChild(del);

    li.appendChild(label);
    li.appendChild(actions);
    domainsList.appendChild(li);
  }
}

function makeSmallIconButton(title, svgMarkup, onClick) {
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.title = title;
  btn.setAttribute('aria-label', title);
  btn.innerHTML = svgMarkup;
  btn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    onClick();
  });
  return btn;
}

function parseExcludedDomains(value) {
  if (!value || typeof value !== 'string') {
    return [];
  }

  return value
    .split(/[\n,]/g)
    .map((entry) => entry.trim())
    .filter((entry) => entry && !entry.startsWith('#'))
    .map((entry) => normalizeDomain(entry))
    .filter(Boolean);
}

function normalizeDomain(value) {
  if (!value || typeof value !== 'string') {
    return '';
  }

  let trimmed = value.trim().toLowerCase();
  if (!trimmed) {
    return '';
  }

  if (trimmed.startsWith('*.')) {
    trimmed = trimmed.slice(2);
  }

  // If it looks like a URL, parse it and take the host.
  if (/^https?:\/\//i.test(trimmed)) {
    try {
      return new URL(trimmed).hostname.toLowerCase();
    } catch {
      return '';
    }
  }

  // If they pasted something like example.com/path, strip the path.
  trimmed = trimmed.replace(/\/.*$/, '');

  // Trim leading dots/spaces.
  trimmed = trimmed.replace(/^\.+/, '');

  // Very loose host validation; the content script does match logic.
  return trimmed;
}

function iconEdit() {
  return '<svg viewBox="0 0 24 24" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
}

function iconTrash() {
  return '<svg viewBox="0 0 24 24" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>';
}

function iconCheck() {
  return '<svg viewBox="0 0 24 24" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><path d="M20 6 9 17l-5-5"/></svg>';
}

function iconX() {
  return '<svg viewBox="0 0 24 24" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg>';
}
