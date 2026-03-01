import { type ReverbSubscription } from '../reverb-client';
import { connectRuntimeReverb } from '../reverb-runtime';
import type { BadgeReactionType } from './reaction-check-queue';

export type ProgressEvent = {
    event: 'DownloadTransferCreated' | 'DownloadTransferQueued' | 'DownloadTransferProgressUpdated';
    fileId: number | null;
    transferId: number | null;
    sourceUrl: string | null;
    referrerUrl: string | null;
    status: string | null;
    percent: number | null;
    reaction: BadgeReactionType | null;
    payload: Record<string, unknown>;
};

type BusListener = (event: ProgressEvent) => void;

const listeners = new Set<BusListener>();
let connectionPromise: Promise<void> | null = null;
let activeSubscription: ReverbSubscription | null = null;
let activeClient: { disconnect: () => void } | null = null;

function asNumber(value: unknown): number | null {
    if (typeof value === 'number' && Number.isFinite(value)) {
        return value;
    }

    if (typeof value === 'string') {
        const trimmed = value.trim();
        if (trimmed === '') {
            return null;
        }

        const parsed = Number(trimmed);
        return Number.isFinite(parsed) ? parsed : null;
    }

    return null;
}

function asString(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function asReaction(value: unknown): BadgeReactionType | null {
    if (value === 'love' || value === 'like' || value === 'dislike' || value === 'funny') {
        return value;
    }

    return null;
}

function parseReaction(payload: Record<string, unknown>): BadgeReactionType | null {
    const direct = asReaction(payload.reaction);
    if (direct !== null) {
        return direct;
    }

    const reactionType = asReaction(payload.reactionType ?? payload.reaction_type);
    if (reactionType !== null) {
        return reactionType;
    }

    if (payload.reaction && typeof payload.reaction === 'object') {
        const nested = payload.reaction as Record<string, unknown>;
        return asReaction(nested.type);
    }

    return null;
}

async function ensureConnected(): Promise<void> {
    if (connectionPromise) {
        return connectionPromise;
    }
    if (activeClient && activeSubscription) {
        return;
    }

    connectionPromise = (async () => {
        const runtime = await connectRuntimeReverb();
        if (runtime.kind !== 'connected') {
            return;
        }

        activeClient = runtime.client;
        activeSubscription = runtime.client.onEvent((event, payload) => {
            const progressEvent: ProgressEvent = {
                event,
                fileId: asNumber(payload.fileId ?? payload.file_id),
                transferId: asNumber(payload.downloadTransferId ?? payload.id),
                sourceUrl: asString(payload.original ?? payload.url ?? payload.file_url),
                referrerUrl: asString(payload.referrer_url ?? payload.referrerUrl ?? payload.page_url),
                status: asString(payload.status),
                percent: asNumber(payload.percent),
                reaction: parseReaction(payload),
                payload,
            };

            listeners.forEach((listener) => {
                listener(progressEvent);
            });
        });

        if (listeners.size === 0) {
            teardownIfUnused();
        }
    })().finally(() => {
        connectionPromise = null;
    });

    return connectionPromise;
}

function teardownIfUnused(): void {
    if (listeners.size > 0) {
        return;
    }

    if (activeSubscription) {
        activeSubscription.unsubscribe();
        activeSubscription = null;
    }
    if (activeClient) {
        activeClient.disconnect();
        activeClient = null;
    }
}

export function subscribeToDownloadProgress(listener: BusListener): () => void {
    listeners.add(listener);
    void ensureConnected();

    return () => {
        listeners.delete(listener);
        teardownIfUnused();
    };
}
