import { ref, computed, onUnmounted } from 'vue';

export interface QueuedReaction {
    id: string;
    fileId: number;
    type: 'love' | 'like' | 'dislike' | 'funny';
    previewUrl?: string;
    countdown: number;
    timeoutId: ReturnType<typeof setTimeout> | null;
    intervalId: ReturnType<typeof setInterval> | null;
    startTime: number; // Track when countdown started
    pausedAt: number | null; // Track when paused (null if not paused)
    pausedRemaining: number | null; // Remaining time when paused
    executeCallback: (fileId: number, type: 'love' | 'like' | 'dislike' | 'funny') => Promise<void>; // Store callback for resume
    restoreItem?: (tabId: number, isTabActive: (tabId: number) => boolean) => void | Promise<void>; // Callback to restore item to masonry with tab check
    tabId?: number; // Tab ID where the item was removed
    itemIndex?: number; // Original index of the item in the masonry
    item?: any; // Store the item data for restoration
}

const QUEUE_DELAY_MS = 5000; // 5 seconds

const queuedReactions = ref<QueuedReaction[]>([]);
const isPaused = ref(false); // Global pause state for all reactions

export function useReactionQueue() {
    function queueReaction(
        fileId: number,
        type: 'love' | 'like' | 'dislike' | 'funny',
        executeCallback: (fileId: number, type: 'love' | 'like' | 'dislike' | 'funny') => Promise<void>,
        previewUrl?: string,
        restoreItem?: (tabId: number, isTabActive: (tabId: number) => boolean) => void,
        tabId?: number,
        itemIndex?: number,
        item?: any
    ): void {
        // Check if this reaction is already queued for this file
        const existingIndex = queuedReactions.value.findIndex(
            (q) => q.fileId === fileId
        );

        // If already queued, preserve restore data from existing reaction if new call doesn't have it
        let preservedRestoreItem = restoreItem;
        let preservedTabId = tabId;
        let preservedItemIndex = itemIndex;
        let preservedItem = item;

        if (existingIndex !== -1) {
            const existing = queuedReactions.value[existingIndex];
            if (existing.timeoutId) {
                clearTimeout(existing.timeoutId);
            }
            // Preserve restore data from existing reaction if new call doesn't provide it
            if (!restoreItem && existing.restoreItem) {
                preservedRestoreItem = existing.restoreItem;
            }
            if (tabId === undefined && existing.tabId !== undefined) {
                preservedTabId = existing.tabId;
            }
            if (itemIndex === undefined && existing.itemIndex !== undefined) {
                preservedItemIndex = existing.itemIndex;
            }
            if (!item && existing.item) {
                preservedItem = existing.item;
            }
            queuedReactions.value.splice(existingIndex, 1);
        }

        // Create new queued reaction
        const queueId = `${fileId}-${Date.now()}`;
        const startTime = Date.now();
        const queuedReaction: QueuedReaction = {
            id: queueId,
            fileId,
            type,
            previewUrl,
            countdown: QUEUE_DELAY_MS / 1000, // Start at 5 seconds
            timeoutId: null,
            intervalId: null,
            startTime,
            pausedAt: null,
            pausedRemaining: null,
            executeCallback,
            restoreItem: preservedRestoreItem,
            tabId: preservedTabId,
            itemIndex: preservedItemIndex,
            item: preservedItem,
        };

        // Add to queue
        queuedReactions.value.push(queuedReaction);

        // Start countdown timer
        const countdownInterval = setInterval(() => {
            const index = queuedReactions.value.findIndex((q) => q.id === queueId);
            if (index === -1) {
                clearInterval(countdownInterval);
                return;
            }

            const queued = queuedReactions.value[index];

            // If paused, don't update countdown
            if (queued.pausedAt !== null) {
                return;
            }

            // Calculate elapsed time (accounting for any previous pauses)
            const elapsed = Date.now() - queued.startTime;
            const remaining = Math.max(0, QUEUE_DELAY_MS - elapsed);

            queuedReactions.value[index].countdown = remaining / 1000;

            if (remaining <= 0) {
                clearInterval(countdownInterval);
                queuedReactions.value[index].intervalId = null;
                queuedReactions.value[index].countdown = 0;
            }
        }, 50); // Update every 50ms for smoother countdown
        queuedReaction.intervalId = countdownInterval;

        // Set timeout to execute after delay
        const timeoutId = setTimeout(async () => {
            try {
                await executeCallback(fileId, type);
            } catch (error) {
                console.error('Failed to execute queued reaction:', error);
            } finally {
                // Remove from queue
                const index = queuedReactions.value.findIndex((q) => q.id === queueId);
                if (index !== -1) {
                    const queued = queuedReactions.value[index];
                    if (queued.intervalId) {
                        clearInterval(queued.intervalId);
                    }
                    queuedReactions.value.splice(index, 1);
                }
            }
        }, QUEUE_DELAY_MS);
        queuedReaction.timeoutId = timeoutId;
    }

    async function cancelReaction(fileId: number, isTabActive?: (tabId: number) => boolean): Promise<void> {
        const index = queuedReactions.value.findIndex((q) => q.fileId === fileId);
        if (index !== -1) {
            const queued = queuedReactions.value[index];
            if (queued.timeoutId) {
                clearTimeout(queued.timeoutId);
            }
            if (queued.intervalId) {
                clearInterval(queued.intervalId);
            }

            // Restore item to masonry if restore callback exists and tab is active
            if (queued.restoreItem && queued.tabId !== undefined) {
                const tabIsActive = isTabActive ? isTabActive(queued.tabId) : true;
                if (tabIsActive) {
                    await queued.restoreItem(queued.tabId, isTabActive || (() => true));
                }
            }

            queuedReactions.value.splice(index, 1);
        }
    }

    function cancelAll(): void {
        queuedReactions.value.forEach((queued) => {
            if (queued.timeoutId) {
                clearTimeout(queued.timeoutId);
            }
            if (queued.intervalId) {
                clearInterval(queued.intervalId);
            }
        });
        queuedReactions.value = [];
    }

    function pauseAll(): void {
        if (isPaused.value) {
            return; // Already paused
        }

        isPaused.value = true;
        const now = Date.now();

        queuedReactions.value.forEach((queued) => {
            // If already paused, skip
            if (queued.pausedAt !== null) {
                return;
            }

            // Calculate remaining time
            const elapsed = now - queued.startTime;
            const remaining = Math.max(0, QUEUE_DELAY_MS - elapsed);
            queued.pausedRemaining = remaining;
            queued.pausedAt = now;

            // Clear timers
            if (queued.timeoutId) {
                clearTimeout(queued.timeoutId);
                queued.timeoutId = null;
            }
            if (queued.intervalId) {
                clearInterval(queued.intervalId);
                queued.intervalId = null;
            }
        });
    }

    function resumeAll(): void {
        if (!isPaused.value) {
            return; // Not paused
        }

        isPaused.value = false;
        const now = Date.now();

        queuedReactions.value.forEach((queued) => {
            // If not paused, skip
            if (queued.pausedAt === null || queued.pausedRemaining === null) {
                return;
            }

            // Calculate how long we were paused
            const pauseDuration = now - queued.pausedAt;

            // Adjust startTime to account for the pause
            queued.startTime = now - (QUEUE_DELAY_MS - queued.pausedRemaining);

            // Clear paused state
            queued.pausedAt = null;
            const remaining = queued.pausedRemaining;
            queued.pausedRemaining = null;

            // Restart countdown interval
            const countdownInterval = setInterval(() => {
                const index = queuedReactions.value.findIndex((q) => q.id === queued.id);
                if (index === -1) {
                    clearInterval(countdownInterval);
                    return;
                }

                const currentQueued = queuedReactions.value[index];

                // If paused again, don't update
                if (currentQueued.pausedAt !== null) {
                    return;
                }

                const elapsed = Date.now() - currentQueued.startTime;
                const currentRemaining = Math.max(0, QUEUE_DELAY_MS - elapsed);

                queuedReactions.value[index].countdown = currentRemaining / 1000;

                if (currentRemaining <= 0) {
                    clearInterval(countdownInterval);
                    queuedReactions.value[index].intervalId = null;
                    queuedReactions.value[index].countdown = 0;
                }
            }, 50);
            queued.intervalId = countdownInterval;

            // Restart timeout
            const timeoutId = setTimeout(async () => {
                try {
                    await queued.executeCallback(queued.fileId, queued.type);
                } catch (error) {
                    console.error('Failed to execute queued reaction:', error);
                } finally {
                    const index = queuedReactions.value.findIndex((q) => q.id === queued.id);
                    if (index !== -1) {
                        const finalQueued = queuedReactions.value[index];
                        if (finalQueued.intervalId) {
                            clearInterval(finalQueued.intervalId);
                        }
                        queuedReactions.value.splice(index, 1);
                    }
                }
            }, remaining);
            queued.timeoutId = timeoutId;
        });
    }

    // Cleanup on unmount
    onUnmounted(() => {
        cancelAll();
    });

    return {
        queuedReactions: computed(() => queuedReactions.value),
        queueReaction,
        cancelReaction,
        cancelAll,
        pauseAll,
        resumeAll,
        isPaused: computed(() => isPaused.value),
    };
}

