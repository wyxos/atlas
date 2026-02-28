export type DomainIncludeRule = {
  domain: string;
  patterns: string[];
};

export function normalizeDomain(input: string): string {
  let value = (input || '').trim().toLowerCase();
  if (!value) {
    return '';
  }

  if (value.startsWith('*.')) {
    value = value.slice(2);
  }

  if (/^https?:\/\//i.test(value)) {
    try {
      return new URL(value).hostname.toLowerCase();
    } catch {
      return '';
    }
  }

  value = value.replace(/\/.*$/, '');
  value = value.replace(/^\.+/, '');
  return value;
}

export function normalizeRegexSource(input: string): string {
  const value = (input || '').trim();
  if (!value) {
    return '';
  }

  if (value.startsWith('/') && value.lastIndexOf('/') > 0) {
    const lastSlash = value.lastIndexOf('/');
    const source = value.slice(1, lastSlash).trim();
    const flags = value.slice(lastSlash + 1).trim();
    if (source && /^[gimsuy]*$/i.test(flags)) {
      return source;
    }
  }

  return value;
}

export function isValidRegexSource(source: string): boolean {
  try {
    void new RegExp(source, 'i');
    return true;
  } catch {
    return false;
  }
}

export function parseDomainIncludeRules(raw: unknown): DomainIncludeRule[] {
  let parsed: unknown = raw;
  if (typeof raw === 'string') {
    if (!raw.trim()) {
      return [];
    }

    try {
      parsed = JSON.parse(raw);
    } catch {
      return [];
    }
  }

  if (!Array.isArray(parsed)) {
    return [];
  }

  const byDomain = new Map<string, Set<string>>();
  for (const entry of parsed) {
    if (!entry || typeof entry !== 'object') {
      continue;
    }

    const domain = normalizeDomain(typeof (entry as { domain?: unknown }).domain === 'string'
      ? String((entry as { domain?: unknown }).domain)
      : '');
    if (!domain) {
      continue;
    }

    if (!byDomain.has(domain)) {
      byDomain.set(domain, new Set<string>());
    }

    const bucket = byDomain.get(domain) as Set<string>;
    const rawPatterns = Array.isArray((entry as { patterns?: unknown }).patterns)
      ? (entry as { patterns: unknown[] }).patterns
      : [];

    for (const rawPattern of rawPatterns) {
      if (typeof rawPattern !== 'string') {
        continue;
      }

      const source = normalizeRegexSource(rawPattern);
      if (!source || !isValidRegexSource(source)) {
        continue;
      }

      bucket.add(source);
    }
  }

  return [...byDomain.entries()]
    .map(([domain, patterns]) => ({
      domain,
      patterns: [...patterns],
    }))
    .sort((a, b) => a.domain.localeCompare(b.domain));
}

export function serializeDomainIncludeRules(rules: DomainIncludeRule[]): string {
  return JSON.stringify(parseDomainIncludeRules(rules));
}
