import type { BadgeMatchResult } from './reaction-check-queue';

export function emptyMatchResult(): BadgeMatchResult {
    return {
        exists: false,
        reaction: null,
        reactedAt: null,
        downloadedAt: null,
        blacklistedAt: null,
    };
}

export function isTerminalStatus(status: string | null): boolean {
    if (status === null) {
        return false;
    }

    return status === 'completed' || status === 'failed' || status === 'canceled';
}

export function preserveTrackedMatchResult(match: BadgeMatchResult): BadgeMatchResult {
    return {
        ...emptyMatchResult(),
        exists: match.exists,
        reaction: match.reaction,
        reactedAt: match.reactedAt,
        downloadedAt: match.downloadedAt,
        blacklistedAt: match.blacklistedAt,
    };
}

export function shouldPreserveTrackedTransfer(options: {
    isDownloadLocked: boolean;
    trackedFileId: number | null;
    trackedTransferId: number | null;
    transferStatus: string | null;
}): boolean {
    return options.isDownloadLocked || (
        (options.trackedFileId !== null || options.trackedTransferId !== null)
        && options.transferStatus !== null
        && !isTerminalStatus(options.transferStatus)
    );
}
