import { ref, type Ref } from 'vue';
import { useQueue } from './useQueue';
import { batchPerformAutoDislike } from '@/actions/App/Http/Controllers/FilesController';
import type { MasonryItem } from './useTabs';
import type { Masonry } from '@wyxos/vibe';
import { useBrowseForm } from './useBrowseForm';
import updateReactionState from '@/utils/reactionStateUpdater';

const COUNTDOWN_DURATION_MS = 5 * 1000; // 5 seconds
const DEBOUNCE_DELAY_MS = 500; // 500ms debounce for batch operations

interface PendingDislike {
    fileId: number;
    item: MasonryItem;
}

// Global state for pending dislikes (debounced batch)
const pendingDislikes = ref<Map<number, PendingDislike>>(new Map());
let debounceTimeout: ReturnType<typeof setTimeout> | null = null;
// Track unfreeze timeout for FileViewer (separate from hover unfreeze in useQueue)
let fileViewerUnfreezeTimeout: ReturnType<typeof setTimeout> | null = null;

/**
 * Composable for managing auto-dislike countdown timers with debounced batch execution.
 * When countdown expires, items are removed from masonry and disliked in batches.
 * In local mode, items are NOT removed (visual treatment only).
 */
export function useAutoDislikeQueue(
    items: Ref<MasonryItem[]>,
    masonry: Ref<InstanceType<typeof Masonry> | null>,
    itemsMap?: Ref<Map<number, MasonryItem>>
) {
    const { isLocal } = useBrowseForm();
    const { add: addToQueue, remove: removeFromQueue, getRemainingTime, getProgress, has: hasInQueue, freezeAll, unfreezeAll, isFrozen, stop, resume, getAll } = useQueue();
    
    // Track auto-dislike items that are frozen (for FileViewer)
    const frozenAutoDislikeItems = ref<Set<number>>(new Set());
    // Track if FileViewer is currently open (so we can freeze new items immediately)
    const isFileViewerOpen = ref(false);

    /**
     * Execute batch dislike operation (debounced).
     * Removes items from masonry and calls backend to dislike them.
     * In local mode, items are NOT removed (visual treatment only).
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

        // Only remove items from masonry in online mode (not in local mode)
        if (!isLocal.value && masonry.value && itemsToRemove.length > 0) {
            await masonry.value.removeMany?.(itemsToRemove);
        }

        // Batch dislike API call
        try {
            await window.axios.post(batchPerformAutoDislike.url(), {
                file_ids: fileIds,
            });
            
            // Update reaction state in local mode (if items provided)
            if (isLocal.value && items) {
                fileIds.forEach((fileId) => {
                    updateReactionState(items, fileId, 'dislike', itemsMap);
                });
            }
        } catch (error) {
            console.error('Failed to batch perform auto-dislike:', error);
            // Note: In online mode, items are already removed from masonry, but dislike failed
            // This is acceptable - the items are gone from view anyway
            // In local mode, items remain visible with visual treatment
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

        // If FileViewer is open, freeze this item immediately
        if (isFileViewerOpen.value) {
            stop(`auto-dislike-${fileId}`);
            frozenAutoDislikeItems.value.add(fileId);
        }
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
        
        // Remove from frozen items if it was frozen
        frozenAutoDislikeItems.value.delete(fileId);
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

    /**
     * Freeze only auto-dislike countdowns (used when FileViewer opens).
     * Other countdowns (e.g., reaction queue) continue normally.
     */
    function freezeAutoDislikeOnly(): void {
        isFileViewerOpen.value = true;
        
        // Find all auto-dislike items in the queue and stop them individually
        const allItems = getAll();
        allItems.forEach((item) => {
            if (item.id.startsWith('auto-dislike-')) {
                const fileId = parseInt(item.id.replace('auto-dislike-', ''), 10);
                if (!isNaN(fileId)) {
                    stop(item.id);
                    frozenAutoDislikeItems.value.add(fileId);
                }
            }
        });
    }

    /**
     * Unfreeze only auto-dislike countdowns (used when FileViewer closes).
     * Resumes after 2 second delay.
     */
    function unfreezeAutoDislikeOnly(): void {
        isFileViewerOpen.value = false;
        
        // Clear any pending unfreeze timeout
        if (fileViewerUnfreezeTimeout) {
            clearTimeout(fileViewerUnfreezeTimeout);
            fileViewerUnfreezeTimeout = null;
        }

        // Resume after 2 second delay
        fileViewerUnfreezeTimeout = setTimeout(() => {
            // Resume all frozen auto-dislike items
            frozenAutoDislikeItems.value.forEach((fileId) => {
                const queueId = `auto-dislike-${fileId}`;
                resume(queueId);
            });
            frozenAutoDislikeItems.value.clear();
            fileViewerUnfreezeTimeout = null;
        }, 2000);
    }

    return {
        startAutoDislikeCountdown,
        cancelAutoDislikeCountdown,
        getCountdownRemainingTime,
        getCountdownProgress,
        hasActiveCountdown,
        formatCountdown,
        freezeAll, // For hover freeze (freezes all countdowns)
        unfreezeAll, // For hover unfreeze (unfreezes all countdowns)
        freezeAutoDislikeOnly, // For FileViewer (freezes only auto-dislike countdowns)
        unfreezeAutoDislikeOnly, // For FileViewer (unfreezes only auto-dislike countdowns)
        isFrozen, // Expose frozen state for UI indicators
    };
}
