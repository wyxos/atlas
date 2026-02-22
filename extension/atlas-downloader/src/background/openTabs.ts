type BrowserTab = {
  url?: string;
};

export function normalizeTabUrlForOpenState(rawUrl: string): string {
  if (!rawUrl || typeof rawUrl !== 'string') {
    return '';
  }

  const trimmed = rawUrl.trim();
  if (!trimmed) {
    return '';
  }

  try {
    const parsed = new URL(trimmed);
    const protocol = parsed.protocol.toLowerCase();
    if (protocol !== 'http:' && protocol !== 'https:') {
      return '';
    }

    parsed.hash = '';
    parsed.hostname = parsed.hostname.toLowerCase();
    return parsed.toString();
  } catch {
    return '';
  }
}

export function collectOpenTabUrls(tabs: BrowserTab[]): string[] {
  const normalized = new Set<string>();
  for (const tab of tabs) {
    const url = normalizeTabUrlForOpenState(tab?.url || '');
    if (url) {
      normalized.add(url);
    }
  }

  return [...normalized];
}
