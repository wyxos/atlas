import { ref, computed, onUnmounted, getCurrentInstance } from 'vue';
import { useTimerManager } from './useTimerManager';

const COUNTDOWN_DURATION = 5000; // 5 seconds
const TICK_INTERVAL = 100; // Update every 100ms for smooth countdown

interface QueuedItem<T = number> {
    id: T;
    addedAt: number;
    remaining: number;
    isActive: boolean; // Only active items have countdown ticking (preview loaded)
    isFrozen: boolean; // Per-item freeze state (for hover)
}

type OnExpireCallback<T = number> = (expiredIds: T[]) => void;

/**
 * Generic composable for managing countdown queues.
 * Can be used for files (auto-dislike) or containers (blacklist).
 * Collects items flagged for countdown and batches them when countdowns expire.
 * Items are queued immediately but countdown only starts when activated (preview loaded).
 */
export function useCountdownQueue<T extends number = number>(onExpire?: OnExpireCallback<T>) {
    const queue = ref(new Map<T, QueuedItem<T>>());
    const isFrozen = ref(false); // Global freeze state (for timer manager coordination)
    const frozenItems = new Set<T>(); // Per-item freeze state (for hover) - not reactive, just for tracking
    let tickInterval: ReturnType<typeof setInterval> | null = null;

    // Register with centralized timer manager
    const timerManager = useTimerManager();
    const systemId = 'auto-dislike' as const;

    // Internal freeze/unfreeze functions that actually modify state
    function internalFreeze(): void {
        isFrozen.value = true;
    }

    function internalUnfreeze(): void {
        isFrozen.value = false;
    }

    // Register with timer manager
    timerManager.registerSystem(systemId, internalFreeze, internalUnfreeze);

    const queuedItems = computed(() => Array.from(queue.value.values()));
    const queueSize = computed(() => queue.value.size);
    const hasQueuedItems = computed(() => queue.value.size > 0);
    const activeQueueSize = computed(() => Array.from(queue.value.values()).filter((i) => i.isActive).length);

    // Get the minimum remaining time across all queued items
    const minRemaining = computed(() => {
        if (queue.value.size === 0) {
            return 0;
        }
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
        if (queue.value.size === 0) {
            return 0;
        }
        return 1 - (minRemaining.value / COUNTDOWN_DURATION);
    });

    /**
     * Add an item to the queue (paused until activated).
     * @param id The ID of the item (file ID or container ID)
     * @param startActive If true, countdown starts immediately (preview already loaded)
     */
    function addToQueue(id: T, startActive: boolean = false): void {
        if (queue.value.has(id)) {
            return; // Already queued
        }

        queue.value.set(id as any, {
            id: id as T,
            addedAt: Date.now(),
            remaining: COUNTDOWN_DURATION,
            isActive: startActive,
            isFrozen: false,
        } as any);

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
    function activateItem(id: T): void {
        const item = queue.value.get(id);
        if (item && !item.isActive) {
            item.isActive = true;
        }
    }

    /**
     * Check if an item is active (countdown running).
     */
    function isActive(id: T): boolean {
        const item = queue.value.get(id);
        return item ? item.isActive : false;
    }

    /**
     * Remove an item from the queue (e.g., when user reacts).
     */
    function removeFromQueue(id: T): void {
        queue.value.delete(id);

        // Stop tick interval if no more items
        if (queue.value.size === 0 && tickInterval) {
            clearInterval(tickInterval);
            tickInterval = null;
        }
    }

    /**
     * Check if an item is in the queue.
     */
    function isQueued(id: T): boolean {
        return queue.value.has(id);
    }

    /**
     * Freeze a specific item's countdown (called on hover).
     * Also freezes globally for timer manager coordination.
     */
    function freezeItem(id: T): void {
        const item = queue.value.get(id);
        if (item) {
            item.isFrozen = true;
            frozenItems.add(id);
        }
        // Also freeze globally for timer manager coordination
        timerManager.freeze(systemId);
    }

    /**
     * Unfreeze a specific item's countdown (called when hover ends).
     * Also unfreezes globally if no items are frozen.
     */
    function unfreezeItem(id: T): void {
        const item = queue.value.get(id);
        if (item) {
            item.isFrozen = false;
            frozenItems.delete(id);
        }
        // Unfreeze globally if no items are frozen
        if (frozenItems.size === 0) {
            timerManager.unfreeze(systemId);
        }
    }

    /**
     * Freeze all countdowns (called on hover) - legacy method for backward compatibility.
     * Now delegates to per-item freezing.
     */
    function freeze(): void {
        // Freeze all items in the queue
        queue.value.forEach((item, id) => {
            item.isFrozen = true;
            frozenItems.add(id);
        });
        timerManager.freeze(systemId);
    }

    /**
     * Unfreeze all countdowns (called when hover ends) - legacy method for backward compatibility.
     * Now delegates to per-item unfreezing.
     */
    function unfreeze(): void {
        // Unfreeze all items in the queue
        queue.value.forEach((item) => {
            item.isFrozen = false;
        });
        frozenItems.clear();
        timerManager.unfreeze(systemId);
    }

    /**
     * Get remaining time for a specific item.
     */
    function getRemaining(id: T): number {
        const item = queue.value.get(id);
        return item ? item.remaining : 0;
    }

    /**
     * Get progress (0-1) for a specific item.
     */
    function getProgress(id: T): number {
        const remaining = getRemaining(id);
        return 1 - (remaining / COUNTDOWN_DURATION);
    }

    /**
     * Tick function that decrements countdowns for active items only.
     */
    function tick(): void {
        if (isFrozen.value) {
            return; // Don't tick when globally frozen (for timer manager coordination)
        }

        const expired: T[] = [];

        queue.value.forEach((item, id) => {
            // Only tick active items (preview loaded) that are not frozen
            if (!item.isActive || item.isFrozen) {
                return;
            }

            item.remaining -= TICK_INTERVAL;
            if (item.remaining <= 0) {
                expired.push(id);
            }
        });

        // Remove expired items and trigger callback
        if (expired.length > 0) {
            expired.forEach((id) => {
                queue.value.delete(id);
            });

            // Call the onExpire callback with all expired IDs
            if (onExpire) {
                onExpire(expired);
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
     * Get all queued IDs.
     */
    function getQueuedIds(): T[] {
        return Array.from(queue.value.keys());
    }

    // Cleanup on unmount (only if called within a component context)
    const instance = getCurrentInstance();
    if (instance) {
        onUnmounted(() => {
            timerManager.unregisterSystem(systemId);
            clearQueue();
        });
    }

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
        freezeItem,
        unfreezeItem,
        freeze,
        unfreeze,
        getRemaining,
        getProgress,
        getQueuedIds,
        clearQueue,
    };
}

