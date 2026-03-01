import { normalizeUrl } from './media-utils';
import type { BadgeMatchResult, BadgeReactionType } from './reaction-check-queue';
import type { ProgressEvent } from './download-progress-bus';

export type PersistedBadgeState = {
    exists: boolean;
    reaction: BadgeReactionType | null;
    fileId: number | null;
    transferId: number | null;
    status: string | null;
    percent: number | null;
    isDownloadLocked: boolean;
    downloadedAt: string | null;
    blacklistedAt: string | null;
    updatedAt: number;
};

type PersistInput = Partial<PersistedBadgeState>;

const stateByUrl = new Map<string, PersistedBadgeState>();
const urlByFileId = new Map<number, string>();
const urlByTransferId = new Map<number, string>();

function now(): number {
    return Date.now();
}

function isTerminalStatus(status: string | null): boolean {
    return status === 'completed' || status === 'failed' || status === 'canceled';
}

function isActiveTransferStatus(status: string | null): boolean {
    return status === 'queued' || status === 'downloading' || status === 'pending';
}

function ensureState(url: string): PersistedBadgeState {
    const existing = stateByUrl.get(url);
    if (existing) {
        return existing;
    }

    const created: PersistedBadgeState = {
        exists: false,
        reaction: null,
        fileId: null,
        transferId: null,
        status: null,
        percent: null,
        isDownloadLocked: false,
        downloadedAt: null,
        blacklistedAt: null,
        updatedAt: now(),
    };
    stateByUrl.set(url, created);
    return created;
}

function rememberIdentity(url: string, fileId: number | null, transferId: number | null): void {
    if (fileId !== null) {
        urlByFileId.set(fileId, url);
    }
    if (transferId !== null) {
        urlByTransferId.set(transferId, url);
    }
}

function normalizeMediaUrl(url: string | null): string | null {
    return normalizeUrl(url);
}

export function getPersistedBadgeState(mediaUrl: string | null): PersistedBadgeState | null {
    const normalized = normalizeMediaUrl(mediaUrl);
    if (normalized === null) {
        return null;
    }

    const state = stateByUrl.get(normalized);
    return state ? { ...state } : null;
}

export function persistBadgeState(mediaUrl: string | null, input: PersistInput): void {
    const normalized = normalizeMediaUrl(mediaUrl);
    if (normalized === null) {
        return;
    }

    const state = ensureState(normalized);
    const next: PersistedBadgeState = {
        ...state,
        ...input,
        updatedAt: now(),
    };

    if (next.status !== null && isTerminalStatus(next.status)) {
        next.isDownloadLocked = false;
    }
    if (next.downloadedAt !== null || next.blacklistedAt !== null) {
        next.isDownloadLocked = false;
    }

    stateByUrl.set(normalized, next);
    rememberIdentity(normalized, next.fileId, next.transferId);
}

export function persistBadgeCheckResult(mediaUrl: string | null, result: BadgeMatchResult): void {
    const normalized = normalizeMediaUrl(mediaUrl);
    if (normalized === null) {
        return;
    }

    const existing = stateByUrl.get(normalized) ?? null;
    const shouldKeepLocalReaction = existing !== null
        && existing.reaction !== null
        && result.reaction === null
        && (
            existing.isDownloadLocked
            || isActiveTransferStatus(existing.status)
            || existing.exists
        );

    const nextReaction = shouldKeepLocalReaction ? existing?.reaction ?? null : result.reaction;
    const nextExists = shouldKeepLocalReaction ? true : result.exists;

    const input: PersistInput = {
        exists: nextExists,
        reaction: nextReaction,
    };

    if (result.downloadedAt !== null) {
        input.downloadedAt = result.downloadedAt;
    }
    if (result.blacklistedAt !== null) {
        input.blacklistedAt = result.blacklistedAt;
    }

    persistBadgeState(normalized, input);
}

export function persistDownloadProgressEvent(event: ProgressEvent): void {
    const byTransfer = event.transferId !== null ? urlByTransferId.get(event.transferId) : null;
    const byFile = event.fileId !== null ? urlByFileId.get(event.fileId) : null;
    const url = byTransfer ?? byFile;
    if (!url) {
        return;
    }

    const state = ensureState(url);
    const nextStatus = event.status ?? state.status;
    const nextPercent = event.percent ?? state.percent;
    const locked = nextStatus !== null ? !isTerminalStatus(nextStatus) : state.isDownloadLocked;

    const next: PersistedBadgeState = {
        ...state,
        fileId: event.fileId ?? state.fileId,
        transferId: event.transferId ?? state.transferId,
        status: nextStatus,
        percent: nextPercent,
        isDownloadLocked: locked,
        updatedAt: now(),
    };

    stateByUrl.set(url, next);
    rememberIdentity(url, next.fileId, next.transferId);
}
