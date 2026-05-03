import { getStoredOptions } from './atlas-options';
import { isCivitAiNsfwHostname } from './civitai-domains';

type RuntimeSendResponse = (response?: unknown) => void;
type RuntimeMessageSender = {
    tab?: {
        url?: string;
    };
};

type OpenCivitAiModelTabPayload = {
    type: 'ATLAS_OPEN_CIVITAI_MODEL_TAB';
    modelId: unknown;
    modelVersionId?: unknown;
    nsfw?: unknown;
    sourceHostname?: unknown;
    sourceUrl?: unknown;
};

type OpenCivitAiUsernameTabPayload = {
    type: 'ATLAS_OPEN_CIVITAI_USERNAME_TAB';
    username: unknown;
    nsfw?: unknown;
    sourceHostname?: unknown;
    sourceUrl?: unknown;
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

function parsePositiveInteger(value: unknown): number | null {
    if (typeof value === 'number' && Number.isInteger(value) && value > 0) {
        return value;
    }

    if (typeof value === 'string') {
        const trimmed = value.trim();
        if (trimmed !== '' && /^\d+$/.test(trimmed)) {
            const parsed = Number(trimmed);

            return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
        }
    }

    return null;
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

function isNsfwSourceUrl(value: unknown): boolean {
    if (typeof value !== 'string' || value.trim() === '') {
        return false;
    }

    try {
        return isCivitAiNsfwHostname(new URL(value).hostname);
    } catch {
        return false;
    }
}

function resolveCivitAiNsfw(
    payload: { nsfw?: unknown; sourceHostname?: unknown; sourceUrl?: unknown },
    sender?: RuntimeMessageSender,
): boolean {
    if (payload.nsfw === true) {
        return true;
    }

    if (typeof payload.sourceHostname === 'string' && isCivitAiNsfwHostname(payload.sourceHostname)) {
        return true;
    }

    return isNsfwSourceUrl(payload.sourceUrl) || isNsfwSourceUrl(sender?.tab?.url);
}

async function openCivitAiBrowseTab(
    endpoint: string,
    body: Record<string, unknown>,
    sendResponse: RuntimeSendResponse,
): Promise<void> {
    const stored = await getStoredOptions();
    const atlasDomain = stored.atlasDomain.trim().replace(/\/+$/, '');
    const apiToken = stored.apiToken.trim();
    if (atlasDomain === '' || apiToken === '') {
        sendResponse({ ok: false, status: 0, payload: null });
        return;
    }

    const response = await fetch(`${atlasDomain}/api/extension/browse-tabs/${endpoint}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Atlas-Api-Key': apiToken,
        },
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

export function handleOpenCivitAiModelTabRuntimeMessage(
    message: unknown,
    sender: RuntimeMessageSender,
    sendResponse: RuntimeSendResponse,
): boolean {
    if (!message || typeof message !== 'object') {
        return false;
    }

    const payload = message as OpenCivitAiModelTabPayload;
    if (payload.type !== 'ATLAS_OPEN_CIVITAI_MODEL_TAB') {
        return false;
    }

    const modelId = parsePositiveInteger(payload.modelId);
    const modelVersionId = parsePositiveInteger(payload.modelVersionId);

    if (modelId === null) {
        sendResponse({ ok: false, status: 0, payload: null });
        return false;
    }

    const body: Record<string, unknown> = {
        model_id: modelId,
        model_version_id: modelVersionId,
    };

    if (resolveCivitAiNsfw(payload, sender)) {
        body.nsfw = true;
    }

    void openCivitAiBrowseTab('civitai-model', body, sendResponse).catch(() => {
        sendResponse({ ok: false, status: 0, payload: null });
    });

    return true;
}

export function handleOpenCivitAiUsernameTabRuntimeMessage(
    message: unknown,
    sender: RuntimeMessageSender,
    sendResponse: RuntimeSendResponse,
): boolean {
    if (!message || typeof message !== 'object') {
        return false;
    }

    const payload = message as OpenCivitAiUsernameTabPayload;
    if (payload.type !== 'ATLAS_OPEN_CIVITAI_USERNAME_TAB') {
        return false;
    }

    const username = parseNonEmptyString(payload.username, 255);
    if (username === null) {
        sendResponse({ ok: false, status: 0, payload: null });
        return false;
    }

    const body: Record<string, unknown> = { username };

    if (resolveCivitAiNsfw(payload, sender)) {
        body.nsfw = true;
    }

    void openCivitAiBrowseTab('civitai-user', body, sendResponse).catch(() => {
        sendResponse({ ok: false, status: 0, payload: null });
    });

    return true;
}
