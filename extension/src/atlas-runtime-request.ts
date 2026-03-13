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

export async function requestAtlasViaRuntime(
    options: RuntimeAtlasRequestOptions,
): Promise<RuntimeAtlasResponse | null> {
    if (typeof chrome === 'undefined' || !chrome.runtime || typeof chrome.runtime.sendMessage !== 'function') {
        return null;
    }

    return new Promise((resolve) => {
        try {
            chrome.runtime.sendMessage(
                {
                    type: 'ATLAS_API_REQUEST',
                    endpoint: options.endpoint,
                    atlasDomain: options.atlasDomain,
                    apiToken: options.apiToken,
                    method: options.method,
                    body: options.body ?? null,
                },
                (response: unknown) => {
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
                },
            );
        } catch {
            resolve(null);
        }
    });
}
