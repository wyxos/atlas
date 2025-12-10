import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import ReactionQueue from './ReactionQueue.vue';
import type { QueuedReaction } from '../composables/useReactionQueue';

describe('ReactionQueue', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders nothing when no reactions are queued', () => {
        const wrapper = mount(ReactionQueue, {
            props: {
                queuedReactions: [],
            },
        });

        expect(wrapper.html()).toBe('<!--v-if-->');
    });

    it('displays queued reactions', () => {
        const queuedReactions: QueuedReaction[] = [
            {
                id: '1-123',
                fileId: 1,
                type: 'love',
                countdown: 5,
                timeoutId: null,
                intervalId: null,
            },
            {
                id: '2-456',
                fileId: 2,
                type: 'like',
                countdown: 3,
                timeoutId: null,
                intervalId: null,
            },
        ];

        const wrapper = mount(ReactionQueue, {
            props: {
                queuedReactions,
            },
        });

        expect(wrapper.text()).toContain('File #1');
        expect(wrapper.text()).toContain('File #2');
    });

    it('displays progress bar with correct width', () => {
        const queuedReactions: QueuedReaction[] = [
            {
                id: '1-123',
                fileId: 1,
                type: 'love',
                countdown: 2.5, // 50% remaining = 50% progress
                timeoutId: null,
                intervalId: null,
            },
        ];

        const wrapper = mount(ReactionQueue, {
            props: {
                queuedReactions,
            },
        });

        const progressBar = wrapper.find('.bg-smart-blue-400');
        expect(progressBar.exists()).toBe(true);
        expect(progressBar.attributes('style')).toContain('width: 50%');
    });

    it('calls onCancel when cancel button is clicked', async () => {
        const onCancel = vi.fn();
        const queuedReactions: QueuedReaction[] = [
            {
                id: '1-123',
                fileId: 1,
                type: 'love',
                countdown: 5,
                timeoutId: null,
                intervalId: null,
            },
        ];

        const wrapper = mount(ReactionQueue, {
            props: {
                queuedReactions,
                onCancel,
            },
        });

        const cancelButton = wrapper.find('button[aria-label="Cancel reaction"]');
        expect(cancelButton.exists()).toBe(true);

        await cancelButton.trigger('click');

        expect(onCancel).toHaveBeenCalledWith(1);
    });

    it('displays correct reaction icon for each type', () => {
        const queuedReactions: QueuedReaction[] = [
            {
                id: '1-123',
                fileId: 1,
                type: 'love',
                countdown: 5,
                timeoutId: null,
                intervalId: null,
            },
            {
                id: '2-456',
                fileId: 2,
                type: 'like',
                countdown: 3,
                timeoutId: null,
                intervalId: null,
            },
            {
                id: '3-789',
                fileId: 3,
                type: 'dislike',
                countdown: 2,
                timeoutId: null,
                intervalId: null,
            },
            {
                id: '4-012',
                fileId: 4,
                type: 'funny',
                countdown: 1,
                timeoutId: null,
                intervalId: null,
            },
        ];

        const wrapper = mount(ReactionQueue, {
            props: {
                queuedReactions,
            },
        });

        // All reaction icons should be present
        expect(wrapper.html()).toContain('text-red-400'); // love
        expect(wrapper.html()).toContain('text-smart-blue-400'); // like
        expect(wrapper.html()).toContain('text-gray-400'); // dislike
        expect(wrapper.html()).toContain('text-yellow-400'); // funny
    });

    it('updates progress bar as countdown decreases', async () => {
        const queuedReactions: QueuedReaction[] = [
            {
                id: '1-123',
                fileId: 1,
                type: 'love',
                countdown: 5,
                timeoutId: null,
                intervalId: null,
            },
        ];

        const wrapper = mount(ReactionQueue, {
            props: {
                queuedReactions,
            },
        });

        // Initial progress should be 0% (5 seconds remaining = 0% progress)
        let progressBar = wrapper.find('.bg-smart-blue-400');
        expect(progressBar.attributes('style')).toContain('width: 0%');

        // Update countdown to 2.5 seconds (50% remaining = 50% progress)
        await wrapper.setProps({
            queuedReactions: [{
                ...queuedReactions[0],
                countdown: 2.5,
            }],
        });

        progressBar = wrapper.find('.bg-smart-blue-400');
        expect(progressBar.attributes('style')).toContain('width: 50%');

        // Update countdown to 0 seconds (0% remaining = 100% progress)
        await wrapper.setProps({
            queuedReactions: [{
                ...queuedReactions[0],
                countdown: 0,
            }],
        });

        progressBar = wrapper.find('.bg-smart-blue-400');
        expect(progressBar.attributes('style')).toContain('width: 100%');
    });
});

