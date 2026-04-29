import type { FeedItem } from '@/composables/useTabs';

export type BrowsePageToken = number | string;

export type BrowseFeedMutationTarget = FeedItem | FeedItem[] | string | string[];

export type BrowseFeedMutationResult = {
    ids: string[];
} | void;

export interface BrowseFeedHandle {
    cancel: () => void;
    getItemByOccurrenceKey?: (occurrenceKey: string) => FeedItem | null;
    getItems?: () => FeedItem[];
    hasReachedEnd: boolean;
    isLoading: boolean;
    loadNextPage?: () => Promise<void> | void;
    lockPageLoading?: () => void;
    nextPage?: BrowsePageToken | null;
    pageLoadingLocked?: boolean;
    remove: (target: BrowseFeedMutationTarget) => Promise<BrowseFeedMutationResult> | BrowseFeedMutationResult;
    restore: (target: BrowseFeedMutationTarget) => Promise<BrowseFeedMutationResult> | BrowseFeedMutationResult;
    unlockPageLoading?: () => void;
}
