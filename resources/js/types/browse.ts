import type { FeedItem } from '@/composables/useTabs';

export type BrowsePageToken = number | string;

export type BrowseFeedMutationTarget = FeedItem | FeedItem[] | string | string[];

export type BrowseFeedMutationResult = {
    ids: string[];
} | void;

export interface BrowseFeedHandle {
    autoScroll?: (speedPxPerSecond: number) => void;
    cancel: () => void;
    cancelFill?: () => void;
    fillUntil?: (count: number) => Promise<void> | void;
    fillUntilEnd?: () => Promise<void> | void;
    getItemByOccurrenceKey?: (occurrenceKey: string) => FeedItem | null;
    getItems?: () => FeedItem[];
    isLoading: boolean;
    loadNextPage?: () => Promise<void> | void;
    lockPageLoading?: () => void;
    pageLoadingLocked?: boolean;
    remove: (target: BrowseFeedMutationTarget) => Promise<BrowseFeedMutationResult> | BrowseFeedMutationResult;
    restore: (target: BrowseFeedMutationTarget) => Promise<BrowseFeedMutationResult> | BrowseFeedMutationResult;
    unlockPageLoading?: () => void;
}
