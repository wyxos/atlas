import type { VibeHandle, VibeViewerItem } from '@wyxos/vibe';
import type { FeedItem } from '@/composables/useTabs';

export type AtlasVibeHandle = VibeHandle & {
    getItemByOccurrenceKey: (occurrenceKey: string) => VibeViewerItem | null;
    getItems: () => VibeViewerItem[];
};

export function getFeedItemFromVibeItem(item: VibeViewerItem): FeedItem | null {
    return (item.feedItem as FeedItem | undefined) ?? null;
}

export function getFeedItemsFromVibeHandle(handle: AtlasVibeHandle | null, fallbackItems: FeedItem[]): FeedItem[] {
    if (!handle) {
        return fallbackItems;
    }

    return handle.getItems().map(getFeedItemFromVibeItem).filter((item): item is FeedItem => item !== null);
}

export function getFeedItemFromVibeOccurrenceTarget(
    handle: AtlasVibeHandle | null,
    target: EventTarget | null,
): FeedItem | null {
    if (!(target instanceof Element)) {
        return null;
    }

    const occurrenceElement = target.closest<HTMLElement>('[data-occurrence-key]');
    const occurrenceKey = occurrenceElement?.dataset.occurrenceKey;
    if (!occurrenceKey) {
        return null;
    }

    const item = handle?.getItemByOccurrenceKey(occurrenceKey);
    return item ? getFeedItemFromVibeItem(item) : null;
}
