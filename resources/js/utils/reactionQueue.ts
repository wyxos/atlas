import { useToast } from 'vue-toastification';
import { useQueue } from '@/composables/useQueue';
import { createReactionCallback } from './reactions';
import updateReactionState from '@/utils/reactionStateUpdater';
import type { ReactionType } from '@/types/reaction';
import type { Ref } from 'vue';
import type { MasonryItem } from '@/composables/useTabs';
import ReactionQueueToast from '@/components/toasts/ReactionQueueToast.vue';
import BatchReactionQueueToast from '@/components/toasts/BatchReactionQueueToast.vue';

const toast = useToast();

const queue = useQueue();
const REACTION_COUNTDOWN_DURATION = 5000; // 5 seconds
type ReactionQueueMetadata = {
    restoreCallback?: () => Promise<void> | void;
    items?: Ref<MasonryItem[]>;
};

/**
 * Queue a reaction with countdown toast.
 * Shows toast immediately with countdown, executes reaction on expiration.
 */
export function queueReaction(
    fileId: number,
    reactionType: ReactionType,
    thumbnail?: string,
    restoreCallback?: () => Promise<void> | void,
    items?: Ref<MasonryItem[]>,
    itemsMap?: Ref<Map<number, MasonryItem>>
): void {
    const queueId = `${reactionType}-${fileId}`;

    // Remove existing reaction for this file if any
    const existingItems = queue.getAll();
    const existingItem = existingItems.find((item) => item.id.startsWith(`${reactionType}-${fileId}`) || item.id.startsWith(`reaction-${fileId}`));
    if (existingItem) {
        queue.remove(existingItem.id);
        toast.dismiss(existingItem.id);
    }

    // Create reaction callback
    const reactionCallback = createReactionCallback();

    // Add to queue
    queue.add({
        id: queueId,
        duration: REACTION_COUNTDOWN_DURATION,
        onComplete: async () => {
            try {
                // Execute the reaction
                await reactionCallback(fileId, reactionType);
                
                // Update reaction state in local mode (if items provided)
                if (items) {
                    updateReactionState(items, fileId, reactionType, itemsMap);
                }
                
                // Dismiss toast on success
                toast.dismiss(queueId);
            } catch (error) {
                console.error('Failed to execute queued reaction:', error);
                // Show error toast
                toast.error('Failed to save reaction', {
                    id: `${queueId}-error`,
                });
                // Dismiss the countdown toast
                toast.dismiss(queueId);
            }
        },
        metadata: {
            fileId,
            reactionType,
            thumbnail,
            restoreCallback,
            items,
        },
    });

    // Show toast immediately with countdown
    toast(
        {
            component: ReactionQueueToast,
            props: {
                queueId,
                fileId,
                reactionType,
                thumbnail,
            },
        },
        {
            id: queueId,
            timeout: false, // Don't auto-dismiss - we handle it in onComplete
            closeButton: false,
            closeOnClick: false,
            toastClassName: 'reaction-queue-toast-wrapper',
            bodyClassName: 'reaction-queue-toast-body',
        }
    );
}

/**
 * Queue a batch of reactions with countdown toast.
 * Shows toast immediately with countdown, executes all reactions on expiration.
 */
export function queueBatchReaction(
    fileIds: number[],
    reactionType: ReactionType,
    previews: Array<{ fileId: number; thumbnail?: string }>,
    restoreCallback?: () => Promise<void> | void,
    items?: Ref<MasonryItem[]>,
    itemsMap?: Ref<Map<number, MasonryItem>>
): void {
    if (fileIds.length === 0) {
        return;
    }

    const queueId = `batch-${reactionType}-${fileIds.join('-')}-${Date.now()}`;

    // Create reaction callback
    const reactionCallback = createReactionCallback();

    // Add to queue
    queue.add({
        id: queueId,
        duration: REACTION_COUNTDOWN_DURATION,
        onComplete: async () => {
            try {
                // Execute all reactions in the batch
                await Promise.all(fileIds.map((fileId) => reactionCallback(fileId, reactionType)));
                
                // Update reaction state in local mode for all files (if items provided)
                if (items) {
                    fileIds.forEach((fileId) => {
                        updateReactionState(items, fileId, reactionType, itemsMap);
                    });
                }
                
                // Dismiss toast on success
                toast.dismiss(queueId);
            } catch (error) {
                console.error('Failed to execute queued batch reaction:', error);
                // Show error toast
                toast.error('Failed to save batch reaction', {
                    id: `${queueId}-error`,
                });
                // Dismiss the countdown toast
                toast.dismiss(queueId);
            }
        },
        metadata: {
            fileIds,
            reactionType,
            previews,
            restoreCallback,
            items,
        },
    });

    // Show toast immediately with countdown
    toast(
        {
            component: BatchReactionQueueToast,
            props: {
                queueId,
                reactionType,
                previews,
                totalCount: fileIds.length,
            },
        },
        {
            id: queueId,
            timeout: false, // Don't auto-dismiss - we handle it in onComplete
            closeButton: false,
            closeOnClick: false,
            toastClassName: 'reaction-queue-toast-wrapper',
            bodyClassName: 'reaction-queue-toast-body',
        }
    );
}

/**
 * Cancel a queued reaction and restore to masonry if restore callback exists.
 */
export async function cancelQueuedReaction(fileId: number, reactionType: ReactionType): Promise<void> {
    const queueId = `${reactionType}-${fileId}`;
    const item = queue.getAll().find((item) => item.id === queueId);
    
    // Get restore callback from metadata before removing
    const restoreCallback = (item?.metadata as ReactionQueueMetadata | undefined)?.restoreCallback;
    
    // Remove from queue
    queue.remove(queueId);
    toast.dismiss(queueId);
    
    // Restore to masonry if callback exists
    if (restoreCallback) {
        try {
            await restoreCallback();
        } catch (error) {
            console.error('Failed to restore item to masonry:', error);
        }
    }
}

/**
 * Cancel a queued batch reaction and restore to masonry if restore callback exists.
 */
export async function cancelBatchQueuedReaction(queueId: string): Promise<void> {
    const item = queue.getAll().find((item) => item.id === queueId);
    
    // Get restore callback from metadata before removing
    const restoreCallback = (item?.metadata as ReactionQueueMetadata | undefined)?.restoreCallback;
    
    // Remove from queue
    queue.remove(queueId);
    toast.dismiss(queueId);
    
    // Restore to masonry if callback exists
    if (restoreCallback) {
        try {
            await restoreCallback();
        } catch (error) {
            console.error('Failed to restore batch items to masonry:', error);
        }
    }
}



