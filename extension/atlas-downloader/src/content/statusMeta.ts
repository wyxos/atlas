export function normalizeProgress(value: unknown): number | null {
  const parsed = Number(value);
  if (!Number.isFinite(parsed)) {
    return null;
  }

  return Math.max(0, Math.min(100, parsed));
}

export function normalizeDownloadedAt(value: unknown): string | null {
  if (typeof value !== 'string') {
    return null;
  }

  const trimmed = value.trim();
  if (!trimmed) {
    return null;
  }

  const date = new Date(trimmed);
  if (Number.isNaN(date.getTime())) {
    return null;
  }

  return trimmed;
}

export function parseFileStatusMeta(file: unknown): { downloadProgress: number | null; downloadedAt: string | null } {
  if (!file || typeof file !== 'object') {
    return {
      downloadProgress: null,
      downloadedAt: null,
    };
  }

  const value = file as { download_progress?: unknown; downloaded_at?: unknown; updated_at?: unknown; downloaded?: unknown };
  const downloaded = Boolean(value.downloaded);
  const downloadedAt = normalizeDownloadedAt(value.downloaded_at);
  return {
    downloadProgress: normalizeProgress(value.download_progress),
    downloadedAt: downloadedAt ?? (downloaded ? normalizeDownloadedAt(value.updated_at) : null),
  };
}
