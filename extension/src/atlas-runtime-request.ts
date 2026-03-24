export type RuntimeAtlasResponse = {
    ok: boolean;
    status: number;
    payload: unknown;
};

type RuntimeAtlasRequestOptions = {
    endpoint: string;
    atlasDomain: string;
    apiToken: string;
    method: 'GET' | 'POST';
    body?: Record<string, unknown>;
};

type RuntimeQueuedBadgeCheckOptions = {
    atlasDomain: string;
    apiToken: string;
    normalizedMediaUrl: string;
};

type RuntimeQueuedReferrerCheckOptions = {
    atlasDomain: string;
    apiToken: string;
    normalizedReferrerUrl: string;
};

function numberOrZero(value: unknown): number {
    if (typeof value === 'number' && Number.isFinite(value)) {
        return value;
    }

    if (typeof value === 'string') {
        const trimmed = value.trim();
        if (trimmed !== '') {
            const parsed = Number(trimmed);
            if (Number.isFinite(parsed)) {
                return parsed;
            }
        }
    }

    return 0;
}

function requestRuntimeBridge(message: Record<string, unknown>): Promise<RuntimeAtlasResponse | null> {
    if (typeof chrome === 'undefined' || !chrome.runtime || typeof chrome.runtime.sendMessage !== 'function') {
        return Promise.resolve(null);
    }

    return new Promise((resolve) => {
        try {
            chrome.runtime.sendMessage(message, (response: unknown) => {
                if (chrome.runtime.lastError) {
                    resolve(null);
                    return;
                }

                if (!response || typeof response !== 'object') {
                    resolve(null);
                    return;
                }

                const row = response as Record<string, unknown>;
                resolve({
                    ok: row.ok === true,
                    status: numberOrZero(row.status),
                    payload: row.payload ?? null,
                });
            });
        } catch {
            resolve(null);
        }
    });
}

export async function requestAtlasViaRuntime(
    options: RuntimeAtlasRequestOptions,
): Promise<RuntimeAtlasResponse | null> {
    return requestRuntimeBridge({
        type: 'ATLAS_API_REQUEST',
        endpoint: options.endpoint,
        atlasDomain: options.atlasDomain,
        apiToken: options.apiToken,
        method: options.method,
        body: options.body ?? null,
    });
}

export async function requestQueuedBadgeCheckViaRuntime(
    options: RuntimeQueuedBadgeCheckOptions,
): Promise<RuntimeAtlasResponse | null> {
    return requestRuntimeBridge({
        type: 'ATLAS_QUEUE_BADGE_CHECK',
        atlasDomain: options.atlasDomain,
        apiToken: options.apiToken,
        normalizedMediaUrl: options.normalizedMediaUrl,
    });
}

export async function requestQueuedReferrerCheckViaRuntime(
    options: RuntimeQueuedReferrerCheckOptions,
): Promise<RuntimeAtlasResponse | null> {
    return requestRuntimeBridge({
        type: 'ATLAS_QUEUE_REFERRER_CHECK',
        atlasDomain: options.atlasDomain,
        apiToken: options.apiToken,
        normalizedReferrerUrl: options.normalizedReferrerUrl,
    });
}
