import { stripHash } from './network';

export function buildLookupKeys(...values: Array<string | null | undefined>): string[] {
  const keys = new Set<string>();
  for (const value of values) {
    const raw = (value || '').trim();
    if (!raw) {
      continue;
    }

    keys.add(raw);
    const normalized = stripHash(raw);
    if (normalized) {
      keys.add(normalized);
    }
  }

  return [...keys];
}
