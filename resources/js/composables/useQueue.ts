import { ref, computed } from 'vue';

export interface QueueItem {
    id: string;
    duration: number;
    remainingTime: number;
    onComplete: () => void | Promise<void>;
    onStart?: () => void | Promise<void>;
    metadata?: unknown;
    isPaused: boolean;
    isStarted: boolean;
    startTime: number;
    pausedAt?: number;
    elapsedWhenPaused: number;
}

// Global state (singleton pattern)
const queue = ref<Map<string, QueueItem>>(new Map());
const isFrozen = ref(false);
const isModalOpen = ref(false);
// Reactive trigger to force Vue reactivity on timer updates
const updateTrigger = ref(0);
let lastUpdateTime: number | null = null;
let updateIntervalId: ReturnType<typeof setInterval> | null = null;
// Throttle UI updates to reduce reactivity overhead (update every 100ms for display)
let lastUIUpdateTime: number | null = null;
const UI_UPDATE_INTERVAL_MS = 100;

/**
 * Global queue manager with built-in countdown timers.
 * Manages queued items with countdowns, freeze control, and execution.
 */
export function useQueue() {
    /**
     * Update all queue items (called by timer loop).
     */
    function updateQueueItems(deltaTime: number): void {
        // Only update if not frozen
        if (isFrozen.value) {
            return;
        }

        const itemsToRemove: string[] = [];

        queue.value.forEach((item, id) => {
            // Skip items that haven't started yet
            if (!item.isStarted) {
                return;
            }

            // Skip paused items
            if (item.isPaused) {
                return;
            }

            // Update remaining time
            item.remainingTime -= deltaTime;

            // Check if countdown expired
            if (item.remainingTime <= 0) {
                itemsToRemove.push(id);
                // Execute onComplete callback
                try {
                    const result = item.onComplete();
                    // Handle promise if returned
                    if (result instanceof Promise) {
                        result.catch((error) => {
                            console.error(`Error executing onComplete for queue item ${id}:`, error);
                        });
                    }
                } catch (error) {
                    console.error(`Error executing onComplete for queue item ${id}:`, error);
                }
            }
        });

        // Remove expired items
        itemsToRemove.forEach((id) => {
            queue.value.delete(id);
        });
        // Trigger reactivity for removal
        if (itemsToRemove.length > 0) {
            updateTrigger.value++;
        }

    }

    /**
     * Start the timer loop if not already running.
     */
    function startTimerLoop(): void {
        if (updateIntervalId !== null) {
            return; // Already running
        }

        lastUpdateTime = performance.now();
        const UPDATE_INTERVAL_MS = 16; // ~60fps
        updateIntervalId = setInterval(() => {
            const currentTime = performance.now();
            if (lastUpdateTime !== null) {
                const deltaTime = currentTime - lastUpdateTime;
                lastUpdateTime = currentTime;
            updateQueueItems(deltaTime);

            // Throttle UI update trigger to reduce reactivity overhead
            // Update every 100ms instead of every frame (~60fps) for better performance
            if (lastUIUpdateTime === null || currentTime - lastUIUpdateTime >= UI_UPDATE_INTERVAL_MS) {
                lastUIUpdateTime = currentTime;
                // Trigger reactivity for progress updates (components will re-render)
                updateTrigger.value++;
            }
            } else {
                lastUpdateTime = currentTime;
            }

            // Stop if no items and not frozen
            if (queue.value.size === 0 && !isFrozen.value) {
                stopTimerLoop();
            }
        }, UPDATE_INTERVAL_MS) as unknown as ReturnType<typeof setInterval>;
    }

    /**
     * Stop the timer loop.
     */
    function stopTimerLoop(): void {
        if (updateIntervalId !== null) {
            clearInterval(updateIntervalId);
            updateIntervalId = null;
        }

        lastUpdateTime = null;
    }

    /**
     * Add an item to the queue.
     */
    function add(item: {
        id: string;
        duration: number;
        onComplete: () => void | Promise<void>;
        onStart?: () => void | Promise<void>;
        metadata?: unknown;
        startImmediately?: boolean;
    }): string {
        // If item already exists, remove it first
        if (queue.value.has(item.id)) {
            remove(item.id);
        }

        const startImmediately = item.startImmediately !== false; // Default to true

        const queueItem: QueueItem = {
            id: item.id,
            duration: item.duration,
            remainingTime: item.duration,
            onComplete: item.onComplete,
            onStart: item.onStart,
            metadata: item.metadata,
            isPaused: false,
            isStarted: startImmediately,
            startTime: performance.now(),
            elapsedWhenPaused: 0,
        };

        queue.value.set(item.id, queueItem);

        // Execute onStart callback if provided and starting immediately
        if (startImmediately && queueItem.onStart) {
            try {
                const result = queueItem.onStart();
                if (result instanceof Promise) {
                    result.catch((error) => {
                        console.error(`Error executing onStart for queue item ${item.id}:`, error);
                    });
                }
            } catch (error) {
                console.error(`Error executing onStart for queue item ${item.id}:`, error);
            }
        }

        // Start timer loop if not running (needed even for non-started items for when they do start)
        startTimerLoop();

        return item.id;
    }

    /**
     * Update an existing queue item.
     */
    function update(id: string, updates: Partial<Pick<QueueItem, 'onComplete' | 'metadata'>>): boolean {
        const item = queue.value.get(id);
        if (!item) {
            return false;
        }

        if (updates.onComplete !== undefined) {
            item.onComplete = updates.onComplete;
        }

        if (updates.metadata !== undefined) {
            item.metadata = updates.metadata;
        }

        return true;
    }

    /**
     * Stop (pause) a queue item's countdown.
     * Only works on items that have started.
     */
    function stop(id: string): boolean {
        const item = queue.value.get(id);
        if (!item || !item.isStarted || item.isPaused) {
            return false;
        }

        item.isPaused = true;
        item.pausedAt = performance.now();
        // Calculate elapsed time so far
        item.elapsedWhenPaused = item.duration - item.remainingTime;

        return true;
    }

    /**
     * Resume (unpause) a queue item's countdown.
     * Only works on items that have started.
     */
    function resume(id: string): boolean {
        const item = queue.value.get(id);
        if (!item || !item.isStarted || !item.isPaused) {
            return false;
        }

        item.isPaused = false;
        // Remaining time is already correct (wasn't being decremented while paused)
        item.pausedAt = undefined;
        // Note: elapsedWhenPaused is kept for reference but not used in calculation

        // Ensure timer loop is running
        startTimerLoop();

        return true;
    }

    /**
     * Start the countdown for an item that was added with startImmediately: false.
     * Items that haven't started are not affected by freeze/unfreeze.
     */
    function start(id: string): boolean {
        const item = queue.value.get(id);
        if (!item || item.isStarted) {
            return false;
        }

        item.isStarted = true;
        item.startTime = performance.now();

        // Execute onStart callback if provided
        if (item.onStart) {
            try {
                const result = item.onStart();
                if (result instanceof Promise) {
                    result.catch((error) => {
                        console.error(`Error executing onStart for queue item ${id}:`, error);
                    });
                }
            } catch (error) {
                console.error(`Error executing onStart for queue item ${id}:`, error);
            }
        }

        // Ensure timer loop is running
        startTimerLoop();

        return true;
    }

    /**
     * Remove an item from the queue.
     */
    function remove(id: string): boolean {
        const existed = queue.value.delete(id);

        // Stop timer loop if no items left
        if (queue.value.size === 0) {
            stopTimerLoop();
        }

        return existed;
    }

    /**
     * Check if an item exists in the queue.
     */
    function has(id: string): boolean {
        return queue.value.has(id);
    }

    /**
     * Get progress percentage (0-100) for an item.
     * Reactive: will trigger re-renders when countdown updates.
     */
    function getProgress(id: string): number {
        // Access updateTrigger to make this reactive
        updateTrigger.value;  

        const item = queue.value.get(id);
        if (!item) {
            return 0;
        }

        const elapsed = item.duration - item.remainingTime;
        const progress = Math.max(0, Math.min(100, (elapsed / item.duration) * 100));

        return progress;
    }

    /**
     * Get remaining time in milliseconds for an item.
     * Reactive: will trigger re-renders when countdown updates.
     */
    function getRemainingTime(id: string): number {
        // Access updateTrigger to make this reactive
        updateTrigger.value;  

        const item = queue.value.get(id);
        if (!item) {
            return 0;
        }

        return Math.max(0, item.remainingTime);
    }

    /**
     * Get a reactive computed for an item's progress.
     * Use this in components for better performance (computed is cached).
     */
    function getProgressComputed(id: string) {
        return computed(() => getProgress(id));
    }

    /**
     * Get a reactive computed for an item's remaining time.
     * Use this in components for better performance (computed is cached).
     */
    function getRemainingTimeComputed(id: string) {
        return computed(() => getRemainingTime(id));
    }

    /**
     * Get all queue items as an array.
     * Reactive: will trigger re-renders when items are added/removed.
     */
    function getAll(): QueueItem[] {
        // Access updateTrigger to make this reactive
        updateTrigger.value;  
        return Array.from(queue.value.values());
    }

    /**
     * Get a reactive computed for all queue items.
     * Use this in components for better performance (computed is cached).
     */
    function getAllComputed() {
        return computed(() => {
            updateTrigger.value; // Access to make reactive
            return Array.from(queue.value.values());
        });
    }

    // Track unfreeze timeout to allow cancellation if freeze is called again
    let unfreezeTimeout: ReturnType<typeof setTimeout> | null = null;

    /**
     * Freeze all countdowns (pause timer loop).
     */
    function freezeAll(): void {
        // Cancel any pending unfreeze timeout
        if (unfreezeTimeout) {
            clearTimeout(unfreezeTimeout);
            unfreezeTimeout = null;
        }
        isFrozen.value = true;
        // Timer loop will continue but won't update items when frozen
    }

    /**
     * Unfreeze all countdowns (resume timer loop).
     * Resumes after a 2 second delay to give user time to move mouse away.
     */
    function unfreezeAll(): void {
        // Clear any pending unfreeze timeout
        if (unfreezeTimeout) {
            clearTimeout(unfreezeTimeout);
            unfreezeTimeout = null;
        }

        // Keep isFrozen = true during the delay, then set to false and resume
        // This ensures the UI shows frozen state during the delay
        unfreezeTimeout = setTimeout(() => {
            isFrozen.value = false;
            unfreezeTimeout = null;
            // Timer loop will resume updating items
            if (queue.value.size > 0) {
                startTimerLoop();
            }
        }, 2000);
    }

    /**
     * Immediately unfreeze all countdowns (no delay).
     * Use this for modals or other cases where immediate unfreezing is needed.
     */
    function unfreezeImmediately(): void {
        // Clear any pending unfreeze timeout
        if (unfreezeTimeout) {
            clearTimeout(unfreezeTimeout);
            unfreezeTimeout = null;
        }

        // Immediately unfreeze
        isFrozen.value = false;
        // Timer loop will resume updating items
        if (queue.value.size > 0) {
            startTimerLoop();
        }
    }

    /**
     * Set modal open state (affects new items).
     */
    function setModalOpen(open: boolean): void {
        isModalOpen.value = open;
    }

    /**
     * Clear all items from the queue.
     */
    function clear(): void {
        queue.value.clear();
        stopTimerLoop();
    }

    /**
     * Reset all state (useful for tests).
     */
    function reset(): void {
        clear();
        isFrozen.value = false;
        isModalOpen.value = false;
        updateTrigger.value = 0;
        lastUIUpdateTime = null;
    }

    return {
        // Queue management
        add,
        update,
        remove,
        has,
        getAll,
        clear,
        reset,

        // Countdown control
        stop,
        resume,
        start,

        // Freeze control
        freezeAll,
        unfreezeAll,
        unfreezeImmediately,
        // Expose isFrozen as computed to ensure reactivity when passed as prop
        // The computed will re-evaluate whenever isFrozen.value changes
        isFrozen: computed(() => isFrozen.value),

        // Modal state
        setModalOpen,
        isModalOpen: computed(() => isModalOpen.value),

        // Query methods
        getProgress,
        getRemainingTime,
        getProgressComputed,
        getRemainingTimeComputed,
        getAllComputed,

        // Internal state (for testing)
        queue: computed(() => queue.value),
    };
}
