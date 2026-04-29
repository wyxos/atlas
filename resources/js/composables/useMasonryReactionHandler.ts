import { triggerRef, type Ref } from 'vue';
import { useToast } from '@/components/ui/toast/use-toast';
import type { DownloadedReactionChoice } from './useDownloadedReactionPrompt';
import type { FeedItem, TabData } from './useTabs';
import { queueReaction } from '@/utils/reactionQueue';
import { createReactionCallback } from '@/utils/reactions';
import type { ReactionType } from '@/types/reaction';
import type { BrowseFeedHandle } from '@/types/browse';
import {
    applyOptimisticLocalReactionState,
    isPositiveReactionType,
    normalizeReactionType,
    restoreOptimisticLocalReactionState,
} from '@/utils/localReactionState';

type UseMasonryReactionHandlerOptions = {
    items: Ref<FeedItem[]>;
    masonry: Ref<BrowseFeedHandle | null>;
    tab: Ref<TabData | undefined>;
    isLocal: Readonly<Ref<boolean>>;
    matchesActiveLocalFilters?: (item: FeedItem) => boolean;
    isPositiveOnlyLocalView?: () => boolean;
    onReaction: (fileId: number, type: ReactionType) => void;
    promptDownloadedReaction?: () => Promise<DownloadedReactionChoice>;
    onWillRemoveItemFromView?: (item: FeedItem) => void;
};

function hasDownloadSource(item: FeedItem): boolean {
    return typeof item.url === 'string' && item.url.trim() !== '';
}

/**
 * Composable for handling masonry item reactions with restore functionality.
 */
export function useMasonryReactionHandler(
    options: UseMasonryReactionHandlerOptions,
) {
    const toast = useToast();
    const reactionCallback = createReactionCallback();

    function shouldPromptDownloadedReaction(item: FeedItem, type: ReactionType): boolean {
        return options.isLocal.value
            && options.promptDownloadedReaction !== undefined
            && type !== 'dislike'
            && item.downloaded === true
            && hasDownloadSource(item)
            && typeof item.reaction?.type === 'string'
            && item.reaction.type !== type;
    }

    async function resolveForceDownload(item: FeedItem, type: ReactionType): Promise<boolean | null> {
        if (!shouldPromptDownloadedReaction(item, type)) {
            return false;
        }

        const choice = await options.promptDownloadedReaction?.();
        if (choice === 'cancel') {
            return null;
        }

        return choice === 'redownload';
    }

    function shouldUseImmediatePositiveLocalReactionUpdate(item: FeedItem, type: ReactionType): boolean {
        if (!options.isLocal.value || options.isPositiveOnlyLocalView?.() !== true) {
            return false;
        }

        const currentReactionType = normalizeReactionType(item.reaction?.type);

        return isPositiveReactionType(currentReactionType)
            && isPositiveReactionType(type)
            && currentReactionType !== type;
    }

    async function applyLocalOptimisticReactionState(item: FeedItem, type: ReactionType): Promise<{
        restore: () => Promise<void>;
    }> {
        const snapshot = applyOptimisticLocalReactionState(item, type);
        const shouldRemoveFromView = options.matchesActiveLocalFilters
            ? !options.matchesActiveLocalFilters(item)
            : false;

        if (shouldRemoveFromView) {
            options.onWillRemoveItemFromView?.(item);
            await options.masonry.value?.remove(item);
        } else {
            triggerRef(options.items);
        }

        return {
            restore: async () => {
                restoreOptimisticLocalReactionState(item, snapshot);
                triggerRef(options.items);

                if (!shouldRemoveFromView) {
                    return;
                }

                await options.masonry.value?.restore(item);
            },
        };
    }

    async function restoreFailedImmediateReaction(restore: () => Promise<void>): Promise<void> {
        try {
            await restore();
        } catch (restoreError) {
            console.error('Failed to restore immediate local reaction state:', restoreError);
        }
    }

    async function handleImmediatePositiveLocalReaction(item: FeedItem, type: ReactionType): Promise<void> {
        const fileId = item.id;
        const localState = await applyLocalOptimisticReactionState(item, type);

        try {
            const result = await reactionCallback(fileId, type);
            options.onReaction(fileId, type);

            if (result.should_prompt_redownload !== true || options.promptDownloadedReaction === undefined) {
                return;
            }

            const choice = await options.promptDownloadedReaction();

            if (choice !== 'redownload') {
                return;
            }

            try {
                await reactionCallback(fileId, type, { forceDownload: true });
            } catch (error) {
                console.error('Failed to queue fresh download after immediate reaction save:', error);
                toast.error('Failed to queue fresh download', {
                    id: `redownload-${fileId}-error`,
                });
            }
        } catch (error) {
            console.error('Failed to save immediate local reaction:', error);
            await restoreFailedImmediateReaction(localState.restore);
            toast.error('Failed to save reaction', {
                id: `reaction-${fileId}-error`,
            });
        }
    }

    /**
     * Handle reaction - conditionally removes item from masonry based on feed mode and queues reaction.
     * In local mode: items are removed only when the optimistic state no longer matches the active filters.
     * In online mode: items are removed immediately.
     */
    async function handleMasonryReaction(
        item: FeedItem,
        type: ReactionType,
        _index?: number
    ): Promise<void> {
        const fileId = item.id;
        const tabId = options.tab.value?.id;

        if (shouldUseImmediatePositiveLocalReactionUpdate(item, type)) {
            await handleImmediatePositiveLocalReaction(item, type);
            return;
        }

        const forceDownload = await resolveForceDownload(item, type);

        if (forceDownload === null) {
            return;
        }

        let restoreCallback: (() => Promise<void> | void) | undefined;

        if (options.isLocal.value) {
            restoreCallback = (await applyLocalOptimisticReactionState(item, type)).restore;
        } else {
            // Only remove from masonry in online mode (not in local mode)
            // Pass item directly - Vibe tracks items by object reference, so we must use the exact reference
            options.onWillRemoveItemFromView?.(item);
            const removalResult = await options.masonry.value?.remove(item);
            if (removalResult && removalResult.ids.length === 0) {
                return;
            }

            // Create restore callback for undo functionality (only in online mode where items are removed)
            restoreCallback = tabId !== undefined
                ? async () => {
                    await options.masonry.value?.restore(item);
                }
                : undefined;
        }

        // Queue reaction with countdown toast (pass thumbnail and restore callback for undo/error recovery)
        const thumbnail = item.preview;
        queueReaction(fileId, type, thumbnail, restoreCallback, options.items, {
            forceDownload,
            updateLocalState: false,
        });

        // Emit to parent (reaction is queued, not executed yet)
        options.onReaction(fileId, type);
    }

    return {
        handleMasonryReaction,
    };
}
