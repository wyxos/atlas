import { ref, computed } from 'vue';

type TimerSystemId = 'auto-dislike';

interface TimerSystem {
    id: TimerSystemId;
    freeze: () => void;
    unfreeze: () => void;
}

// Global state for timer manager (singleton pattern)
// These are shared across all instances of useTimerManager
const registeredSystems = ref<Map<TimerSystemId, TimerSystem>>(new Map());
const freezeRequestCounts = ref<Map<TimerSystemId, number>>(new Map());
const isGloballyFrozen = ref(false);

// Singleton instance flag to ensure global functions are only registered once
let globalFunctionsRegistered = false;
// Store references to freeze/unfreeze functions for global access
let globalFreezeFn: ((systemId: TimerSystemId) => void) | null = null;
let globalUnfreezeFn: ((systemId: TimerSystemId) => void) | null = null;

/**
 * Centralized timer manager that coordinates freezing/unfreezing across all timer systems.
 * When any system requests a freeze, all systems freeze.
 * When a system requests unfreeze, all systems unfreeze only if no other system is still frozen.
 */
export function useTimerManager() {
    /**
     * Register a timer system with the manager.
     * @param systemId Unique identifier for the system
     * @param freeze Function to call when system should freeze
     * @param unfreeze Function to call when system should unfreeze
     */
    function registerSystem(systemId: TimerSystemId, freeze: () => void, unfreeze: () => void): void {
        const wasAlreadyRegistered = registeredSystems.value.has(systemId);

        registeredSystems.value.set(systemId, {
            id: systemId,
            freeze,
            unfreeze,
        });
        freezeRequestCounts.value.set(systemId, 0);

        // Reset global freeze state if this system had no active freezes (test isolation)
        // This ensures that when a new instance is created in tests, it starts fresh
        // Only reset if this is a new registration (not re-registration with same functions)
        if (!wasAlreadyRegistered) {
            const hasActiveFreezes = Array.from(freezeRequestCounts.value.values()).some((count) => count > 0);
            if (!hasActiveFreezes && isGloballyFrozen.value) {
                isGloballyFrozen.value = false;
                // Unfreeze all systems since there are no active freezes
                registeredSystems.value.forEach((system) => {
                    system.unfreeze();
                });
            }
        }
    }

    /**
     * Unregister a timer system from the manager.
     * @param systemId Unique identifier for the system
     */
    function unregisterSystem(systemId: TimerSystemId): void {
        const hadFreezeRequests = (freezeRequestCounts.value.get(systemId) || 0) > 0;
        registeredSystems.value.delete(systemId);
        freezeRequestCounts.value.delete(systemId);

        // If this system had freeze requests, check if we should unfreeze all systems
        if (hadFreezeRequests) {
            const hasActiveFreezes = Array.from(freezeRequestCounts.value.values()).some((count) => count > 0);
            if (!hasActiveFreezes && isGloballyFrozen.value) {
                isGloballyFrozen.value = false;
                registeredSystems.value.forEach((system) => {
                    system.unfreeze();
                });
            }
        }
    }

    /**
     * Request freeze from a specific system.
     * This will freeze all registered systems if not already frozen.
     * @param systemId The system requesting the freeze
     */
    function freeze(systemId: TimerSystemId): void {
        const currentCount = freezeRequestCounts.value.get(systemId) || 0;
        freezeRequestCounts.value.set(systemId, currentCount + 1);

        // If this is the first freeze request from any system, freeze all systems
        if (!isGloballyFrozen.value) {
            isGloballyFrozen.value = true;
            registeredSystems.value.forEach((system) => {
                system.freeze();
            });
        }
    }

    /**
     * Request unfreeze from a specific system.
     * This will unfreeze all registered systems only if no other system is still requesting freeze.
     * @param systemId The system requesting the unfreeze
     */
    function unfreeze(systemId: TimerSystemId): void {
        const currentCount = freezeRequestCounts.value.get(systemId) || 0;
        if (currentCount > 0) {
            freezeRequestCounts.value.set(systemId, currentCount - 1);
        }

        // Check if any system still has active freeze requests
        const hasActiveFreezes = Array.from(freezeRequestCounts.value.values()).some((count) => count > 0);

        // If no system has active freeze requests, unfreeze all systems
        if (!hasActiveFreezes && isGloballyFrozen.value) {
            isGloballyFrozen.value = false;
            registeredSystems.value.forEach((system) => {
                system.unfreeze();
            });
        }
    }

    /**
     * Get the global freeze state.
     */
    const isFrozen = computed(() => isGloballyFrozen.value);

    /**
     * Get freeze request count for a specific system.
     */
    function getFreezeCount(systemId: TimerSystemId): number {
        return freezeRequestCounts.value.get(systemId) || 0;
    }

    /**
     * Reset all timer manager state (useful for test isolation).
     * This should only be used in tests.
     */
    function reset(): void {
        registeredSystems.value.clear();
        freezeRequestCounts.value.clear();
        isGloballyFrozen.value = false;
    }

    // Store references for global functions
    globalFreezeFn = freeze;
    globalUnfreezeFn = unfreeze;

    // Register global functions for backward compatibility
    // Only register once (singleton pattern)
    if (typeof window !== 'undefined' && !globalFunctionsRegistered) {
        globalFunctionsRegistered = true;
    }

    return {
        registerSystem,
        unregisterSystem,
        freeze,
        unfreeze,
        isFrozen,
        getFreezeCount,
        reset,
    };
}

