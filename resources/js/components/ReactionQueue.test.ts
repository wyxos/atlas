import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';

// Mock vue-toastification - must be hoisted before imports
vi.mock('vue-toastification', () => {
    const toastIds = new Map();
    let nextId = 1;

    const toastFn = vi.fn((options: any) => {
        const id = nextId++;
        toastIds.set(id, options);
        return id;
    });

    const updateFn = vi.fn((id: number | string, options: any) => {
        if (toastIds.has(id)) {
            toastIds.set(id, { ...toastIds.get(id), ...options });
        }
    });

    const dismissFn = vi.fn((id: number | string) => {
        toastIds.delete(id);
    });

    const clearFn = vi.fn(() => {
        toastIds.clear();
    });

    // useToast returns a function that can be called directly (toast({...}))
    // but also has methods like update, dismiss, clear
    const useToastReturn = Object.assign(
        toastFn,
        {
            update: updateFn,
            dismiss: dismissFn,
            clear: clearFn,
        }
    );

    return {
        useToast: vi.fn(() => useToastReturn),
        POSITION: {
            BOTTOM_RIGHT: 'bottom-right',
        },
    };
});

import { useReactionQueue } from '../composables/useReactionQueue';
import { useTimerManager } from '../composables/useTimerManager';
import SingleReactionToast from './toasts/SingleReactionToast.vue';
import BatchReactionToast from './toasts/BatchReactionToast.vue';

describe('ReactionQueue Toast Integration', () => {
    let reactionQueue: ReturnType<typeof useReactionQueue>;
    let timerManager: ReturnType<typeof useTimerManager>;

    beforeEach(() => {
        vi.useFakeTimers();
        // Reset timer manager state between tests
        timerManager = useTimerManager();
        timerManager.reset();
        // Create a single reaction queue instance for all tests
        // This will register with the timer manager automatically
        reactionQueue = useReactionQueue();
        // Clear any existing reactions
        reactionQueue.cancelAll();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.clearAllMocks();
        // Clean up any queued reactions
        reactionQueue.cancelAll();
        timerManager.reset();
    });

    it('creates a toast when a single reaction is queued', () => {
        const executeCallback = vi.fn();

        reactionQueue.queueReaction(1, 'love', executeCallback, 'preview.jpg');

        // The toast should be created (we can't easily test the actual toast creation
        // without a full Vue app setup, but we can verify the composable works)
        expect(executeCallback).not.toHaveBeenCalled();
    });

    it('creates a batch toast when multiple reactions share a batchId', () => {
        const executeCallback = vi.fn();
        const batchId = 'test-batch';

        reactionQueue.queueReaction(1, 'like', executeCallback, 'preview1.jpg', undefined, undefined, undefined, undefined, batchId);
        reactionQueue.queueReaction(2, 'like', executeCallback, 'preview2.jpg', undefined, undefined, undefined, undefined, batchId);

        // Both reactions should be queued with the same batchId
        expect(executeCallback).not.toHaveBeenCalled();
    });

    it('starts reaction in paused state when timer manager is frozen', () => {
        const executeCallback = vi.fn();

        // Freeze the timer manager (simulating modal open)
        timerManager.freeze('reaction-queue');

        // Queue a reaction while frozen
        reactionQueue.queueReaction(1, 'like', executeCallback, 'preview1.jpg');

        // Reaction should be queued but paused
        expect(reactionQueue.queuedReactions.value).toHaveLength(1);
        const queued = reactionQueue.queuedReactions.value[0];
        expect(queued.pausedAt).not.toBeNull();
        expect(queued.pausedRemaining).toBe(5000); // Full 5 seconds remaining
        expect(queued.timeoutId).toBeNull(); // No timeout started
        expect(queued.intervalId).toBeNull(); // No interval started

        // Execute callback should not have been called
        expect(executeCallback).not.toHaveBeenCalled();
    });

    it('resumes reactions created while frozen when timer manager unfreezes', async () => {
        const executeCallback = vi.fn();

        // Freeze the timer manager (simulating modal open)
        timerManager.freeze('reaction-queue');

        // Queue a reaction while frozen
        reactionQueue.queueReaction(1, 'like', executeCallback, 'preview1.jpg');

        // Verify it's paused
        expect(reactionQueue.queuedReactions.value).toHaveLength(1);
        const beforeResume = reactionQueue.queuedReactions.value[0];
        expect(beforeResume.pausedAt).not.toBeNull();
        expect(beforeResume.timeoutId).toBeNull();

        // Unfreeze the timer manager (simulating modal close)
        timerManager.unfreeze('reaction-queue');

        // Reaction should now have timers started (check immediately, don't advance time)
        expect(reactionQueue.queuedReactions.value).toHaveLength(1);
        const queued = reactionQueue.queuedReactions.value[0];
        expect(queued.pausedAt).toBeNull(); // Paused state cleared
        expect(queued.pausedRemaining).toBeNull(); // Paused remaining cleared
        expect(queued.timeoutId).not.toBeNull(); // Timeout started
        expect(queued.intervalId).not.toBeNull(); // Interval started
    });

    it('resumes multiple reactions created while frozen', async () => {
        const executeCallback = vi.fn();

        // Freeze the timer manager
        timerManager.freeze('reaction-queue');

        // Queue multiple reactions while frozen
        reactionQueue.queueReaction(1, 'like', executeCallback, 'preview1.jpg');
        reactionQueue.queueReaction(2, 'love', executeCallback, 'preview2.jpg');
        reactionQueue.queueReaction(3, 'funny', executeCallback, 'preview3.jpg');

        // All should be paused
        expect(reactionQueue.queuedReactions.value).toHaveLength(3);
        reactionQueue.queuedReactions.value.forEach((queued) => {
            expect(queued.pausedAt).not.toBeNull();
            expect(queued.timeoutId).toBeNull();
        });

        // Unfreeze
        timerManager.unfreeze('reaction-queue');

        // Wait a tick for the resume logic to complete
        await vi.runOnlyPendingTimersAsync();

        // All should now have timers started
        reactionQueue.queuedReactions.value.forEach((queued) => {
            expect(queued.pausedAt).toBeNull();
            expect(queued.timeoutId).not.toBeNull();
            expect(queued.intervalId).not.toBeNull();
        });
    });
});

describe('SingleReactionToast', () => {
    it('renders with correct props', () => {
        const onCancel = vi.fn();
        const wrapper = mount(SingleReactionToast, {
            props: {
                fileId: 1,
                type: 'love',
                previewUrl: 'preview.jpg',
                countdown: 5,
                onCancel,
            },
        });

        expect(wrapper.text()).toContain('File #1');
        expect(wrapper.find('img').exists()).toBe(true);
    });

    it('calls onCancel when cancel button is clicked', async () => {
        const onCancel = vi.fn();
        const wrapper = mount(SingleReactionToast, {
            props: {
                fileId: 1,
                type: 'love',
                countdown: 5,
                onCancel,
            },
        });

        const cancelButton = wrapper.find('button[aria-label="Cancel reaction"]');
        expect(cancelButton.exists()).toBe(true);

        await cancelButton.trigger('click');

        expect(onCancel).toHaveBeenCalledWith(1);
    });

    it('displays progress bar with correct width', () => {
        const wrapper = mount(SingleReactionToast, {
            props: {
                fileId: 1,
                type: 'love',
                countdown: 2.5, // 50% remaining = 50% progress
            },
        });

        const progressBar = wrapper.find('.bg-smart-blue-400');
        expect(progressBar.exists()).toBe(true);
        expect(progressBar.attributes('style')).toContain('width: 50%');
    });

    it('displays correct reaction icon for each type', () => {
        const types = ['love', 'like', 'dislike', 'funny'] as const;
        const colors = {
            love: 'text-red-400',
            like: 'text-smart-blue-400',
            dislike: 'text-gray-400',
            funny: 'text-yellow-400',
        };

        types.forEach((type) => {
            const wrapper = mount(SingleReactionToast, {
                props: {
                    fileId: 1,
                    type,
                    countdown: 5,
                },
            });

            expect(wrapper.html()).toContain(colors[type]);
        });
    });
});

describe('BatchReactionToast', () => {
    it('renders with correct props', () => {
        const reactions = [
            {
                id: '1-123',
                fileId: 1,
                type: 'like' as const,
                previewUrl: 'preview1.jpg',
                countdown: 5,
                timeoutId: null,
                intervalId: null,
                startTime: Date.now(),
                pausedAt: null,
                pausedRemaining: null,
                executeCallback: async () => { },
            },
            {
                id: '2-456',
                fileId: 2,
                type: 'like' as const,
                previewUrl: 'preview2.jpg',
                countdown: 5,
                timeoutId: null,
                intervalId: null,
                startTime: Date.now(),
                pausedAt: null,
                pausedRemaining: null,
                executeCallback: async () => { },
            },
        ];

        const onCancelBatch = vi.fn();
        const wrapper = mount(BatchReactionToast, {
            props: {
                batchId: 'test-batch',
                reactions,
                type: 'like',
                countdown: 5,
                onCancelBatch,
            },
        });

        expect(wrapper.text()).toContain('2 files');
        expect(wrapper.findAll('img')).toHaveLength(2);
    });

    it('shows plus icon when more than 5 reactions', () => {
        const reactions = Array.from({ length: 7 }, (_, i) => ({
            id: `${i}-123`,
            fileId: i + 1,
            type: 'like' as const,
            previewUrl: `preview${i}.jpg`,
            countdown: 5,
            timeoutId: null,
            intervalId: null,
            startTime: Date.now(),
            pausedAt: null,
            pausedRemaining: null,
            executeCallback: async () => { },
        }));

        const wrapper = mount(BatchReactionToast, {
            props: {
                batchId: 'test-batch',
                reactions,
                type: 'like',
                countdown: 5,
            },
        });

        expect(wrapper.findAll('img')).toHaveLength(5);
        // Check for the Plus icon component (lucide-vue-next renders it as an SVG with class containing "lucide-plus" or "lucide-plus-icon")
        const plusIcon = wrapper.find('.lucide-plus, .lucide-plus-icon');
        expect(plusIcon.exists()).toBe(true);
    });

    it('calls onCancelBatch when cancel button is clicked', async () => {
        const reactions = [
            {
                id: '1-123',
                fileId: 1,
                type: 'like' as const,
                countdown: 5,
                timeoutId: null,
                intervalId: null,
                startTime: Date.now(),
                pausedAt: null,
                pausedRemaining: null,
                executeCallback: async () => { },
            },
        ];

        const onCancelBatch = vi.fn();
        const wrapper = mount(BatchReactionToast, {
            props: {
                batchId: 'test-batch',
                reactions,
                type: 'like',
                countdown: 5,
                onCancelBatch,
            },
        });

        const cancelButton = wrapper.find('button[aria-label="Cancel batch reaction"]');
        expect(cancelButton.exists()).toBe(true);

        await cancelButton.trigger('click');

        expect(onCancelBatch).toHaveBeenCalledWith('test-batch');
    });
});
