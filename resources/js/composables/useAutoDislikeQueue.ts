import { ref, computed, onUnmounted } from 'vue';

const COUNTDOWN_DURATION = 5000; // 5 seconds
const TICK_INTERVAL = 100; // Update every 100ms for smooth countdown

interface QueuedItem {
    fileId: number;
    addedAt: number;
    remaining: number;
    isActive: boolean; // Only active items have countdown ticking (preview loaded)
}

type OnExpireCallback = (expiredIds: number[]) => void;

/**
 * Composable for managing auto-dislike queue.
 * Collects items flagged for auto-dislike and batches them when countdowns expire.
 * Items are queued immediately but countdown only starts when activated (preview loaded).
 */
export function useAutoDislikeQueue(onExpire?: OnExpireCallback) {
    const queue = ref<Map<number, QueuedItem>>(new Map());
    const isFrozen = ref(false);
    let tickInterval: ReturnType<typeof setInterval> | null = null;

    const queuedItems = computed(() => Array.from(queue.value.values()));
    const queueSize = computed(() => queue.value.size);
    const hasQueuedItems = computed(() => queue.value.size > 0);
    const activeQueueSize = computed(() => Array.from(queue.value.values()).filter((i) => i.isActive).length);

    // Get the minimum remaining time across all queued items
    const minRemaining = computed(() => {
        if (queue.value.size === 0) return 0;
        let min = Infinity;
        queue.value.forEach((item) => {
            if (item.remaining < min) {
                min = item.remaining;
            }
        });
        return min === Infinity ? 0 : min;
    });

    // Get progress based on minimum remaining (for the global progress bar)
    const progress = computed(() => {
        if (queue.value.size === 0) return 0;
        return 1 - (minRemaining.value / COUNTDOWN_DURATION);
    });

    /**
     * Add an item to the auto-dislike queue (paused until activated).
     * @param startActive If true, countdown starts immediately (preview already loaded)
     */
    function addToQueue(fileId: number, startActive: boolean = false): void {
        if (queue.value.has(fileId)) {
            return; // Already queued
        }

        queue.value.set(fileId, {
            fileId,
            addedAt: Date.now(),
            remaining: COUNTDOWN_DURATION,
            isActive: startActive,
        });

        // Start tick interval if not already running
        if (!tickInterval) {
            tickInterval = setInterval(() => {
                tick();
            }, TICK_INTERVAL);
        }
    }

    /**
     * Activate an item's countdown (called when preview loads).
     */
    function activateItem(fileId: number): void {
        const item = queue.value.get(fileId);
        if (item && !item.isActive) {
            item.isActive = true;
        }
    }

    /**
     * Check if an item is active (countdown running).
     */
    function isActive(fileId: number): boolean {
        const item = queue.value.get(fileId);
        return item ? item.isActive : false;
    }

    /**
     * Remove an item from the queue (e.g., when user reacts).
     */
    function removeFromQueue(fileId: number): void {
        queue.value.delete(fileId);

        // Stop tick interval if no more items
        if (queue.value.size === 0 && tickInterval) {
            clearInterval(tickInterval);
            tickInterval = null;
        }
    }

    /**
     * Check if an item is in the queue.
     */
    function isQueued(fileId: number): boolean {
        return queue.value.has(fileId);
    }

    /**
     * Freeze all countdowns (called on hover).
     */
    function freeze(): void {
        isFrozen.value = true;
    }

    /**
     * Unfreeze all countdowns (called when hover ends).
     */
    function unfreeze(): void {
        isFrozen.value = false;
    }

    /**
     * Get remaining time for a specific item.
     */
    function getRemaining(fileId: number): number {
        const item = queue.value.get(fileId);
        return item ? item.remaining : 0;
    }

    /**
     * Get progress (0-1) for a specific item.
     */
    function getProgress(fileId: number): number {
        const remaining = getRemaining(fileId);
        return 1 - (remaining / COUNTDOWN_DURATION);
    }

    /**
     * Tick function that decrements countdowns for active items only.
     */
    function tick(): void {
        if (isFrozen.value) {
            return; // Don't tick when frozen
        }

        const expired: number[] = [];

        queue.value.forEach((item, fileId) => {
            // Only tick active items (preview loaded)
            if (!item.isActive) {
                return;
            }

            item.remaining -= TICK_INTERVAL;
            if (item.remaining <= 0) {
                expired.push(fileId);
            }
        });

        // Remove expired items and trigger callback
        if (expired.length > 0) {
            console.log('[AutoDislikeQueue] Items expired:', expired);
            expired.forEach((fileId) => {
                queue.value.delete(fileId);
            });

            // Call the onExpire callback with all expired file IDs
            if (onExpire) {
                console.log('[AutoDislikeQueue] Calling onExpire callback...');
                onExpire(expired);
            } else {
                console.warn('[AutoDislikeQueue] No onExpire callback registered!');
            }
        }

        // Stop tick interval if no more items
        if (queue.value.size === 0 && tickInterval) {
            clearInterval(tickInterval);
            tickInterval = null;
        }
    }

    /**
     * Clear all items from the queue.
     */
    function clearQueue(): void {
        queue.value.clear();
        if (tickInterval) {
            clearInterval(tickInterval);
            tickInterval = null;
        }
        isFrozen.value = false;
    }

    /**
     * Get all queued file IDs.
     */
    function getQueuedIds(): number[] {
        return Array.from(queue.value.keys());
    }

    // Cleanup on unmount
    onUnmounted(() => {
        clearQueue();
    });

    return {
        queue: computed(() => queue.value),
        queuedItems,
        queueSize,
        hasQueuedItems,
        activeQueueSize,
        minRemaining,
        progress,
        isFrozen: computed(() => isFrozen.value),
        addToQueue,
        activateItem,
        removeFromQueue,
        isQueued,
        isActive,
        freeze,
        unfreeze,
        getRemaining,
        getProgress,
        getQueuedIds,
        clearQueue,
    };
}

