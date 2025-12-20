import { useToast } from 'vue-toastification';
import { useQueue } from '@/composables/useQueue';
import { createReactionCallback } from './reactions';
import type { ReactionType } from '@/types/reaction';
import ReactionQueueToast from '@/components/toasts/ReactionQueueToast.vue';

const toast = useToast();

const queue = useQueue();
const REACTION_COUNTDOWN_DURATION = 5000; // 5 seconds

/**
 * Queue a reaction with countdown toast.
 * Shows toast immediately with countdown, executes reaction on expiration.
 */
export function queueReaction(
    fileId: number,
    reactionType: ReactionType,
    thumbnail?: string,
    restoreCallback?: () => Promise<void> | void
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
 * Cancel a queued reaction and restore to masonry if restore callback exists.
 */
export async function cancelQueuedReaction(fileId: number, reactionType: ReactionType): Promise<void> {
    const queueId = `${reactionType}-${fileId}`;
    const item = queue.getAll().find((item) => item.id === queueId);
    
    // Get restore callback from metadata before removing
    const restoreCallback = item?.metadata?.restoreCallback as (() => Promise<void> | void) | undefined;
    
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

