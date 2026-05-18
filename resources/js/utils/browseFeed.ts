export type BrowseFeed = 'online' | 'local';

export function resolveBrowseFeed(params?: Record<string, unknown> | null): BrowseFeed {
    if (params?.feed === 'local') {
        return 'local';
    }

    if (params?.service === 'local') {
        return 'local';
    }

    return 'online';
}
