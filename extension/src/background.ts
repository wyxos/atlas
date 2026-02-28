/* global chrome */
const OPEN_OPTIONS_MENU_ID = 'atlas-open-options';

function ensureActionContextMenu(): void {
    chrome.contextMenus.removeAll(() => {
        if (chrome.runtime.lastError) {
            return;
        }

        chrome.contextMenus.create({
            id: OPEN_OPTIONS_MENU_ID,
            title: 'Open Atlas Extension Options',
            contexts: ['action'],
        });
    });
}

chrome.runtime.onInstalled.addListener(() => {
    ensureActionContextMenu();
});

chrome.runtime.onStartup.addListener(() => {
    ensureActionContextMenu();
});

chrome.contextMenus.onClicked.addListener((info: { menuItemId: string | number }) => {
    if (info.menuItemId !== OPEN_OPTIONS_MENU_ID) {
        return;
    }

    chrome.runtime.openOptionsPage();
});
