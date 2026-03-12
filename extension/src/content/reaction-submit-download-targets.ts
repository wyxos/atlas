export type DownloadCloseTarget = {
    fileId: number | null;
    transferId: number | null;
    status: string | null;
    downloadedAt: string | null;
};

function numberOrNull(value: unknown): number | null {
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

function stringOrNull(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function parseDownloadCloseTarget(value: unknown, fallbackFileId: number | null = null): DownloadCloseTarget | null {
    if (!value || typeof value !== 'object') {
        return null;
    }

    const row = value as Record<string, unknown>;
    const download = row.download && typeof row.download === 'object'
        ? row.download as Record<string, unknown>
        : null;
    if (download === null) {
        return null;
    }

    const file = row.file && typeof row.file === 'object'
        ? row.file as Record<string, unknown>
        : null;
    const fileId = numberOrNull(file?.id ?? row.file_id ?? fallbackFileId);
    const transferId = numberOrNull(download.transfer_id);
    const status = stringOrNull(download.status);
    const downloadedAt = stringOrNull(download.downloaded_at);
    const requested = download.requested === true;

    if (!requested && transferId === null && status === null && downloadedAt === null) {
        return null;
    }

    return {
        fileId,
        transferId,
        status,
        downloadedAt,
    };
}

export function getDownloadCloseTargets(payload: unknown, primaryFileId: number | null): DownloadCloseTarget[] {
    if (!payload || typeof payload !== 'object') {
        return [];
    }

    const root = payload as Record<string, unknown>;
    const targets: DownloadCloseTarget[] = [];
    const seenKeys = new Set<string>();

    const pushTarget = (target: DownloadCloseTarget | null): void => {
        if (target === null) {
            return;
        }

        const key = target.transferId !== null
            ? `transfer:${target.transferId}`
            : target.fileId !== null
                ? `file:${target.fileId}`
                : target.downloadedAt !== null
                    ? `downloaded:${target.downloadedAt}`
                    : null;

        if (key !== null) {
            if (seenKeys.has(key)) {
                return;
            }

            seenKeys.add(key);
        }

        targets.push(target);
    };

    pushTarget(parseDownloadCloseTarget(root, primaryFileId));

    const batch = root.batch;
    const items = batch && typeof batch === 'object'
        ? (batch as Record<string, unknown>).items
        : null;
    if (!Array.isArray(items)) {
        return targets;
    }

    for (const entry of items) {
        pushTarget(parseDownloadCloseTarget(entry));
    }

    return targets;
}
