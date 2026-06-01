import { getStoredConnectionOptions } from './atlas-options';
import { createAtlasApiHeaders, createAtlasFetchAuthOptions, hasAtlasApiAuth, normalizeAtlasDomain } from './atlas-auth';

type RuntimeSendResponse = (response?: unknown) => void;

type OpenDeviantArtUsernameTabPayload = {
    type: 'ATLAS_OPEN_DEVIANTART_USERNAME_TAB';
    username: unknown;
};

function parseJsonResponse(response: Response): Promise<unknown> {
    return response.text()
        .then((bodyText) => {
            const trimmed = bodyText.trim();
            if (trimmed === '') {
                return null;
            }

            try {
                return JSON.parse(trimmed) as unknown;
            } catch {
                return bodyText;
            }
        })
        .catch(() => null);
}

function parseNonEmptyString(value: unknown, maxLength: number): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();

    return trimmed !== '' && trimmed.length <= maxLength ? trimmed : null;
}

function resolveBrowseUrlFromPayload(payload: unknown): string | null {
    if (!payload || typeof payload !== 'object') {
        return null;
    }

    const browseUrl = (payload as Record<string, unknown>).browse_url;

    return typeof browseUrl === 'string' && browseUrl.trim() !== '' ? browseUrl.trim() : null;
}

async function openDeviantArtBrowseTab(
    endpoint: string,
    body: Record<string, unknown>,
    sendResponse: RuntimeSendResponse,
): Promise<void> {
    const stored = await getStoredConnectionOptions();
    const atlasDomain = normalizeAtlasDomain(stored.atlasDomain);
    const apiToken = stored.apiToken.trim();
    if (atlasDomain === '' || !hasAtlasApiAuth(atlasDomain, apiToken)) {
        sendResponse({ ok: false, status: 0, payload: null });
        return;
    }

    const response = await fetch(`${atlasDomain}/api/extension/browse-tabs/${endpoint}`, {
        method: 'POST',
        headers: createAtlasApiHeaders(apiToken, true),
        ...createAtlasFetchAuthOptions(apiToken),
        body: JSON.stringify(body),
    });
    const responsePayload = await parseJsonResponse(response);
    const browseUrl = resolveBrowseUrlFromPayload(responsePayload);

    if (!response.ok || browseUrl === null) {
        sendResponse({
            ok: false,
            status: response.status,
            payload: responsePayload,
        });
        return;
    }

    chrome.tabs.create({ url: browseUrl }, () => {
        sendResponse({
            ok: !chrome.runtime.lastError,
            status: response.status,
            payload: responsePayload,
        });
    });
}

export function handleOpenDeviantArtUsernameTabRuntimeMessage(
    message: unknown,
    sendResponse: RuntimeSendResponse,
): boolean {
    if (!message || typeof message !== 'object') {
        return false;
    }

    const payload = message as OpenDeviantArtUsernameTabPayload;
    if (payload.type !== 'ATLAS_OPEN_DEVIANTART_USERNAME_TAB') {
        return false;
    }

    const username = parseNonEmptyString(payload.username, 255);
    if (username === null) {
        sendResponse({ ok: false, status: 0, payload: null });
        return false;
    }

    void openDeviantArtBrowseTab('deviantart-user', { username }, sendResponse).catch(() => {
        sendResponse({ ok: false, status: 0, payload: null });
    });

    return true;
}
