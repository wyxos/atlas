import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { useTimerManager } from './useTimerManager';

describe('useTimerManager', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    describe('registration', () => {
        it('registers a system with freeze/unfreeze functions', () => {
            const manager = useTimerManager();
            const freezeFn = vi.fn();
            const unfreezeFn = vi.fn();

            manager.registerSystem('auto-dislike', freezeFn, unfreezeFn);

            expect(manager.getFreezeCount('auto-dislike')).toBe(0);
        });

        it('unregisters a system', () => {
            const manager = useTimerManager();
            const freezeFn = vi.fn();
            const unfreezeFn = vi.fn();

            manager.registerSystem('auto-dislike', freezeFn, unfreezeFn);
            manager.unregisterSystem('auto-dislike');

            // System should be unregistered
            expect(manager.getFreezeCount('auto-dislike')).toBe(0);
        });
    });

    describe('freeze/unfreeze coordination', () => {
        it('freezes all systems when any system requests freeze', () => {
            const manager = useTimerManager();
            const autoDislikeFreeze = vi.fn();
            const autoDislikeUnfreeze = vi.fn();

            manager.registerSystem('auto-dislike', autoDislikeFreeze, autoDislikeUnfreeze);

            // Freeze from auto-dislike system
            manager.freeze('auto-dislike');

            // System should be frozen
            expect(autoDislikeFreeze).toHaveBeenCalledTimes(1);
            expect(manager.isFrozen.value).toBe(true);
            expect(manager.getFreezeCount('auto-dislike')).toBe(1);
        });

        it('unfreezes all systems only when all systems have no active freezes', () => {
            const manager = useTimerManager();
            const autoDislikeFreeze = vi.fn();
            const autoDislikeUnfreeze = vi.fn();

            manager.registerSystem('auto-dislike', autoDislikeFreeze, autoDislikeUnfreeze);

            // Freeze from auto-dislike (multiple times)
            manager.freeze('auto-dislike');
            manager.freeze('auto-dislike');

            expect(manager.isFrozen.value).toBe(true);
            expect(manager.getFreezeCount('auto-dislike')).toBe(2);

            // Unfreeze once
            manager.unfreeze('auto-dislike');

            // Should not unfreeze because count is still > 0
            expect(autoDislikeUnfreeze).not.toHaveBeenCalled();
            expect(manager.isFrozen.value).toBe(true);
            expect(manager.getFreezeCount('auto-dislike')).toBe(1);

            // Unfreeze again (now count is 0)
            manager.unfreeze('auto-dislike');

            // Now should be unfrozen
            expect(autoDislikeUnfreeze).toHaveBeenCalledTimes(1);
            expect(manager.isFrozen.value).toBe(false);
            expect(manager.getFreezeCount('auto-dislike')).toBe(0);
        });

        it('handles multiple freeze requests from the same system', () => {
            const manager = useTimerManager();
            const autoDislikeFreeze = vi.fn();
            const autoDislikeUnfreeze = vi.fn();

            manager.registerSystem('auto-dislike', autoDislikeFreeze, autoDislikeUnfreeze);

            // Freeze twice from the same system
            manager.freeze('auto-dislike');
            manager.freeze('auto-dislike');

            expect(manager.getFreezeCount('auto-dislike')).toBe(2);
            expect(autoDislikeFreeze).toHaveBeenCalledTimes(1); // Only called once (already frozen)

            // Unfreeze once
            manager.unfreeze('auto-dislike');

            // Should not unfreeze because count is still 1
            expect(autoDislikeUnfreeze).not.toHaveBeenCalled();
            expect(manager.isFrozen.value).toBe(true);
            expect(manager.getFreezeCount('auto-dislike')).toBe(1);

            // Unfreeze again
            manager.unfreeze('auto-dislike');

            // Now should unfreeze
            expect(autoDislikeUnfreeze).toHaveBeenCalledTimes(1);
            expect(manager.isFrozen.value).toBe(false);
            expect(manager.getFreezeCount('auto-dislike')).toBe(0);
        });

        it('resets freeze state when registering a system with no active freezes', () => {
            const manager = useTimerManager();
            const freeze1 = vi.fn();
            const unfreeze1 = vi.fn();
            const freeze2 = vi.fn();
            const unfreeze2 = vi.fn();

            // Register and freeze first system
            manager.registerSystem('auto-dislike', freeze1, unfreeze1);
            manager.freeze('auto-dislike');
            expect(manager.isFrozen.value).toBe(true);

            // Unregister
            manager.unregisterSystem('auto-dislike');

            // Register new system (should reset global state if no active freezes)
            manager.registerSystem('auto-dislike', freeze2, unfreeze2);

            // Global state should be reset
            expect(manager.isFrozen.value).toBe(false);
            expect(manager.getFreezeCount('auto-dislike')).toBe(0);
        });
    });

});




