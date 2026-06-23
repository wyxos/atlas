import type { ProposedTabRuntime } from './tab-runtime';
import type { ProposedReactionType, ProposedReverbEvent, ProposedReverbEventName } from './types';

type ProposedTabReverbClient = {
    onEvent: (handler: (eventName: string, payload: Record<string, unknown>) => void) => { unsubscribe: () => void };
    disconnect?: () => void;
};

type ProposedTabReverbConnection =
    | { kind: 'connected'; client: ProposedTabReverbClient }
    | { kind: 'setup_required' | 'auth_failed' | 'offline' | 'unavailable' | 'disconnected'; detail?: string | null };

type ProposedTabReverbListenerOptions = {
    runtime: ProposedTabRuntime;
    connect: () => Promise<ProposedTabReverbConnection>;
    sendMessage?: (message: unknown) => void;
};

function asString(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function asNumber(value: unknown): number | null {
    if (typeof value === 'number' && Number.isFinite(value)) {
        return value;
    }

    if (typeof value === 'string') {
        const parsed = Number(value.trim());
        return Number.isFinite(parsed) ? parsed : null;
    }

    return null;
}

function asReaction(value: unknown): ProposedReactionType | null {
    return value === 'love' || value === 'like' || value === 'funny' ? value : null;
}

function asEventName(value: string): ProposedReverbEventName | null {
    if (
        value === 'DownloadTransferCreated'
        || value === 'DownloadTransferQueued'
        || value === 'DownloadTransferProgressUpdated'
    ) {
        return value;
    }

    return null;
}

function optionalString(payload: Record<string, unknown>, ...keys: string[]): string | null | undefined {
    for (const key of keys) {
        if (key in payload) {
            return asString(payload[key]);
        }
    }

    return undefined;
}

function normalizeReverbEvent(eventName: string, payload: Record<string, unknown>): ProposedReverbEvent | null {
    const knownEventName = asEventName(eventName);
    if (knownEventName === null) {
        return null;
    }

    return {
        eventName: knownEventName,
        referrerUrl: asString(payload.referrer_url ?? payload.referrerUrl ?? payload.page_url),
        sourceUrl: asString(payload.original ?? payload.url ?? payload.file_url),
        reaction: asReaction(payload.reaction ?? payload.reactionType ?? payload.reaction_type),
        reactedAt: optionalString(payload, 'reacted_at', 'reactedAt'),
        downloadedAt: optionalString(payload, 'downloaded_at', 'downloadedAt'),
        blacklistedAt: optionalString(payload, 'blacklisted_at', 'blacklistedAt'),
        fileId: asNumber(payload.fileId ?? payload.file_id),
        transferId: asNumber(payload.downloadTransferId ?? payload.id),
        status: asString(payload.status),
        percent: asNumber(payload.percent),
        payload,
    };
}

export function createProposedTabReverbListener(options: ProposedTabReverbListenerOptions) {
    let activeClient: ProposedTabReverbClient | null = null;
    let activeSubscription: { unsubscribe: () => void } | null = null;

    function stop(): void {
        activeSubscription?.unsubscribe();
        activeSubscription = null;
        activeClient?.disconnect?.();
        activeClient = null;
    }

    return {
        async start(): Promise<ProposedTabReverbConnection> {
            stop();
            const connection = await options.connect();
            if (connection.kind !== 'connected') {
                return connection;
            }

            activeClient = connection.client;
            activeSubscription = connection.client.onEvent((eventName, payload) => {
                const event = normalizeReverbEvent(eventName, payload);
                if (event !== null) {
                    options.runtime.handleReverbEvent(event);
                }
            });

            return connection;
        },
        stop,
    };
}

export {
    normalizeReverbEvent,
};
