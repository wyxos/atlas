import { nextTick, triggerRef, type Ref, type ShallowRef } from 'vue';
import type { FeedItem, TabData } from '@/composables/useTabs';
import { queueBatchReaction } from '@/utils/reactionQueue';
import type { BrowseFeedHandle } from '@/types/browse';
import type { ReactionType } from '@/types/reaction';
import {
    applyOptimisticLocalReactionState,
    restoreOptimisticLocalReactionState,
    type LocalReactionSnapshot,
} from '@/utils/localReactionState';

export type LoadedItemsBulkAction =
    | 'love'
    | 'like'
    | 'dislike'
    | 'blacklist';

type BatchBlacklistResponse = {
    results?: Array<{
        id: number;
        blacklisted_at: string;
    }>;
};

type CreateLoadedItemsBulkActionsOptions = {
    getLoadedItems: () => FeedItem[];
    items: ShallowRef<FeedItem[]>;
    tab: Ref<TabData | null>;
    isLocal: Readonly<Ref<boolean>>;
    masonry: Ref<BrowseFeedHandle | null>;
    matchesActiveLocalFilters?: (item: FeedItem) => boolean;
    clearHoverForRemovedItems: (itemIds: Set<number>) => void;
    onReaction: (fileId: number, type: ReactionType) => void;
};

const LOADED_ITEMS_BATCH_SIZE = 100;

export function createLoadedItemsBulkActions(options: CreateLoadedItemsBulkActionsOptions) {
    function getItemsToRemoveAfterLocalMutation(mutatedItems: FeedItem[]): FeedItem[] {
        if (!options.matchesActiveLocalFilters) {
            return [];
        }

        return mutatedItems.filter((item) => !options.matchesActiveLocalFilters?.(item));
    }

    async function removeItemsFromView(itemsToRemove: FeedItem[]): Promise<void> {
        if (itemsToRemove.length === 0) {
            return;
        }

        options.clearHoverForRemovedItems(new Set(itemsToRemove.map((item) => item.id)));

        await options.masonry.value?.remove(itemsToRemove);
    }

    async function syncLocalMutationView(mutatedItems: FeedItem[]): Promise<void> {
        const itemsToRemove = getItemsToRemoveAfterLocalMutation(mutatedItems);

        if (itemsToRemove.length > 0) {
            await removeItemsFromView(itemsToRemove);
        } else {
            triggerRef(options.items);
        }

        await nextTick();
    }

    async function applyBatchReaction(type: ReactionType): Promise<number> {
        const loadedItems = options.getLoadedItems();
        if (loadedItems.length === 0) {
            return 0;
        }

        const fileIds = loadedItems.map((item) => item.id);
        const previews = loadedItems.map((item) => ({
            fileId: item.id,
            thumbnail: item.thumbnail || item.preview || item.src,
        }));
        const currentTabId = options.tab.value?.id;
        let restoreCallback: (() => Promise<void> | void) | undefined;

        if (options.isLocal.value) {
            const snapshots = new Map<number, LocalReactionSnapshot>();

            for (const item of loadedItems) {
                snapshots.set(item.id, applyOptimisticLocalReactionState(item, type));
            }

            const itemsToTemporarilyRemove = getItemsToRemoveAfterLocalMutation(loadedItems);

            if (itemsToTemporarilyRemove.length > 0) {
                await removeItemsFromView(itemsToTemporarilyRemove);
            } else {
                triggerRef(options.items);
                await nextTick();
            }

            restoreCallback = async () => {
                for (const item of loadedItems) {
                    const snapshot = snapshots.get(item.id);

                    if (snapshot) {
                        restoreOptimisticLocalReactionState(item, snapshot);
                    }
                }

                triggerRef(options.items);

                if (itemsToTemporarilyRemove.length === 0) {
                    return;
                }

                await options.masonry.value?.restore(itemsToTemporarilyRemove);
            };
        } else {
            await removeItemsFromView(loadedItems);

            restoreCallback = currentTabId !== undefined
                ? async () => {
                    await options.masonry.value?.restore(loadedItems);
                }
                : undefined;
        }

        queueBatchReaction(fileIds, type, previews, restoreCallback, options.items, {
            updateLocalState: false,
        });

        options.onReaction(fileIds[0], type);

        return fileIds.length;
    }

    async function batchBlacklistLoadedItems(): Promise<number> {
        const loadedItems = options.getLoadedItems();
        if (loadedItems.length === 0) {
            return 0;
        }

        const fileIds = loadedItems.map((item) => item.id);
        const results: NonNullable<BatchBlacklistResponse['results']> = [];

        for (let index = 0; index < fileIds.length; index += LOADED_ITEMS_BATCH_SIZE) {
            const chunk = fileIds.slice(index, index + LOADED_ITEMS_BATCH_SIZE);
            const { data } = await window.axios.post<BatchBlacklistResponse>('/api/files/blacklist/batch', {
                file_ids: chunk,
            });

            if (Array.isArray(data.results)) {
                results.push(...data.results);
            }
        }

        const resultMap = new Map(results.map((result) => [result.id, result]));
        const mutatedItems = loadedItems.filter((item) => resultMap.has(item.id));

        for (const item of mutatedItems) {
            const result = resultMap.get(item.id);
            if (!result) {
                continue;
            }

            item.blacklisted_at = result.blacklisted_at;
            item.blacklist_rule = null;
            item.reaction = null;
            item.auto_disliked = false;
            item.auto_dislike_rule = null;
        }

        if (mutatedItems.length === 0) {
            return 0;
        }

        if (options.isLocal.value) {
            await syncLocalMutationView(mutatedItems);
        } else {
            await removeItemsFromView(mutatedItems);
        }

        return mutatedItems.length;
    }

    async function performLoadedItemsBulkAction(action: LoadedItemsBulkAction): Promise<number> {
        if (action === 'blacklist') {
            return batchBlacklistLoadedItems();
        }

        return applyBatchReaction(action);
    }

    return {
        performLoadedItemsBulkAction,
    };
}
