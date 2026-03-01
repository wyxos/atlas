type IsUrlOpenMessage = {
    type: 'ATLAS_IS_URL_OPEN';
    url: string;
};

type RuntimeMessageSender = {
    tab?: {
        id?: number;
    };
};

type BrowserTab = {
    id?: number;
    url?: string;
};

function normalizeComparableUrl(value: string): string | null {
    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }

    try {
        const parsed = new URL(trimmed);
        if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
            return null;
        }
        parsed.hash = '';
        return parsed.toString();
    } catch {
        return null;
    }
}

chrome.runtime.onMessage.addListener((
    message: unknown,
    sender: RuntimeMessageSender,
    sendResponse: (response?: unknown) => void,
) => {
    if (typeof message !== 'object' || message === null) {
        return false;
    }

    const payload = message as Partial<IsUrlOpenMessage>;
    if (payload.type !== 'ATLAS_IS_URL_OPEN' || typeof payload.url !== 'string') {
        return false;
    }

    const target = normalizeComparableUrl(payload.url);
    if (target === null) {
        sendResponse({ isOpenInAnotherTab: false });
        return false;
    }

    const senderTabId = sender.tab?.id;

    chrome.tabs.query({}, (tabs: BrowserTab[]) => {
        const isOpenInAnotherTab = tabs.some((tab) => {
            if (tab.id === undefined || tab.id === senderTabId || typeof tab.url !== 'string') {
                return false;
            }

            const tabUrl = normalizeComparableUrl(tab.url);
            return tabUrl !== null && tabUrl === target;
        });

        sendResponse({ isOpenInAnotherTab });
    });

    return true;
});
