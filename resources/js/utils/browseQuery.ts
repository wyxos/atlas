const RESERVED_BROWSE_QUERY_KEYS = new Set([
    'service',
    'source',
    'feed',
    'tab_id',
    'page',
    'limit',
    'serviceFilters',
]);

export function appendBrowseServiceFilters(
    params: Record<string, unknown>,
    serviceFilters?: Record<string, unknown>,
): void {
    for (const [key, value] of Object.entries(serviceFilters ?? {})) {
        if (RESERVED_BROWSE_QUERY_KEYS.has(key)) {
            continue;
        }

        params[key] = value;
    }
}
