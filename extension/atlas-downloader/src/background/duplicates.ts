type BrowserTab = {
  id?: number;
  url?: string;
  active?: boolean;
};

export function normalizeTabUrlForDuplicateCheck(rawUrl: string): string {
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

export function findDuplicateTabId(
  tabs: BrowserTab[],
  candidateTabId: number,
  candidateUrl: string
): number | null {
  const normalizedCandidate = normalizeTabUrlForDuplicateCheck(candidateUrl);
  if (!normalizedCandidate) {
    return null;
  }

  for (const tab of tabs) {
    if (!tab || typeof tab.id !== 'number' || tab.id === candidateTabId) {
      continue;
    }

    if (normalizeTabUrlForDuplicateCheck(tab.url || '') === normalizedCandidate) {
      return tab.id;
    }
  }

  return null;
}

export function pickDuplicateNoticeTargetTabId(
  tabs: BrowserTab[],
  candidateTabId: number,
  duplicateTabId: number,
  openerTabId: number | null | undefined,
): number | null {
  if (
    typeof openerTabId === 'number'
    && openerTabId > 0
    && openerTabId !== candidateTabId
    && openerTabId !== duplicateTabId
  ) {
    return openerTabId;
  }

  const activeTab = tabs.find((tab) => tab?.active && typeof tab.id === 'number' && tab.id !== candidateTabId);
  if (typeof activeTab?.id === 'number') {
    return activeTab.id;
  }

  return null;
}
