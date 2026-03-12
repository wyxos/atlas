import { subscribeToDownloadProgress, type ProgressEvent } from './download-progress-bus';
import { requestCloseCurrentTab } from './reaction-badge-tab-runtime';
import type { DownloadCloseTarget } from './reaction-submit-download-targets';

type PendingAutoCloseTarget = {
    transferKey: string | null;
    fileKey: string | null;
};

const pendingTargets = new Map<string, PendingAutoCloseTarget>();
let unsubscribeProgress: (() => void) | null = null;

function transferKey(transferId: number | null): string | null {
    return transferId !== null ? `transfer:${transferId}` : null;
}

function fileKey(fileId: number | null): string | null {
    return fileId !== null ? `file:${fileId}` : null;
}

function isCompletedTarget(target: DownloadCloseTarget): boolean {
    return target.status === 'completed' || target.downloadedAt !== null;
}

function isFailedTarget(target: DownloadCloseTarget): boolean {
    return target.status === 'failed' || target.status === 'canceled';
}

function ensureProgressSubscription(): void {
    if (unsubscribeProgress !== null) {
        return;
    }

    unsubscribeProgress = subscribeToDownloadProgress((event) => {
        handleProgressEvent(event);
    });
}

function teardownProgressSubscription(): void {
    if (pendingTargets.size > 0 || unsubscribeProgress === null) {
        return;
    }

    unsubscribeProgress();
    unsubscribeProgress = null;
}

function clearPendingTargets(): void {
    pendingTargets.clear();
    teardownProgressSubscription();
}

function handleProgressEvent(event: ProgressEvent): void {
    if (pendingTargets.size === 0) {
        teardownProgressSubscription();
        return;
    }

    const eventTransferKey = transferKey(event.transferId);
    const eventFileKey = fileKey(event.fileId);
    const matchedKeys: string[] = [];

    for (const [key, target] of pendingTargets.entries()) {
        if (
            (eventTransferKey !== null && target.transferKey === eventTransferKey)
            || (eventFileKey !== null && target.fileKey === eventFileKey)
        ) {
            matchedKeys.push(key);
        }
    }

    if (matchedKeys.length === 0) {
        return;
    }

    if (event.status === 'failed' || event.status === 'canceled') {
        clearPendingTargets();
        return;
    }

    if (event.status !== 'completed' && event.downloadedAt === null) {
        return;
    }

    for (const key of matchedKeys) {
        pendingTargets.delete(key);
    }

    if (pendingTargets.size > 0) {
        return;
    }

    teardownProgressSubscription();
    void requestCloseCurrentTab();
}

export function queueCloseCurrentTabAfterDownloadComplete(targets: DownloadCloseTarget[]): void {
    if (targets.length === 0) {
        return;
    }

    let hasPendingTargets = false;
    let hasUntrackablePendingTargets = false;

    for (const target of targets) {
        if (isFailedTarget(target)) {
            clearPendingTargets();
            return;
        }

        if (isCompletedTarget(target)) {
            continue;
        }

        const pendingTarget: PendingAutoCloseTarget = {
            transferKey: transferKey(target.transferId),
            fileKey: fileKey(target.fileId),
        };
        const key = pendingTarget.transferKey ?? pendingTarget.fileKey;
        if (key === null) {
            hasUntrackablePendingTargets = true;
            continue;
        }

        hasPendingTargets = true;
        pendingTargets.set(key, pendingTarget);
    }

    if (hasUntrackablePendingTargets) {
        clearPendingTargets();
        return;
    }

    if (!hasPendingTargets) {
        void requestCloseCurrentTab();
        return;
    }

    ensureProgressSubscription();
}
