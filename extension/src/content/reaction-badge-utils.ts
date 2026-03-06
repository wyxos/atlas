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
