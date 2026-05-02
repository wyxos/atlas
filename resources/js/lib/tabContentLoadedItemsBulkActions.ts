import { nextTick, triggerRef, type Ref, type ShallowRef } from 'vue';
import type { FeedItem, TabData } from '@/composables/useTabs';
import { queueBatchBlacklist, queueBatchReaction, queueBlacklist } from '@/utils/reactionQueue';
import type { BrowseFeedHandle } from '@/types/browse';
import type { ReactionType } from '@/types/reaction';
import {
    applyConfirmedLocalBlacklistState,
    applyOptimisticLocalBlacklistState,
    applyOptimisticLocalReactionState,
    restoreOptimisticLocalReactionState,
    type LocalReactionSnapshot,
} from '@/utils/localReactionState';
import type { BatchBlacklistResult } from '@/utils/reactions';

export type LoadedItemsBulkAction =
    | 'love'
    | 'like'
    | 'blacklist';

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

export function createLoadedItemsBulkActions(options: CreateLoadedItemsBulkActionsOptions) {
    function getItemsToRemoveAfterLocalMutation(changedItems: FeedItem[]): FeedItem[] {
        if (!options.matchesActiveLocalFilters) {
            return [];
        }

        return changedItems.filter((item) => !options.matchesActiveLocalFilters?.(item));
    }

    async function removeItemsFromView(itemsToRemove: FeedItem[]): Promise<void> {
        if (itemsToRemove.length === 0) {
            return;
        }

        options.clearHoverForRemovedItems(new Set(itemsToRemove.map((item) => item.id)));

        await options.masonry.value?.remove(itemsToRemove);
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

    async function blacklistItems(itemsToBlacklist: FeedItem[]): Promise<number> {
        if (itemsToBlacklist.length === 0) {
            return 0;
        }

        const fileIds = itemsToBlacklist.map((item) => item.id);
        const previews = itemsToBlacklist.map((item) => ({
            fileId: item.id,
            thumbnail: item.thumbnail || item.preview || item.src,
        }));
        const currentTabId = options.tab.value?.id;
        let restoreCallback: (() => Promise<void> | void) | undefined;

        if (options.isLocal.value) {
            const snapshots = new Map<number, LocalReactionSnapshot>();

            for (const item of itemsToBlacklist) {
                snapshots.set(item.id, applyOptimisticLocalBlacklistState(item));
            }

            const itemsToTemporarilyRemove = getItemsToRemoveAfterLocalMutation(itemsToBlacklist);

            if (itemsToTemporarilyRemove.length > 0) {
                await removeItemsFromView(itemsToTemporarilyRemove);
            } else {
                triggerRef(options.items);
                await nextTick();
            }

            restoreCallback = async () => {
                for (const item of itemsToBlacklist) {
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
            await removeItemsFromView(itemsToBlacklist);

            restoreCallback = currentTabId !== undefined
                ? async () => {
                    await options.masonry.value?.restore(itemsToBlacklist);
                }
                : undefined;
        }

        const onSuccess = (results: BatchBlacklistResult[]) => {
            const resultMap = new Map(results.map((result) => [result.id, result]));

            for (const item of itemsToBlacklist) {
                const result = resultMap.get(item.id);

                if (result) {
                    applyConfirmedLocalBlacklistState(item, result);
                }
            }

            triggerRef(options.items);
        };

        if (fileIds.length === 1) {
            queueBlacklist(fileIds[0], previews[0]?.thumbnail, restoreCallback, options.items, { onSuccess });
        } else {
            queueBatchBlacklist(fileIds, previews, restoreCallback, options.items, { onSuccess });
        }

        return fileIds.length;
    }

    async function performLoadedItemsBulkAction(action: LoadedItemsBulkAction): Promise<number> {
        if (action === 'blacklist') {
            return blacklistItems(options.getLoadedItems());
        }

        return applyBatchReaction(action);
    }

    return {
        blacklistItems,
        performLoadedItemsBulkAction,
    };
}
