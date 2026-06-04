import type { VibeViewerItem } from '@wyxos/vibe';
import type { TabContentContainerInteractions } from '@/composables/useTabContentContainerInteractions';
import type { FeedItem } from '@/composables/useTabs';

export function getFeedItemFromVibeItem(item: VibeViewerItem): FeedItem | null {
    return (item.feedItem as FeedItem | undefined) ?? null;
}

export function getContainerPillTargets(
    item: VibeViewerItem,
    containerInteractions: TabContentContainerInteractions,
) {
    const feedItem = getFeedItemFromVibeItem(item);

    return feedItem
        ? containerInteractions.badges?.getContainersForItem(feedItem) ?? []
        : [];
}

export function shouldDimGridItemForContainerDrawer(
    item: VibeViewerItem,
    highlightedItemIds: Set<number>,
): boolean {
    const feedItem = getFeedItemFromVibeItem(item);
    if (!feedItem) {
        return false;
    }

    return highlightedItemIds.size > 0 && !highlightedItemIds.has(feedItem.id);
}
