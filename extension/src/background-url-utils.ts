export function normalizeComparableUrl(value: string): string | null {
    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }

    try {
        const parsed = new URL(trimmed);
        if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
            return null;
        }

        parsed.hash = '';
        return parsed.toString();
    } catch {
        return null;
    }
}

export function normalizeComparableUrls(values: unknown): string[] {
    if (!Array.isArray(values)) {
        return [];
    }

    const normalized = values
        .map((value) => (typeof value === 'string' ? normalizeComparableUrl(value) : null))
        .filter((value): value is string => value !== null);

    return Array.from(new Set(normalized));
}
