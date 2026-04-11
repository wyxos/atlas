import type { FeedItem } from '@/composables/useTabs';

export type BrowsePageToken = number | string;

export type BrowseFeedMutationTarget = FeedItem | FeedItem[] | string | string[];

export type BrowseFeedMutationResult = {
    ids: string[];
} | void;

export interface BrowseFeedHandle {
    cancel: () => void;
    hasReachedEnd: boolean;
    isLoading: boolean;
    loadNextPage?: () => Promise<void> | void;
    nextPage?: BrowsePageToken | null;
    remove: (target: BrowseFeedMutationTarget) => Promise<BrowseFeedMutationResult> | BrowseFeedMutationResult;
    restore: (target: BrowseFeedMutationTarget) => Promise<BrowseFeedMutationResult> | BrowseFeedMutationResult;
}
