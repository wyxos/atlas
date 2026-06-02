import { normalizeComparableUrls } from './background-url-utils';

type RuntimeSendResponse = (response?: unknown) => void;

const MAX_SOURCE_TABS_PER_REQUEST = 1000;

type OpenSourceTabsPayload = {
    type: 'ATLAS_OPEN_SOURCE_TABS';
    urls?: unknown;
};

export function handleOpenSourceTabsRuntimeMessage(
    message: unknown,
    sendResponse: RuntimeSendResponse,
): boolean {
    if (!message || typeof message !== 'object') {
        return false;
    }

    const payload = message as OpenSourceTabsPayload;
    if (payload.type !== 'ATLAS_OPEN_SOURCE_TABS') {
        return false;
    }

    const urls = normalizeComparableUrls(payload.urls).slice(0, MAX_SOURCE_TABS_PER_REQUEST);
    if (urls.length === 0) {
        sendResponse({ ok: false, openedCount: 0, failedCount: 0 });
        return false;
    }

    let openedCount = 0;
    let failedCount = 0;
    let remainingCount = urls.length;

    const sendFinalResponse = (): void => {
        remainingCount -= 1;

        if (remainingCount > 0) {
            return;
        }

        sendResponse({
            ok: failedCount === 0,
            openedCount,
            failedCount,
        });
    };

    urls.forEach((url) => {
        try {
            chrome.tabs.create({ url, active: false }, () => {
                if (chrome.runtime.lastError) {
                    failedCount += 1;
                } else {
                    openedCount += 1;
                }

                sendFinalResponse();
            });
        } catch {
            failedCount += 1;
            sendFinalResponse();
        }
    });

    return true;
}
