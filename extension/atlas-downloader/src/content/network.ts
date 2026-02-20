export function stripHash(value: string): string {
  const hashPos = value.indexOf('#');
  if (hashPos === -1) {
    return value;
  }
  return value.slice(0, hashPos);
}

export function parseExcludedDomains(value: unknown): string[] {
  if (!value || typeof value !== 'string') {
    return [];
  }

  return value
    .split(/[\n,]/g)
    .map((entry) => entry.trim())
    .filter((entry) => entry && !entry.startsWith('#'))
    .map((entry) => {
      const wildcard = entry.startsWith('*.') ? entry.slice(2) : entry;
      return wildcard.toLowerCase();
    })
    .map((entry) => resolveHost(entry) || entry.replace(/^\.+/, '').trim())
    .filter(Boolean);
}

export function isHostExcluded(currentHost: string, excludedHosts: string[]): boolean {
  const current = (currentHost || '').toLowerCase();
  if (!current) {
    return false;
  }

  for (const host of excludedHosts) {
    if (isHostMatch(current, host)) {
      return true;
    }
  }

  return false;
}

export function resolveHost(value: unknown): string {
  if (!value || typeof value !== 'string') {
    return '';
  }

  const trimmed = value.trim();
  if (!trimmed) {
    return '';
  }

  const withScheme = /^https?:\/\//i.test(trimmed) ? trimmed : `https://${trimmed}`;
  try {
    return new URL(withScheme).hostname;
  } catch {
    return '';
  }
}

export function isHostMatch(current: string, base: string): boolean {
  if (!current || !base) {
    return false;
  }

  return current === base || current.endsWith(`.${base}`);
}
