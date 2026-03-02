export type AtlasRequestStatus = number | 'runtime_unavailable' | 'network_error';

export type AtlasRequestLogEntry = {
    id: number;
    timestamp: string;
    endpoint: string;
    method: string;
    requestPayload: unknown;
    responsePayload: unknown;
    status: AtlasRequestStatus;
    durationMs: number;
};

type AtlasRequestListener = (entries: AtlasRequestLogEntry[]) => void;

type AtlasRuntimeResponse = {
    ok: boolean;
    status: number;
    payload: unknown;
};

const MAX_ENTRIES = 20;

const listeners = new Set<AtlasRequestListener>();
let entries: AtlasRequestLogEntry[] = [];
let nextId = 1;

function notifyListeners(): void {
    for (const listener of listeners) {
        listener(entries);
    }
}

function pushLogEntry(row: Omit<AtlasRequestLogEntry, 'id' | 'timestamp'>): void {
    entries = [
        {
            id: nextId++,
            timestamp: new Date().toLocaleTimeString(),
            ...row,
        },
        ...entries,
    ].slice(0, MAX_ENTRIES);

    notifyListeners();
}

function resolveDurationMs(startedAt: number): number {
    return Math.max(0, Math.round(performance.now() - startedAt));
}

function parseResponsePayload(response: Response, fallbackPayload?: unknown): Promise<unknown> {
    if (fallbackPayload !== undefined) {
        return Promise.resolve(fallbackPayload);
    }

    const responseRecord = response as unknown as {
        clone?: () => { text?: () => Promise<string> };
        json?: () => Promise<unknown>;
        text?: () => Promise<string>;
    };

    if (typeof responseRecord.clone === 'function') {
        const cloned = responseRecord.clone();
        if (cloned && typeof cloned.text === 'function') {
            return cloned.text()
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
    }

    if (typeof responseRecord.json === 'function') {
        return responseRecord.json().catch(() => null);
    }

    if (typeof responseRecord.text === 'function') {
        return responseRecord.text().catch(() => null);
    }

    return Promise.resolve(null);
}

export function subscribeToAtlasRequestLog(listener: AtlasRequestListener): () => void {
    listeners.add(listener);
    listener(entries);

    return () => {
        listeners.delete(listener);
    };
}

export function clearAtlasRequestLog(): void {
    entries = [];
    notifyListeners();
}

export function getAtlasRequestLogSnapshot(): AtlasRequestLogEntry[] {
    return entries;
}

export async function atlasLoggedFetch(
    endpoint: string,
    method: string,
    requestPayload: unknown,
    init: RequestInit,
    responsePayloadOverride?: unknown,
): Promise<Response> {
    const startedAt = performance.now();

    try {
        const response = await fetch(endpoint, init);
        const responsePayload = await parseResponsePayload(response, responsePayloadOverride);
        pushLogEntry({
            endpoint,
            method,
            requestPayload,
            responsePayload,
            status: response.status,
            durationMs: resolveDurationMs(startedAt),
        });

        return response;
    } catch (error) {
        pushLogEntry({
            endpoint,
            method,
            requestPayload,
            responsePayload: error instanceof Error ? error.message : 'Request failed',
            status: 'network_error',
            durationMs: resolveDurationMs(startedAt),
        });

        throw error;
    }
}

export async function atlasLoggedRuntimeRequest(
    endpoint: string,
    method: string,
    requestPayload: unknown,
    run: () => Promise<AtlasRuntimeResponse | null>,
): Promise<AtlasRuntimeResponse | null> {
    const startedAt = performance.now();

    try {
        const runtimeResponse = await run();
        if (runtimeResponse === null) {
            pushLogEntry({
                endpoint,
                method,
                requestPayload,
                responsePayload: 'Runtime response unavailable',
                status: 'runtime_unavailable',
                durationMs: resolveDurationMs(startedAt),
            });
            return null;
        }

        pushLogEntry({
            endpoint,
            method,
            requestPayload,
            responsePayload: runtimeResponse.payload,
            status: runtimeResponse.status,
            durationMs: resolveDurationMs(startedAt),
        });

        return runtimeResponse;
    } catch (error) {
        pushLogEntry({
            endpoint,
            method,
            requestPayload,
            responsePayload: error instanceof Error ? error.message : 'Runtime request failed',
            status: 'network_error',
            durationMs: resolveDurationMs(startedAt),
        });

        throw error;
    }
}
