/* global chrome */
const status = document.getElementById('status');
const baseUrlInput = document.getElementById('atlasBaseUrl');
const tokenInput = document.getElementById('atlasToken');
const saveButton = document.getElementById('save');

loadSettings();

saveButton.addEventListener('click', saveSettings);

function loadSettings() {
  chrome.storage.sync.get(['atlasBaseUrl', 'atlasToken'], (data) => {
    baseUrlInput.value = data.atlasBaseUrl || '';
    tokenInput.value = data.atlasToken || '';
  });
}

function saveSettings() {
  const atlasBaseUrl = baseUrlInput.value.trim();
  const atlasToken = tokenInput.value.trim();

  chrome.storage.sync.set({ atlasBaseUrl, atlasToken }, () => {
    status.textContent = 'Settings saved.';
    setTimeout(() => {
      status.textContent = '';
    }, 2000);
  });
}
