import { ref, h, onUnmounted, getCurrentInstance } from 'vue';
import { useToast } from 'vue-toastification';
import { useTimerManager } from './useTimerManager';
import ImmediateActionToast from '../components/toasts/ImmediateActionToast.vue';
import type { ReactionType } from '@/types/reaction';

export interface ImmediateActionItem {
    id: number;
    action_type: string;
    thumbnail?: string;
}

const TOAST_DURATION_MS = 5000; // 5 seconds
const TOAST_UPDATE_INTERVAL_MS = 50; // Update every 50ms for smooth countdown

export function useImmediateActionsToast(onReaction?: (fileId: number, type: ReactionType) => void) {
    const toast = useToast();
    const timerManager = useTimerManager();
    const systemId = 'immediate-actions-toast' as const;

    const pendingActions = ref<ImmediateActionItem[]>([]);
    const currentToastId = ref<string | number | null>(null);
    const countdown = ref(TOAST_DURATION_MS);
    const countdownInterval = ref<ReturnType<typeof setInterval> | null>(null);
    const isFrozen = ref(false);
    const isShowingToast = ref(false); // Track if toast is currently being shown

    // Internal freeze/unfreeze functions
    function internalFreeze(): void {
        isFrozen.value = true;
    }

    function internalUnfreeze(): void {
        isFrozen.value = false;
    }

    // Register with timer manager
    timerManager.registerSystem(systemId, internalFreeze, internalUnfreeze);

    /**
     * Add immediate actions to the pending queue
     */
    function addActions(actions: ImmediateActionItem[]): void {
        if (actions.length === 0) {
            return;
        }
        pendingActions.value.push(...actions);
    }

    /**
     * Show toast with pending actions and start countdown
     */
    function showToast(): void {
        if (pendingActions.value.length === 0) {
            return;
        }

        // Dismiss existing toast if any (always recreate to avoid update issues)
        if (currentToastId.value !== null) {
            toast.dismiss(currentToastId.value);
            currentToastId.value = null;
        }

        isShowingToast.value = true;

        // Reset countdown
        countdown.value = TOAST_DURATION_MS;

        // Create new toast
        const toastId = toast({
            content: h(ImmediateActionToast, {
                items: [...pendingActions.value],
                countdown: countdown.value / 1000, // Convert to seconds
                onDismiss: handleDismiss,
                onReaction,
            }),
            timeout: false, // Manual timeout control
            closeOnClick: false,
            closeButton: false,
        });

        currentToastId.value = toastId;

        // Start countdown
        startCountdown();
    }

    /**
     * Start countdown timer
     */
    function startCountdown(): void {
        // Clear existing interval
        if (countdownInterval.value !== null) {
            clearInterval(countdownInterval.value);
        }

        countdownInterval.value = setInterval(() => {
            if (!isFrozen.value) {
                countdown.value = Math.max(0, countdown.value - TOAST_UPDATE_INTERVAL_MS);

                // Update toast with new countdown (same pattern as useReactionQueue)
                if (currentToastId.value !== null && pendingActions.value.length > 0) {
                    const queued = currentToastId.value;
                    toast.update(queued, {
                        content: h(ImmediateActionToast, {
                            items: [...pendingActions.value],
                            countdown: countdown.value / 1000, // Convert to seconds
                            onDismiss: handleDismiss,
                            onReaction,
                        }),
                    });
                }

                // Auto-dismiss when countdown reaches 0
                if (countdown.value <= 0) {
                    handleDismiss();
                }
            }
        }, TOAST_UPDATE_INTERVAL_MS);
    }

    /**
     * Handle toast dismiss
     */
    function handleDismiss(): void {
        // Clear countdown interval
        if (countdownInterval.value !== null) {
            clearInterval(countdownInterval.value);
            countdownInterval.value = null;
        }

        // Dismiss toast
        if (currentToastId.value !== null) {
            toast.dismiss(currentToastId.value);
            currentToastId.value = null;
        }

        // Clear pending actions and reset state
        pendingActions.value = [];
        countdown.value = TOAST_DURATION_MS;
        isShowingToast.value = false;
    }

    /**
     * Clear all pending actions without showing toast
     */
    function clear(): void {
        handleDismiss();
    }

    // Cleanup on unmount (only if called within a component context)
    const instance = getCurrentInstance();
    if (instance) {
        onUnmounted(() => {
            handleDismiss();
            timerManager.unregisterSystem(systemId);
        });
    }

    /**
     * Check if there are pending actions
     */
    function hasPendingActions(): boolean {
        return pendingActions.value.length > 0;
    }

    return {
        addActions,
        showToast,
        clear,
        hasPendingActions,
    };
}

