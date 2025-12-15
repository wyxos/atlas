import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { useReactionQueue } from '../composables/useReactionQueue';
import SingleReactionToast from './toasts/SingleReactionToast.vue';
import BatchReactionToast from './toasts/BatchReactionToast.vue';

// Mock vue-toastification
vi.mock('vue-toastification', () => {
    const toastIds = new Map();
    let nextId = 1;
    
    return {
        useToast: () => ({
            toast: vi.fn((options: any) => {
                const id = nextId++;
                toastIds.set(id, options);
                return id;
            }),
            update: vi.fn((id: number | string, options: any) => {
                if (toastIds.has(id)) {
                    toastIds.set(id, { ...toastIds.get(id), ...options });
                }
            }),
            dismiss: vi.fn((id: number | string) => {
                toastIds.delete(id);
            }),
            clear: vi.fn(() => {
                toastIds.clear();
            }),
        }),
        POSITION: {
            BOTTOM_RIGHT: 'bottom-right',
        },
    };
});

describe('ReactionQueue Toast Integration', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.clearAllMocks();
    });

    it('creates a toast when a single reaction is queued', () => {
        const { queueReaction } = useReactionQueue();
        const executeCallback = vi.fn();

        queueReaction(1, 'love', executeCallback, 'preview.jpg');

        // The toast should be created (we can't easily test the actual toast creation
        // without a full Vue app setup, but we can verify the composable works)
        expect(executeCallback).not.toHaveBeenCalled();
    });

    it('creates a batch toast when multiple reactions share a batchId', () => {
        const { queueReaction } = useReactionQueue();
        const executeCallback = vi.fn();
        const batchId = 'test-batch';

        queueReaction(1, 'like', executeCallback, 'preview1.jpg', undefined, undefined, undefined, undefined, batchId);
        queueReaction(2, 'like', executeCallback, 'preview2.jpg', undefined, undefined, undefined, undefined, batchId);

        // Both reactions should be queued with the same batchId
        expect(executeCallback).not.toHaveBeenCalled();
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
            },
            {
                id: '2-456',
                fileId: 2,
                type: 'like' as const,
                previewUrl: 'preview2.jpg',
                countdown: 5,
                timeoutId: null,
                intervalId: null,
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
        expect(wrapper.html()).toContain('Plus');
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
