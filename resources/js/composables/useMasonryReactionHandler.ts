import { triggerRef, type Ref } from 'vue';
import type { DownloadedReactionChoice } from './useDownloadedReactionPrompt';
import type { FeedItem, TabData } from './useTabs';
import { queueReaction } from '@/utils/reactionQueue';
import type { ReactionType } from '@/types/reaction';
import { Masonry } from '@wyxos/vibe';
import {
    applyOptimisticLocalReactionState,
    restoreOptimisticLocalReactionState,
} from '@/utils/localReactionState';

type UseMasonryReactionHandlerOptions = {
    items: Ref<FeedItem[]>;
    masonry: Ref<InstanceType<typeof Masonry> | null>;
    tab: Ref<TabData | undefined>;
    isLocal: Readonly<Ref<boolean>>;
    matchesActiveLocalFilters?: (item: FeedItem) => boolean;
    onReaction: (fileId: number, type: ReactionType) => void;
    promptDownloadedReaction?: () => Promise<DownloadedReactionChoice>;
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
        const forceDownload = await resolveForceDownload(item, type);

        if (forceDownload === null) {
            return;
        }

        let restoreCallback: (() => Promise<void> | void) | undefined;

        if (options.isLocal.value) {
            const snapshot = applyOptimisticLocalReactionState(item, type);
            const shouldRemoveFromView = options.matchesActiveLocalFilters
                ? !options.matchesActiveLocalFilters(item)
                : false;

            if (shouldRemoveFromView) {
                if (options.masonry.value) {
                    await options.masonry.value.remove(item);
                } else {
                    options.items.value = options.items.value.filter((candidate) => candidate.id !== item.id);
                }
            } else {
                triggerRef(options.items);
            }

            restoreCallback = async () => {
                restoreOptimisticLocalReactionState(item, snapshot);
                triggerRef(options.items);

                if (!shouldRemoveFromView) {
                    return;
                }

                if (options.masonry.value) {
                    await options.masonry.value.restore(item);
                    return;
                }

                options.items.value = [...options.items.value, item];
            };
        } else {
            // Only remove from masonry in online mode (not in local mode)
            // Pass item directly - Vibe tracks items by object reference, so we must use the exact reference
            options.masonry.value?.remove(item);

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
