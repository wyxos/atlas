import { ref, computed, onUnmounted } from 'vue';

export interface QueuedReaction {
    id: string;
    fileId: number;
    type: 'love' | 'like' | 'dislike' | 'funny';
    previewUrl?: string;
    countdown: number;
    timeoutId: ReturnType<typeof setTimeout> | null;
    intervalId: ReturnType<typeof setInterval> | null;
}

const QUEUE_DELAY_MS = 5000; // 5 seconds

const queuedReactions = ref<QueuedReaction[]>([]);

export function useReactionQueue() {
    function queueReaction(
        fileId: number,
        type: 'love' | 'like' | 'dislike' | 'funny',
        executeCallback: (fileId: number, type: 'love' | 'like' | 'dislike' | 'funny') => Promise<void>,
        previewUrl?: string
    ): void {
        // Check if this reaction is already queued for this file
        const existingIndex = queuedReactions.value.findIndex(
            (q) => q.fileId === fileId
        );

        // If already queued, cancel the previous one
        if (existingIndex !== -1) {
            const existing = queuedReactions.value[existingIndex];
            if (existing.timeoutId) {
                clearTimeout(existing.timeoutId);
            }
            queuedReactions.value.splice(existingIndex, 1);
        }

        // Create new queued reaction
        const queueId = `${fileId}-${Date.now()}`;
        const queuedReaction: QueuedReaction = {
            id: queueId,
            fileId,
            type,
            previewUrl,
            countdown: QUEUE_DELAY_MS / 1000, // Start at 5 seconds
            timeoutId: null,
            intervalId: null,
        };

        // Add to queue
        queuedReactions.value.push(queuedReaction);

        // Start countdown timer
        const startTime = Date.now();
        const countdownInterval = setInterval(() => {
            const elapsed = Date.now() - startTime;
            const remaining = Math.max(0, QUEUE_DELAY_MS - elapsed);
            // Store remaining time in seconds (with decimals for smooth progress)
            // Find the item in the array and update it to ensure reactivity
            const index = queuedReactions.value.findIndex((q) => q.id === queueId);
            if (index !== -1) {
                queuedReactions.value[index].countdown = remaining / 1000;
            }

            if (remaining <= 0) {
                clearInterval(countdownInterval);
                const finalIndex = queuedReactions.value.findIndex((q) => q.id === queueId);
                if (finalIndex !== -1) {
                    queuedReactions.value[finalIndex].intervalId = null;
                    queuedReactions.value[finalIndex].countdown = 0;
                }
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

    function cancelReaction(fileId: number): void {
        const index = queuedReactions.value.findIndex((q) => q.fileId === fileId);
        if (index !== -1) {
            const queued = queuedReactions.value[index];
            if (queued.timeoutId) {
                clearTimeout(queued.timeoutId);
            }
            if (queued.intervalId) {
                clearInterval(queued.intervalId);
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

    // Cleanup on unmount
    onUnmounted(() => {
        cancelAll();
    });

    return {
        queuedReactions: computed(() => queuedReactions.value),
        queueReaction,
        cancelReaction,
        cancelAll,
    };
}

