import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import ImmediateActionToast from './ImmediateActionToast.vue';

// Mock Dialog components
vi.mock('../ui/dialog', () => ({
    Dialog: {
        name: 'Dialog',
        template: '<div class="dialog-mock"><slot></slot></div>',
        props: ['open', 'modelValue'],
        emits: ['update:open', 'update:modelValue'],
    },
    DialogContent: {
        name: 'DialogContent',
        template: '<div class="dialog-content-mock"><slot></slot></div>',
    },
    DialogDescription: {
        name: 'DialogDescription',
        template: '<div class="dialog-description-mock"><slot></slot></div>',
    },
    DialogHeader: {
        name: 'DialogHeader',
        template: '<div class="dialog-header-mock"><slot></slot></div>',
    },
    DialogTitle: {
        name: 'DialogTitle',
        template: '<div class="dialog-title-mock"><slot></slot></div>',
    },
}));

describe('ImmediateActionToast', () => {
    const mockItems = [
        { id: 1, action_type: 'auto_dislike', thumbnail: 'https://example.com/thumb1.jpg' },
        { id: 2, action_type: 'blacklist', thumbnail: 'https://example.com/thumb2.jpg' },
        { id: 3, action_type: 'auto_dislike', thumbnail: 'https://example.com/thumb3.jpg' },
    ];

    let mockTimerManagerFreeze: ReturnType<typeof vi.fn>;
    let mockTimerManagerUnfreeze: ReturnType<typeof vi.fn>;
    let mockReactionQueuePauseAll: ReturnType<typeof vi.fn>;
    let mockReactionQueueResumeAll: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        vi.clearAllMocks();

        // Mock timer manager functions
        mockTimerManagerFreeze = vi.fn();
        mockTimerManagerUnfreeze = vi.fn();
        mockReactionQueuePauseAll = vi.fn();
        mockReactionQueueResumeAll = vi.fn();

        const win = window as any;
        win.__timerManagerFreeze = mockTimerManagerFreeze;
        win.__timerManagerUnfreeze = mockTimerManagerUnfreeze;
        win.__reactionQueuePauseAll = mockReactionQueuePauseAll;
        win.__reactionQueueResumeAll = mockReactionQueueResumeAll;
    });

    it('renders the toast with items and countdown', () => {
        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: mockItems,
                countdown: 3.5,
            },
        });

        expect(wrapper.text()).toContain('3 files');
        expect(wrapper.find('button[aria-label="Review actions"]').exists()).toBe(true);
    });

    it('displays review button instead of dismiss button', () => {
        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: mockItems,
                countdown: 5,
            },
        });

        const reviewButton = wrapper.find('button[aria-label="Review actions"]');
        expect(reviewButton.exists()).toBe(true);
        expect(wrapper.find('button[aria-label="Dismiss toast"]').exists()).toBe(false);
    });

    it('opens modal when review button is clicked', async () => {
        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: mockItems,
                countdown: 5,
            },
        });

        const reviewButton = wrapper.find('button[aria-label="Review actions"]');
        await reviewButton.trigger('click');

        // Modal should be open (check for dialog content)
        expect(wrapper.find('.dialog-content-mock').exists()).toBe(true);
    });

    it('freezes timer when review button is clicked', async () => {
        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: mockItems,
                countdown: 5,
            },
        });

        const reviewButton = wrapper.find('button[aria-label="Review actions"]');
        await reviewButton.trigger('click');

        expect(mockTimerManagerFreeze).toHaveBeenCalledTimes(1);
    });

    it('falls back to reaction queue pause when timer manager freeze is not available', async () => {
        const win = window as any;
        delete win.__timerManagerFreeze;
        delete win.__timerManagerUnfreeze;

        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: mockItems,
                countdown: 5,
            },
        });

        const reviewButton = wrapper.find('button[aria-label="Review actions"]');
        await reviewButton.trigger('click');

        expect(mockReactionQueuePauseAll).toHaveBeenCalledTimes(1);
    });

    it('displays all items in the modal', async () => {
        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: mockItems,
                countdown: 5,
            },
        });

        const reviewButton = wrapper.find('button[aria-label="Review actions"]');
        await reviewButton.trigger('click');

        await wrapper.vm.$nextTick();

        const modalContent = wrapper.find('.dialog-content-mock');
        expect(modalContent.text()).toContain('File #1');
        expect(modalContent.text()).toContain('File #2');
        expect(modalContent.text()).toContain('File #3');
    });

    it('displays correct action labels in modal', async () => {
        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: mockItems,
                countdown: 5,
            },
        });

        const reviewButton = wrapper.find('button[aria-label="Review actions"]');
        await reviewButton.trigger('click');

        await wrapper.vm.$nextTick();

        const modalContent = wrapper.find('.dialog-content-mock');
        expect(modalContent.text()).toContain('Auto-disliked');
        expect(modalContent.text()).toContain('Blacklisted');
    });

    it('unfreezes timer when modal is closed', async () => {
        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: mockItems,
                countdown: 5,
            },
        });

        const reviewButton = wrapper.find('button[aria-label="Review actions"]');
        await reviewButton.trigger('click');

        // Modal should be open
        expect(wrapper.find('.dialog-content-mock').exists()).toBe(true);

        // Close modal by emitting update:open event
        const dialog = wrapper.findComponent({ name: 'Dialog' });
        await dialog.vm.$emit('update:open', false);

        await wrapper.vm.$nextTick();

        expect(mockTimerManagerUnfreeze).toHaveBeenCalledTimes(1);
    });

    it('falls back to reaction queue resume when timer manager unfreeze is not available', async () => {
        const win = window as any;
        delete win.__timerManagerFreeze;
        delete win.__timerManagerUnfreeze;

        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: mockItems,
                countdown: 5,
            },
        });

        const reviewButton = wrapper.find('button[aria-label="Review actions"]');
        await reviewButton.trigger('click');

        // Close modal
        const dialog = wrapper.findComponent({ name: 'Dialog' });
        await dialog.vm.$emit('update:open', false);

        await wrapper.vm.$nextTick();

        expect(mockReactionQueueResumeAll).toHaveBeenCalledTimes(1);
    });

    it('displays correct action label for mixed actions', () => {
        const mixedItems = [
            { id: 1, action_type: 'auto_dislike', thumbnail: 'thumb1.jpg' },
            { id: 2, action_type: 'blacklist', thumbnail: 'thumb2.jpg' },
        ];

        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: mixedItems,
                countdown: 5,
            },
        });

        expect(wrapper.text()).toContain('auto-disliked and blacklisted');
    });

    it('displays correct action label for only auto_dislike', () => {
        const autoDislikeItems = [
            { id: 1, action_type: 'auto_dislike', thumbnail: 'thumb1.jpg' },
        ];

        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: autoDislikeItems,
                countdown: 5,
            },
        });

        expect(wrapper.text()).toContain('auto-disliked');
    });

    it('displays correct action label for only blacklist', () => {
        const blacklistItems = [
            { id: 1, action_type: 'blacklist', thumbnail: 'thumb1.jpg' },
        ];

        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: blacklistItems,
                countdown: 5,
            },
        });

        expect(wrapper.text()).toContain('blacklisted');
    });

    it('shows progress bar with correct width based on countdown', () => {
        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: mockItems,
                countdown: 2.5, // 50% of 5 seconds
            },
        });

        const progressBar = wrapper.find('.bg-smart-blue-400');
        expect(progressBar.exists()).toBe(true);
        const style = progressBar.attributes('style');
        expect(style).toContain('width: 50%');
    });

    it('handles hover events to pause/resume timer', async () => {
        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: mockItems,
                countdown: 5,
            },
        });

        const toastContainer = wrapper.find('.bg-prussian-blue-800');
        await toastContainer.trigger('mouseenter');

        expect(mockTimerManagerFreeze).toHaveBeenCalledTimes(1);

        await toastContainer.trigger('mouseleave');

        expect(mockTimerManagerUnfreeze).toHaveBeenCalledTimes(1);
    });
});

