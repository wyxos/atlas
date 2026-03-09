const COMPARABLE_PROTOCOLS = new Set(['http:', 'https:']);

function isComparableProtocol(protocol: string): boolean {
    return COMPARABLE_PROTOCOLS.has(protocol);
}

function isPlainRootUrl(parsed: URL): boolean {
    return parsed.pathname === '/' && parsed.search === '' && parsed.hash === '';
}

export function normalizeComparableOpenTabUrl(value: string | null | undefined): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }

    try {
        const parsed = new URL(trimmed);
        if (!isComparableProtocol(parsed.protocol) || isPlainRootUrl(parsed)) {
            return null;
        }

        return parsed.toString();
    } catch {
        return null;
    }
}

export function normalizeComparableOpenTabUrls(values: unknown): string[] {
    if (!Array.isArray(values)) {
        return [];
    }

    const normalized = values
        .map((value) => (typeof value === 'string' ? normalizeComparableOpenTabUrl(value) : null))
        .filter((value): value is string => value !== null);

    return Array.from(new Set(normalized));
}
