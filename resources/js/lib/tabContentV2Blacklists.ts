import type { FeedItem } from '@/composables/useTabs';
import type { ContainerBlacklist } from '@/types/container-blacklist';

export function itemMatchesContainerBlacklist(item: FeedItem, blacklist: ContainerBlacklist): boolean {
    const containers = Array.isArray(item.containers) ? item.containers : [];

    return containers.some((container) => {
        if (!container || typeof container !== 'object') {
            return false;
        }

        const candidate = container as {
            id?: number;
            source?: string;
            source_id?: string;
        };

        return candidate.id === blacklist.id
            || (
                candidate.source === blacklist.source
                && candidate.source_id === blacklist.source_id
            );
    });
}

export function matchContainerBlacklist(left: ContainerBlacklist, right: ContainerBlacklist): boolean {
    return left.id === right.id
        || (
            left.source === right.source
            && left.source_id === right.source_id
        );
}

export function upsertContainerBlacklist(current: ContainerBlacklist[], blacklist: ContainerBlacklist): ContainerBlacklist[] {
    return [
        blacklist,
        ...current.filter((candidate) => !matchContainerBlacklist(candidate, blacklist)),
    ];
}

export function removeContainerBlacklist(current: ContainerBlacklist[], blacklist: ContainerBlacklist): ContainerBlacklist[] {
    return current.filter((candidate) => !matchContainerBlacklist(candidate, blacklist));
}

export function filterItemsByContainerBlacklists(candidateItems: FeedItem[], activeBlacklists: ContainerBlacklist[]): FeedItem[] {
    if (activeBlacklists.length === 0) {
        return candidateItems;
    }

    const blacklistedItemIds = new Set(candidateItems
        .filter((item) => activeBlacklists.some((blacklist) => itemMatchesContainerBlacklist(item, blacklist)))
        .map((item) => item.id));

    if (blacklistedItemIds.size === 0) {
        return candidateItems;
    }

    return candidateItems.filter((item) => !blacklistedItemIds.has(item.id));
}
