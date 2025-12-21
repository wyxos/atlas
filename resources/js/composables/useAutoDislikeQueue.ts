import { ref, type Ref } from 'vue';
import { useQueue } from './useQueue';
import { batchPerformAutoDislike } from '@/actions/App/Http/Controllers/FilesController';
import type { MasonryItem } from './useBrowseTabs';
import type { Masonry } from '@wyxos/vibe';

const COUNTDOWN_DURATION_MS = 5 * 1000; // 5 seconds
const DEBOUNCE_DELAY_MS = 500; // 500ms debounce for batch operations

interface PendingDislike {
    fileId: number;
    item: MasonryItem;
}

// Global state for pending dislikes (debounced batch)
const pendingDislikes = ref<Map<number, PendingDislike>>(new Map());
let debounceTimeout: ReturnType<typeof setTimeout> | null = null;

/**
 * Composable for managing auto-dislike countdown timers with debounced batch execution.
 * When countdown expires, items are removed from masonry and disliked in batches.
 */
export function useAutoDislikeQueue(
    items: Ref<MasonryItem[]>,
    masonry: Ref<InstanceType<typeof Masonry> | null>
) {
    const { add: addToQueue, remove: removeFromQueue, getRemainingTime, getProgress, has: hasInQueue, freezeAll, unfreezeAll, isFrozen } = useQueue();

    /**
     * Execute batch dislike operation (debounced).
     * Removes items from masonry and calls backend to dislike them.
     */
    async function executeBatchDislike(): Promise<void> {
        if (pendingDislikes.value.size === 0) {
            return;
        }

        const dislikes = new Map(pendingDislikes.value);
        pendingDislikes.value.clear();

        if (debounceTimeout) {
            clearTimeout(debounceTimeout);
            debounceTimeout = null;
        }

        const fileIds = Array.from(dislikes.keys());
        const itemsToRemove = Array.from(dislikes.values()).map((pending) => pending.item);

        // Remove items from masonry first (batch removal)
        if (masonry.value && itemsToRemove.length > 0) {
            await masonry.value.removeMany(itemsToRemove);
        }

        // Batch dislike API call
        try {
            await window.axios.post(batchPerformAutoDislike.url(), {
                file_ids: fileIds,
            });
        } catch (error) {
            console.error('Failed to batch perform auto-dislike:', error);
            // Note: Items are already removed from masonry, but dislike failed
            // This is acceptable - the items are gone from view anyway
        }
    }

    /**
     * Schedule debounced batch dislike execution.
     */
    function scheduleBatchDislike(): void {
        if (debounceTimeout) {
            // Reset debounce timer
            clearTimeout(debounceTimeout);
        }

        debounceTimeout = setTimeout(() => {
            void executeBatchDislike();
        }, DEBOUNCE_DELAY_MS);
    }

    /**
     * Start countdown for an item that needs to be auto-disliked.
     * When countdown expires, the item will be removed and disliked (batched with others).
     */
    function startAutoDislikeCountdown(fileId: number, item: MasonryItem): void {
        // Don't start if already queued
        if (hasInQueue(`auto-dislike-${fileId}`)) {
            return;
        }

        // Add to queue with countdown
        addToQueue({
            id: `auto-dislike-${fileId}`,
            duration: COUNTDOWN_DURATION_MS,
            metadata: { fileId, item },
            onComplete: () => {
                // When countdown expires, add to pending dislikes (will be batched)
                pendingDislikes.value.set(fileId, {
                    fileId,
                    item,
                });

                // Remove from queue
                removeFromQueue(`auto-dislike-${fileId}`);

                // Schedule debounced batch execution
                scheduleBatchDislike();
            },
        });
    }

    /**
     * Cancel auto-dislike countdown for an item (e.g., if user reacts manually).
     */
    function cancelAutoDislikeCountdown(fileId: number): void {
        const queueId = `auto-dislike-${fileId}`;
        if (hasInQueue(queueId)) {
            removeFromQueue(queueId);
        }

        // Also remove from pending dislikes if it's there
        pendingDislikes.value.delete(fileId);
    }

    /**
     * Get remaining time for an item's countdown.
     */
    function getCountdownRemainingTime(fileId: number): number {
        return getRemainingTime(`auto-dislike-${fileId}`);
    }

    /**
     * Get progress (0-100) for an item's countdown.
     */
    function getCountdownProgress(fileId: number): number {
        return getProgress(`auto-dislike-${fileId}`);
    }

    /**
     * Check if an item has an active countdown.
     */
    function hasActiveCountdown(fileId: number): boolean {
        return hasInQueue(`auto-dislike-${fileId}`);
    }

    /**
     * Format remaining time as SS:MM string (seconds:milliseconds).
     */
    function formatCountdown(remainingMs: number): string {
        const totalSeconds = Math.floor(remainingMs / 1000);
        const milliseconds = Math.floor((remainingMs % 1000) / 10); // Convert to centiseconds (0-99)
        return `${totalSeconds.toString().padStart(2, '0')}:${milliseconds.toString().padStart(2, '0')}`;
    }

    return {
        startAutoDislikeCountdown,
        cancelAutoDislikeCountdown,
        getCountdownRemainingTime,
        getCountdownProgress,
        hasActiveCountdown,
        formatCountdown,
        freezeAll,
        unfreezeAll,
        isFrozen, // Expose frozen state for UI indicators
    };
}

