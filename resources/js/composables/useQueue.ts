import { computed, ref } from 'vue';

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

type QueueAddOptions = {
    id: string;
    duration: number;
    onComplete: () => void | Promise<void>;
    onStart?: () => void | Promise<void>;
    metadata?: unknown;
    startImmediately?: boolean;
};

type QueueUpdateOptions = Partial<Pick<QueueItem, 'onComplete' | 'metadata'>>;

const queueItems = ref<Map<string, QueueItem>>(new Map());
const isFrozen = ref(false);
const isModalOpen = ref(false);
const updateTrigger = ref(0);

let lastUpdateTime: number | null = null;
let lastUIUpdateTime: number | null = null;
let updateIntervalId: ReturnType<typeof setInterval> | null = null;
let pendingUnfreezeTimeoutId: ReturnType<typeof setTimeout> | null = null;

const UPDATE_INTERVAL_MS = 16;
const UI_UPDATE_INTERVAL_MS = 100;
const UNFREEZE_DELAY_MS = 2000;

function bumpUpdateTrigger(): void {
    updateTrigger.value++;
}

function getQueueItem(id: string): QueueItem | undefined {
    return queueItems.value.get(id);
}

function getQueueEntries(): QueueItem[] {
    return Array.from(queueItems.value.values());
}

function isPromiseLike(value: unknown): value is Promise<unknown> {
    return value instanceof Promise;
}

function runQueueCallback(
    callback: (() => void | Promise<void>) | undefined,
    callbackName: 'onStart' | 'onComplete',
    id: string,
): void {
    if (!callback) {
        return;
    }

    try {
        const result = callback();
        if (isPromiseLike(result)) {
            void result.catch((error) => {
                console.error(`Error executing ${callbackName} for queue item ${id}:`, error);
            });
        }
    } catch (error) {
        console.error(`Error executing ${callbackName} for queue item ${id}:`, error);
    }
}

function clearPendingUnfreeze(): void {
    if (pendingUnfreezeTimeoutId !== null) {
        clearTimeout(pendingUnfreezeTimeoutId);
        pendingUnfreezeTimeoutId = null;
    }
}

function stopTimerLoop(): void {
    if (updateIntervalId !== null) {
        clearInterval(updateIntervalId);
        updateIntervalId = null;
    }

    lastUpdateTime = null;
    lastUIUpdateTime = null;
}

function updateQueueItems(deltaTime: number): void {
    if (isFrozen.value) {
        return;
    }

    const expiredIds: string[] = [];

    queueItems.value.forEach((item, id) => {
        if (!item.isStarted || item.isPaused) {
            return;
        }

        item.remainingTime -= deltaTime;

        if (item.remainingTime <= 0) {
            expiredIds.push(id);
            runQueueCallback(item.onComplete, 'onComplete', id);
        }
    });

    if (expiredIds.length === 0) {
        return;
    }

    expiredIds.forEach((id) => {
        queueItems.value.delete(id);
    });

    bumpUpdateTrigger();
}

function startTimerLoop(): void {
    if (updateIntervalId !== null) {
        return;
    }

    lastUpdateTime = performance.now();
    lastUIUpdateTime = lastUpdateTime;

    updateIntervalId = setInterval(() => {
        const currentTime = performance.now();

        if (lastUpdateTime === null) {
            lastUpdateTime = currentTime;
            return;
        }

        const deltaTime = currentTime - lastUpdateTime;
        lastUpdateTime = currentTime;

        updateQueueItems(deltaTime);

        if (lastUIUpdateTime === null || currentTime - lastUIUpdateTime >= UI_UPDATE_INTERVAL_MS) {
            lastUIUpdateTime = currentTime;
            bumpUpdateTrigger();
        }

        if (queueItems.value.size === 0 && !isFrozen.value) {
            stopTimerLoop();
        }
    }, UPDATE_INTERVAL_MS) as unknown as ReturnType<typeof setInterval>;
}

function add(item: QueueAddOptions): string {
    if (queueItems.value.has(item.id)) {
        remove(item.id);
    }

    const startImmediately = item.startImmediately !== false;
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

    queueItems.value.set(item.id, queueItem);
    bumpUpdateTrigger();

    if (startImmediately) {
        runQueueCallback(queueItem.onStart, 'onStart', item.id);
    }

    startTimerLoop();
    return item.id;
}

function update(id: string, updates: QueueUpdateOptions): boolean {
    const item = getQueueItem(id);
    if (!item) {
        return false;
    }

    if (updates.onComplete !== undefined) {
        item.onComplete = updates.onComplete;
    }

    if (updates.metadata !== undefined) {
        item.metadata = updates.metadata;
    }

    bumpUpdateTrigger();
    return true;
}

function stop(id: string): boolean {
    const item = getQueueItem(id);
    if (!item || !item.isStarted || item.isPaused) {
        return false;
    }

    item.isPaused = true;
    item.pausedAt = performance.now();
    item.elapsedWhenPaused = item.duration - item.remainingTime;
    bumpUpdateTrigger();

    return true;
}

function resume(id: string): boolean {
    const item = getQueueItem(id);
    if (!item || !item.isStarted || !item.isPaused) {
        return false;
    }

    item.isPaused = false;
    item.pausedAt = undefined;
    bumpUpdateTrigger();

    startTimerLoop();
    return true;
}

function start(id: string): boolean {
    const item = getQueueItem(id);
    if (!item || item.isStarted) {
        return false;
    }

    item.isStarted = true;
    item.startTime = performance.now();
    bumpUpdateTrigger();

    runQueueCallback(item.onStart, 'onStart', id);
    startTimerLoop();
    return true;
}

function remove(id: string): boolean {
    const existed = queueItems.value.delete(id);
    if (!existed) {
        return false;
    }

    if (queueItems.value.size === 0) {
        stopTimerLoop();
    }

    bumpUpdateTrigger();
    return true;
}

function has(id: string): boolean {
    return queueItems.value.has(id);
}

function getProgress(id: string): number {
    updateTrigger.value;

    const item = getQueueItem(id);
    if (!item) {
        return 0;
    }

    const elapsed = item.duration - item.remainingTime;
    return Math.max(0, Math.min(100, (elapsed / item.duration) * 100));
}

function getRemainingTime(id: string): number {
    updateTrigger.value;

    const item = getQueueItem(id);
    if (!item) {
        return 0;
    }

    return Math.max(0, item.remainingTime);
}

function getProgressComputed(id: string) {
    return computed(() => getProgress(id));
}

function getRemainingTimeComputed(id: string) {
    return computed(() => getRemainingTime(id));
}

function getAll(): QueueItem[] {
    updateTrigger.value;
    return getQueueEntries();
}

function getAllComputed() {
    return computed(() => {
        updateTrigger.value;
        return getQueueEntries();
    });
}

function freezeAll(): void {
    clearPendingUnfreeze();

    if (isFrozen.value) {
        return;
    }

    isFrozen.value = true;
    bumpUpdateTrigger();
}

function unfreezeAll(): void {
    clearPendingUnfreeze();

    pendingUnfreezeTimeoutId = setTimeout(() => {
        pendingUnfreezeTimeoutId = null;

        if (!isFrozen.value) {
            return;
        }

        isFrozen.value = false;
        bumpUpdateTrigger();

        if (queueItems.value.size > 0) {
            startTimerLoop();
        }
    }, UNFREEZE_DELAY_MS);
}

function unfreezeImmediately(): void {
    clearPendingUnfreeze();

    if (!isFrozen.value) {
        return;
    }

    isFrozen.value = false;
    bumpUpdateTrigger();

    if (queueItems.value.size > 0) {
        startTimerLoop();
    }
}

function setModalOpen(open: boolean): void {
    if (isModalOpen.value === open) {
        return;
    }

    isModalOpen.value = open;
    bumpUpdateTrigger();
}

function clear(): void {
    queueItems.value.clear();
    clearPendingUnfreeze();
    stopTimerLoop();
    bumpUpdateTrigger();
}

function reset(): void {
    clear();
    isFrozen.value = false;
    isModalOpen.value = false;
    lastUpdateTime = null;
    lastUIUpdateTime = null;
    bumpUpdateTrigger();
}

const queueState = {
    queue: computed(() => {
        updateTrigger.value;
        return queueItems.value;
    }),
};

const queueCollection = {
    add,
    update,
    remove,
    has,
    getAll,
    getAllComputed,
    clear,
    reset,
};

const queueCountdown = {
    stop,
    resume,
    start,
};

const queueFreeze = {
    freezeAll,
    unfreezeAll,
    unfreezeImmediately,
    isFrozen: computed(() => isFrozen.value),
};

const queueModal = {
    setModalOpen,
    isModalOpen: computed(() => isModalOpen.value),
};

const queueQuery = {
    getProgress,
    getRemainingTime,
    getProgressComputed,
    getRemainingTimeComputed,
};

/**
 * Shared queue manager module with built-in countdown timers.
 * This is a single reactive manager, not a per-component composable.
 */
export const queueManager = {
    collection: queueCollection,
    countdown: queueCountdown,
    freeze: queueFreeze,
    modal: queueModal,
    query: queueQuery,
    state: queueState,
} as const;

export type QueueManager = typeof queueManager;
