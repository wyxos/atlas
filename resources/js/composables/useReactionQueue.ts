import { ref, computed, onUnmounted, h, getCurrentInstance, nextTick } from 'vue';
import { useToast } from 'vue-toastification';
import SingleReactionToast from '../components/toasts/SingleReactionToast.vue';
import BatchReactionToast from '../components/toasts/BatchReactionToast.vue';
import { useTimerManager } from './useTimerManager';
import type { ReactionType } from '@/types/reaction';

export interface QueuedReaction {
    id: string;
    fileId: number;
    type: ReactionType;
    previewUrl?: string;
    countdown: number;
    timeoutId: ReturnType<typeof setTimeout> | null;
    intervalId: ReturnType<typeof setInterval> | null;
    startTime: number; // Track when countdown started
    pausedAt: number | null; // Track when paused (null if not paused)
    pausedRemaining: number | null; // Remaining time when paused
    executeCallback: (fileId: number, type: ReactionType) => Promise<void>; // Store callback for resume
    restoreItem?: (tabId: number, isTabActive: (tabId: number) => boolean) => void | Promise<void>; // Callback to restore item to masonry with tab check
    restoreBatch?: (tabId: number, isTabActive: (tabId: number) => boolean) => void | Promise<void>; // Callback to restore entire batch to masonry with tab check
    tabId?: number; // Tab ID where the item was removed
    itemIndex?: number; // Original index of the item in the masonry
    item?: any; // Store the item data for restoration
    batchId?: string; // Optional batch identifier for grouping batch reactions (e.g., containerId)
    toastId?: string | number; // Toast ID for Vue Toastification
}

const QUEUE_DELAY_MS = 5000; // 5 seconds

const queuedReactions = ref<QueuedReaction[]>([]);
const isPaused = ref(false); // Global pause state for all reactions
const batchToastIds = ref<Map<string, string | number>>(new Map()); // Track batch toast IDs

export function useReactionQueue() {
    const toast = useToast();

    // Register with centralized timer manager
    const timerManager = useTimerManager();
    const systemId = 'reaction-queue' as const;

    // Define cancel functions first so they're available in queueReaction callbacks
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

            // Dismiss toast
            if (queued.batchId) {
                const batchToastId = batchToastIds.value.get(queued.batchId);
                if (batchToastId) {
                    // Check if this is the last reaction in the batch
                    const remainingBatchReactions = queuedReactions.value.filter((q) => q.batchId === queued.batchId && q.id !== queued.id);
                    if (remainingBatchReactions.length === 0) {
                        toast.dismiss(batchToastId);
                        batchToastIds.value.delete(queued.batchId);
                    }
                }
            } else if (queued.toastId) {
                toast.dismiss(queued.toastId);
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

    async function cancelBatch(batchId: string, isTabActive?: (tabId: number) => boolean): Promise<void> {
        const batchReactions = queuedReactions.value.filter((q) => q.batchId === batchId);

        if (batchReactions.length === 0) {
            return;
        }

        // Clear all timers first
        for (const queued of batchReactions) {
            if (queued.timeoutId) {
                clearTimeout(queued.timeoutId);
            }
            if (queued.intervalId) {
                clearInterval(queued.intervalId);
            }
        }

        // Dismiss batch toast
        const batchToastId = batchToastIds.value.get(batchId);
        if (batchToastId) {
            toast.dismiss(batchToastId);
            batchToastIds.value.delete(batchId);
        }

        // Try to use batch restore callback if available (more efficient)
        const firstReaction = batchReactions[0];
        if (firstReaction.restoreBatch && firstReaction.tabId !== undefined) {
            const tabIsActive = isTabActive ? isTabActive(firstReaction.tabId) : true;
            if (tabIsActive) {
                await firstReaction.restoreBatch(firstReaction.tabId, isTabActive || (() => true));
            }
        } else {
            // Fallback: restore items individually if no batch restore callback
            for (const queued of batchReactions) {
                if (queued.restoreItem && queued.tabId !== undefined) {
                    const tabIsActive = isTabActive ? isTabActive(queued.tabId) : true;
                    if (tabIsActive) {
                        await queued.restoreItem(queued.tabId, isTabActive || (() => true));
                    }
                }
            }
        }

        // Remove all batch reactions
        queuedReactions.value = queuedReactions.value.filter((q) => q.batchId !== batchId);
    }

    function queueReaction(
        fileId: number,
        type: ReactionType,
        executeCallback: (fileId: number, type: ReactionType) => Promise<void>,
        previewUrl?: string,
        restoreItem?: (tabId: number, isTabActive: (tabId: number) => boolean) => void,
        tabId?: number,
        itemIndex?: number,
        item?: any,
        batchId?: string,
        restoreBatch?: (tabId: number, isTabActive: (tabId: number) => boolean) => void | Promise<void>
    ): void {
        // Check if this reaction is already queued for this file
        const existingIndex = queuedReactions.value.findIndex(
            (q) => q.fileId === fileId
        );

        // If already queued, preserve restore data from existing reaction if new call doesn't have it
        let preservedRestoreItem = restoreItem;
        let preservedRestoreBatch = restoreBatch;
        let preservedTabId = tabId;
        let preservedItemIndex = itemIndex;
        let preservedItem = item;
        let preservedBatchId = batchId;

        if (existingIndex !== -1) {
            const existing = queuedReactions.value[existingIndex];
            if (existing.timeoutId) {
                clearTimeout(existing.timeoutId);
            }
            if (existing.intervalId) {
                clearInterval(existing.intervalId);
            }

            // Dismiss existing toast before creating a new one
            if (existing.batchId) {
                const batchToastId = batchToastIds.value.get(existing.batchId);
                if (batchToastId) {
                    // Check if this is the last reaction in the batch
                    const remainingBatchReactions = queuedReactions.value.filter((q) => q.batchId === existing.batchId && q.id !== existing.id);
                    if (remainingBatchReactions.length === 0) {
                        toast.dismiss(batchToastId);
                        batchToastIds.value.delete(existing.batchId);
                    }
                }
            } else if (existing.toastId) {
                toast.dismiss(existing.toastId);
            }

            // Preserve restore data from existing reaction if new call doesn't provide it
            if (!restoreItem && existing.restoreItem) {
                preservedRestoreItem = existing.restoreItem;
            }
            if (!restoreBatch && existing.restoreBatch) {
                preservedRestoreBatch = existing.restoreBatch;
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
            // Use new batchId if provided, otherwise preserve existing batchId
            if (batchId !== undefined) {
                preservedBatchId = batchId; // Always use new batchId if provided
            } else if (existing.batchId) {
                preservedBatchId = existing.batchId; // Preserve existing batchId if new call doesn't provide one
            }
            queuedReactions.value.splice(existingIndex, 1);
        }

        // Create new queued reaction
        const queueId = `${fileId}-${Date.now()}`;
        const startTime = Date.now();
        
        // Check if timer manager is currently frozen (e.g., modal is open)
        // If frozen, start the reaction in a paused state
        const isCurrentlyFrozen = timerManager.isFrozen.value;
        const queuedReaction: QueuedReaction = {
            id: queueId,
            fileId,
            type,
            previewUrl,
            countdown: QUEUE_DELAY_MS / 1000, // Start at 5 seconds
            timeoutId: null,
            intervalId: null,
            startTime,
            pausedAt: isCurrentlyFrozen ? startTime : null, // Pause immediately if frozen
            pausedRemaining: isCurrentlyFrozen ? QUEUE_DELAY_MS : null, // Full delay remaining if frozen
            executeCallback,
            restoreItem: preservedRestoreItem,
            restoreBatch: preservedRestoreBatch,
            tabId: preservedTabId,
            itemIndex: preservedItemIndex,
            item: preservedItem,
            batchId: preservedBatchId,
        };

        // Add to queue
        queuedReactions.value.push(queuedReaction);
        
        // If currently frozen, don't start timers - they'll start when unfrozen
        if (isCurrentlyFrozen) {
            // Update isPaused state to match
            if (!isPaused.value) {
                isPaused.value = true;
            }
            // Create toast but don't start countdown yet
            if (preservedBatchId) {
                // Batch reaction - defer toast creation/update to nextTick
                nextTick(() => {
                    const existingBatchToastId = batchToastIds.value.get(preservedBatchId);
                    const batchReactions = queuedReactions.value.filter((q) => q.batchId === preservedBatchId);

                    if (batchReactions.length === 0) {
                        return;
                    }

                    if (existingBatchToastId) {
                        const firstReaction = batchReactions[0];
                        toast.update(existingBatchToastId, {
                            content: h(BatchReactionToast, {
                                batchId: preservedBatchId,
                                reactions: batchReactions,
                                type: firstReaction?.type || 'like',
                                countdown: firstReaction?.countdown || QUEUE_DELAY_MS / 1000,
                                onCancelBatch: (batchId: string) => {
                                    cancelBatch(batchId);
                                },
                            }),
                        });
                    } else {
                        const firstReaction = batchReactions[0];
                        const toastId = toast({
                            content: h(BatchReactionToast, {
                                batchId: preservedBatchId,
                                reactions: batchReactions,
                                type: firstReaction?.type || 'like',
                                countdown: firstReaction?.countdown || QUEUE_DELAY_MS / 1000,
                                onCancelBatch: (batchId: string) => {
                                    cancelBatch(batchId);
                                },
                            }),
                            timeout: false,
                            closeOnClick: false,
                        });
                        batchToastIds.value.set(preservedBatchId, toastId);
                        batchReactions.forEach((r) => {
                            r.toastId = toastId;
                        });
                    }
                });
            } else {
                // Single reaction - create individual toast
                const toastId = toast({
                    content: h(SingleReactionToast, {
                        fileId,
                        type,
                        previewUrl,
                        countdown: QUEUE_DELAY_MS / 1000,
                        onCancel: (fileId: number) => {
                            cancelReaction(fileId);
                        },
                    }),
                    timeout: false,
                    closeOnClick: false,
                });
                queuedReaction.toastId = toastId;
            }
            // Don't start timers - they'll start when unfrozen via internalResumeAll
            return;
        }

        // Create toast for this reaction
        if (preservedBatchId) {
            // Batch reaction - defer toast creation/update to nextTick to ensure all reactions are queued first
            // This prevents creating multiple toasts when reactions are queued synchronously
            nextTick(() => {
                const existingBatchToastId = batchToastIds.value.get(preservedBatchId);
                const batchReactions = queuedReactions.value.filter((q) => q.batchId === preservedBatchId);

                // Skip if no reactions found (shouldn't happen, but safety check)
                if (batchReactions.length === 0) {
                    return;
                }

                if (existingBatchToastId) {
                    // Update existing batch toast
                    const firstReaction = batchReactions[0];
                    // Update toast with new content - use same format as toast creation
                    toast.update(existingBatchToastId, {
                        content: h(BatchReactionToast, {
                            batchId: preservedBatchId,
                            reactions: batchReactions,
                            type: firstReaction?.type || 'like',
                            countdown: firstReaction?.countdown || QUEUE_DELAY_MS / 1000,
                            onCancelBatch: (batchId: string) => {
                                cancelBatch(batchId);
                            },
                        }),
                    });
                } else {
                    // Create new batch toast
                    const firstReaction = batchReactions[0];
                    const toastId = toast({
                        content: h(BatchReactionToast, {
                            batchId: preservedBatchId,
                            reactions: batchReactions,
                            type: firstReaction?.type || 'like',
                            countdown: firstReaction?.countdown || QUEUE_DELAY_MS / 1000,
                            onCancelBatch: (batchId: string) => {
                                cancelBatch(batchId);
                            },
                        }),
                        timeout: false, // We'll manage timeout manually
                        closeOnClick: false,
                    });
                    batchToastIds.value.set(preservedBatchId, toastId);
                    // Store toastId in all batch reactions
                    batchReactions.forEach((r) => {
                        r.toastId = toastId;
                    });
                }
            });
        } else {
            // Single reaction - create individual toast
            const toastId = toast({
                content: h(SingleReactionToast, {
                    fileId,
                    type,
                    previewUrl,
                    countdown: QUEUE_DELAY_MS / 1000,
                    onCancel: (fileId: number) => {
                        cancelReaction(fileId);
                    },
                }),
                timeout: false, // We'll manage timeout manually
                closeOnClick: false,
            });
            queuedReaction.toastId = toastId;
        }

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

            // Update toast with new countdown
            if (queued.batchId) {
                // Update batch toast
                const batchToastId = batchToastIds.value.get(queued.batchId);
                if (batchToastId) {
                    const batchReactions = queuedReactions.value.filter((q) => q.batchId === queued.batchId);
                    const firstReaction = batchReactions[0];
                    toast.update(batchToastId, {
                        content: h(BatchReactionToast, {
                            batchId: queued.batchId,
                            reactions: batchReactions,
                            type: firstReaction?.type || 'like',
                            countdown: firstReaction?.countdown || 0,
                            onCancelBatch: (batchId: string) => {
                                cancelBatch(batchId);
                            },
                        }),
                    });
                }
            } else if (queued.toastId) {
                // Update single reaction toast
                toast.update(queued.toastId, {
                    content: h(SingleReactionToast, {
                        fileId: queued.fileId,
                        type: queued.type,
                        previewUrl: queued.previewUrl,
                        countdown: remaining / 1000,
                        onCancel: (fileId: number) => {
                            cancelReaction(fileId);
                        },
                    }),
                });
            }

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

                    // Dismiss toast
                    if (queued.batchId) {
                        const batchToastId = batchToastIds.value.get(queued.batchId);
                        if (batchToastId) {
                            // Check if this is the last reaction in the batch
                            const remainingBatchReactions = queuedReactions.value.filter((q) => q.batchId === queued.batchId && q.id !== queueId);
                            if (remainingBatchReactions.length === 0) {
                                toast.dismiss(batchToastId);
                                batchToastIds.value.delete(queued.batchId);
                            }
                        }
                    } else if (queued.toastId) {
                        toast.dismiss(queued.toastId);
                    }

                    queuedReactions.value.splice(index, 1);
                }
            }
        }, QUEUE_DELAY_MS);
        queuedReaction.timeoutId = timeoutId;
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

        // Dismiss all toasts
        queuedReactions.value.forEach((queued) => {
            if (queued.batchId) {
                const batchToastId = batchToastIds.value.get(queued.batchId);
                if (batchToastId) {
                    toast.dismiss(batchToastId);
                }
            } else if (queued.toastId) {
                toast.dismiss(queued.toastId);
            }
        });

        batchToastIds.value.clear();
        queuedReactions.value = [];
    }

    // Internal pause/resume functions that actually modify state
    function internalPauseAll(): void {
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

    function internalResumeAll(): void {
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

                // Update toast with new countdown
                if (currentQueued.batchId) {
                    // Update batch toast
                    const batchToastId = batchToastIds.value.get(currentQueued.batchId);
                    if (batchToastId) {
                        const batchReactions = queuedReactions.value.filter((q) => q.batchId === currentQueued.batchId);
                        const firstReaction = batchReactions[0];
                        toast.update(batchToastId, {
                            content: h(BatchReactionToast, {
                                batchId: currentQueued.batchId,
                                reactions: batchReactions,
                                type: firstReaction?.type || 'like',
                                countdown: firstReaction?.countdown || 0,
                                onCancelBatch: (batchId: string) => {
                                    cancelBatch(batchId);
                                },
                            }),
                        });
                    }
                } else if (currentQueued.toastId) {
                    // Update single reaction toast
                    toast.update(currentQueued.toastId, {
                        content: h(SingleReactionToast, {
                            fileId: currentQueued.fileId,
                            type: currentQueued.type,
                            previewUrl: currentQueued.previewUrl,
                            countdown: currentRemaining / 1000,
                            onCancel: (fileId: number) => {
                                cancelReaction(fileId);
                            },
                        }),
                    });
                }

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

                        // Dismiss toast
                        if (finalQueued.batchId) {
                            const batchToastId = batchToastIds.value.get(finalQueued.batchId);
                            if (batchToastId) {
                                // Check if this is the last reaction in the batch
                                const remainingBatchReactions = queuedReactions.value.filter((q) => q.batchId === finalQueued.batchId && q.id !== queued.id);
                                if (remainingBatchReactions.length === 0) {
                                    toast.dismiss(batchToastId);
                                    batchToastIds.value.delete(finalQueued.batchId);
                                }
                            }
                        } else if (finalQueued.toastId) {
                            toast.dismiss(finalQueued.toastId);
                        }

                        queuedReactions.value.splice(index, 1);
                    }
                }
            }, remaining);
            queued.timeoutId = timeoutId;
        });
    }

    // Public pause/resume functions that delegate to timer manager
    function pauseAll(): void {
        timerManager.freeze(systemId);
    }

    function resumeAll(): void {
        timerManager.unfreeze(systemId);
    }

    // Register with timer manager
    timerManager.registerSystem(systemId, internalPauseAll, internalResumeAll);

    // Cleanup on unmount (only if called within a component context)
    const instance = getCurrentInstance();
    if (instance) {
        onUnmounted(() => {
            timerManager.unregisterSystem(systemId);
            cancelAll();
        });
    }

    return {
        queuedReactions: computed(() => queuedReactions.value),
        queueReaction,
        cancelReaction,
        cancelBatch,
        cancelAll,
        pauseAll,
        resumeAll,
        isPaused: computed(() => isPaused.value),
    };
}

