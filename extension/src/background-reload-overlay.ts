type BrowserTab = {
    id?: number;
    url?: string;
};

function canInjectReloadOverlay(tab: BrowserTab): tab is BrowserTab & { id: number; url: string } {
    return typeof tab.id === 'number' && typeof tab.url === 'string';
}

function injectReloadOverlayIntoTab(tabId: number): void {
    if (!chrome.scripting || typeof chrome.scripting.executeScript !== 'function') {
        return;
    }

    chrome.scripting.executeScript({
        target: { tabId },
        func: () => {
            const overlayId = 'atlas-extension-reload-overlay';
            if (document.getElementById(overlayId)) {
                return;
            }

            const overlay = document.createElement('div');
            overlay.id = overlayId;
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.style.position = 'fixed';
            overlay.style.inset = '0';
            overlay.style.zIndex = '2147483647';
            overlay.style.background = 'rgba(8, 14, 24, 0.86)';
            overlay.style.backdropFilter = 'blur(2px)';
            overlay.style.display = 'flex';
            overlay.style.alignItems = 'center';
            overlay.style.justifyContent = 'center';
            overlay.style.padding = '24px';
            overlay.style.boxSizing = 'border-box';

            const panel = document.createElement('div');
            panel.style.maxWidth = '540px';
            panel.style.width = '100%';
            panel.style.background = '#0f172a';
            panel.style.border = '1px solid rgba(148, 163, 184, 0.35)';
            panel.style.borderRadius = '14px';
            panel.style.boxShadow = '0 24px 60px rgba(0, 0, 0, 0.45)';
            panel.style.padding = '24px';
            panel.style.color = '#e2e8f0';
            panel.style.fontFamily = 'ui-sans-serif, system-ui, -apple-system, Segoe UI, sans-serif';

            const heading = document.createElement('h2');
            heading.textContent = 'Atlas extension updated';
            heading.style.margin = '0 0 10px 0';
            heading.style.fontSize = '20px';
            heading.style.lineHeight = '1.3';
            heading.style.fontWeight = '700';

            const message = document.createElement('p');
            message.textContent = 'Reload this tab to re-enable Atlas widgets and checks on this page.';
            message.style.margin = '0 0 18px 0';
            message.style.fontSize = '14px';
            message.style.lineHeight = '1.6';
            message.style.color = '#cbd5e1';

            const actions = document.createElement('div');
            actions.style.display = 'flex';
            actions.style.gap = '10px';
            actions.style.flexWrap = 'wrap';

            const reloadButton = document.createElement('button');
            reloadButton.type = 'button';
            reloadButton.textContent = 'Reload tab';
            reloadButton.style.background = '#14b8a6';
            reloadButton.style.color = '#052e2b';
            reloadButton.style.border = 'none';
            reloadButton.style.borderRadius = '10px';
            reloadButton.style.padding = '10px 16px';
            reloadButton.style.fontSize = '14px';
            reloadButton.style.fontWeight = '700';
            reloadButton.style.cursor = 'pointer';
            reloadButton.addEventListener('click', () => {
                window.location.reload();
            });

            const dismissButton = document.createElement('button');
            dismissButton.type = 'button';
            dismissButton.textContent = 'Dismiss';
            dismissButton.style.background = 'transparent';
            dismissButton.style.color = '#cbd5e1';
            dismissButton.style.border = '1px solid rgba(148, 163, 184, 0.5)';
            dismissButton.style.borderRadius = '10px';
            dismissButton.style.padding = '10px 16px';
            dismissButton.style.fontSize = '14px';
            dismissButton.style.fontWeight = '600';
            dismissButton.style.cursor = 'pointer';
            dismissButton.addEventListener('click', () => {
                overlay.remove();
            });

            actions.appendChild(reloadButton);
            actions.appendChild(dismissButton);
            panel.appendChild(heading);
            panel.appendChild(message);
            panel.appendChild(actions);
            overlay.appendChild(panel);
            (document.documentElement ?? document.body).appendChild(overlay);
            reloadButton.focus();
        },
    }, () => {
        void chrome.runtime.lastError;
    });
}

export function notifyTabsExtensionReloaded(): void {
    chrome.tabs.query({}, (tabs: BrowserTab[]) => {
        for (const tab of tabs) {
            if (canInjectReloadOverlay(tab)) {
                injectReloadOverlayIntoTab(tab.id);
            }
        }
    });
}
