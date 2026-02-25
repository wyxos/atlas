export type ReactionBroadcastEvent = {
  url: string;
  referrerUrl: string | null;
  previewUrl: string | null;
  reactionType: string | null;
  downloaded: boolean;
  blacklisted: boolean;
  downloadProgress: number | null;
  downloadedAt: string | null;
};

export function buildReactionBroadcastEvent(requestPayload: unknown, result: unknown): ReactionBroadcastEvent | null {
  const payload = requestPayload as {
    url?: unknown;
    referrer_url?: unknown;
    preview_url?: unknown;
    type?: unknown;
  } | null;
  const response = result as {
    data?: {
      file?: {
        downloaded?: unknown;
        blacklisted_at?: unknown;
        download_progress?: unknown;
        downloaded_at?: unknown;
        referrer_url?: unknown;
        preview_url?: unknown;
      } | null;
      reaction?: {
        type?: unknown;
      } | null;
    } | null;
  } | null;

  const rawUrl = typeof payload?.url === 'string' ? payload.url.trim() : '';
  if (!rawUrl) {
    return null;
  }

  const file = response?.data?.file || null;
  const downloadProgressRaw = Number(file?.download_progress);
  const downloadProgress = Number.isFinite(downloadProgressRaw)
    ? Math.max(0, Math.min(100, downloadProgressRaw))
    : null;
  const downloadedAtRaw = typeof file?.downloaded_at === 'string' ? file.downloaded_at.trim() : '';
  const referrerUrlRaw = typeof file?.referrer_url === 'string'
    ? file.referrer_url.trim()
    : typeof payload?.referrer_url === 'string'
      ? payload.referrer_url.trim()
      : '';
  const previewUrlRaw = typeof file?.preview_url === 'string'
    ? file.preview_url.trim()
    : typeof payload?.preview_url === 'string'
      ? payload.preview_url.trim()
      : '';

  const reactionType = (() => {
    if (typeof response?.data?.reaction?.type === 'string') {
      return response.data.reaction.type;
    }
    if (typeof payload?.type === 'string') {
      return payload.type;
    }

    return null;
  })();

  return {
    url: rawUrl,
    referrerUrl: referrerUrlRaw || null,
    previewUrl: previewUrlRaw || null,
    reactionType,
    downloaded: Boolean(file?.downloaded),
    blacklisted: Boolean(file?.blacklisted_at),
    downloadProgress,
    downloadedAt: downloadedAtRaw || null,
  };
}
