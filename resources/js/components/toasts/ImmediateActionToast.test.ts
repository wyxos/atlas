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

// Mock FileReactions component
vi.mock('../FileReactions.vue', () => ({
    default: {
        name: 'FileReactions',
        template: '<div class="file-reactions-mock" data-file-id="[fileId]"><button @click="$emit(\'reaction\', \'like\')" class="reaction-button">Like</button></div>',
        props: ['fileId', 'hideDislike', 'variant'],
        emits: ['reaction'],
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

        // Check that FileReactions components are rendered (one per item)
        const fileReactions = wrapper.findAllComponents({ name: 'FileReactions' });
        expect(fileReactions.length).toBe(3);
        
        // Check that grid layout is present
        const modalContent = wrapper.find('.dialog-content-mock');
        const grid = modalContent.find('.grid');
        expect(grid.exists()).toBe(true);
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

    it('displays items in grid layout when modal is open', async () => {
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
        const grid = modalContent.find('.grid');
        expect(grid.exists()).toBe(true);
        expect(grid.classes()).toContain('grid-cols-2');
        expect(grid.classes()).toContain('sm:grid-cols-3');
    });

    it('displays FileReactions component for each item', async () => {
        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: mockItems,
                countdown: 5,
            },
        });

        const reviewButton = wrapper.find('button[aria-label="Review actions"]');
        await reviewButton.trigger('click');

        await wrapper.vm.$nextTick();

        const fileReactions = wrapper.findAllComponents({ name: 'FileReactions' });
        expect(fileReactions.length).toBe(3);
    });

    it('passes hideDislike prop to FileReactions', async () => {
        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: mockItems,
                countdown: 5,
            },
        });

        const reviewButton = wrapper.find('button[aria-label="Review actions"]');
        await reviewButton.trigger('click');

        await wrapper.vm.$nextTick();

        const fileReactions = wrapper.findAllComponents({ name: 'FileReactions' });
        fileReactions.forEach((reactions) => {
            expect(reactions.props('hideDislike')).toBe(true);
        });
    });

    it('removes item from modal when reacted to', async () => {
        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: mockItems,
                countdown: 5,
            },
        });

        const reviewButton = wrapper.find('button[aria-label="Review actions"]');
        await reviewButton.trigger('click');

        await wrapper.vm.$nextTick();

        // Initially should have 3 items
        let fileReactions = wrapper.findAllComponents({ name: 'FileReactions' });
        expect(fileReactions.length).toBe(3);

        // React to first item
        const firstReactions = fileReactions[0];
        await firstReactions.vm.$emit('reaction', 'like');

        await wrapper.vm.$nextTick();

        // Should now have 2 items
        fileReactions = wrapper.findAllComponents({ name: 'FileReactions' });
        expect(fileReactions.length).toBe(2);
    });

    it('calls onReaction handler when item is reacted to', async () => {
        const mockOnReaction = vi.fn();
        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: mockItems,
                countdown: 5,
                onReaction: mockOnReaction,
            },
        });

        const reviewButton = wrapper.find('button[aria-label="Review actions"]');
        await reviewButton.trigger('click');

        await wrapper.vm.$nextTick();

        const fileReactions = wrapper.findAllComponents({ name: 'FileReactions' });
        await fileReactions[0].vm.$emit('reaction', 'like');

        await wrapper.vm.$nextTick();

        expect(mockOnReaction).toHaveBeenCalledWith(1, 'like');
    });

    it('displays empty state when all items are reviewed', async () => {
        const singleItem = [{ id: 1, action_type: 'auto_dislike', thumbnail: 'thumb1.jpg' }];
        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: singleItem,
                countdown: 5,
            },
        });

        const reviewButton = wrapper.find('button[aria-label="Review actions"]');
        await reviewButton.trigger('click');

        await wrapper.vm.$nextTick();

        // React to the only item
        const fileReactions = wrapper.findAllComponents({ name: 'FileReactions' });
        await fileReactions[0].vm.$emit('reaction', 'like');

        await wrapper.vm.$nextTick();

        // Should show empty state
        const modalContent = wrapper.find('.dialog-content-mock');
        expect(modalContent.text()).toContain('All files have been reviewed.');
    });

    it('initializes modalItems when modal opens', async () => {
        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: mockItems,
                countdown: 5,
            },
        });

        const reviewButton = wrapper.find('button[aria-label="Review actions"]');
        await reviewButton.trigger('click');

        await wrapper.vm.$nextTick();

        // Modal should have all items
        const fileReactions = wrapper.findAllComponents({ name: 'FileReactions' });
        expect(fileReactions.length).toBe(3);
    });

    it('updates modal item count in description when items are removed', async () => {
        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: mockItems,
                countdown: 5,
            },
        });

        const reviewButton = wrapper.find('button[aria-label="Review actions"]');
        await reviewButton.trigger('click');

        await wrapper.vm.$nextTick();

        // Initially shows 3 files
        let description = wrapper.find('.dialog-description-mock');
        expect(description.text()).toContain('3 files');

        // React to one item
        const fileReactions = wrapper.findAllComponents({ name: 'FileReactions' });
        await fileReactions[0].vm.$emit('reaction', 'like');

        await wrapper.vm.$nextTick();

        // Should now show 2 files
        description = wrapper.find('.dialog-description-mock');
        expect(description.text()).toContain('2 files');
    });

    it('excludes reacted files when modal is reopened', async () => {
        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: mockItems,
                countdown: 5,
            },
        });

        const reviewButton = wrapper.find('button[aria-label="Review actions"]');
        await reviewButton.trigger('click');

        await wrapper.vm.$nextTick();

        // Initially should have 3 items
        let fileReactions = wrapper.findAllComponents({ name: 'FileReactions' });
        expect(fileReactions.length).toBe(3);

        // React to first item
        await fileReactions[0].vm.$emit('reaction', 'like');
        await wrapper.vm.$nextTick();

        // Should now have 2 items
        fileReactions = wrapper.findAllComponents({ name: 'FileReactions' });
        expect(fileReactions.length).toBe(2);

        // Close modal
        const dialog = wrapper.findComponent({ name: 'Dialog' });
        await dialog.vm.$emit('update:open', false);
        await wrapper.vm.$nextTick();

        // Reopen modal
        await reviewButton.trigger('click');
        await wrapper.vm.$nextTick();

        // Should still have only 2 items (reacted file should not reappear)
        fileReactions = wrapper.findAllComponents({ name: 'FileReactions' });
        expect(fileReactions.length).toBe(2);
    });

    it('reuses onReaction handler when items are reacted to', async () => {
        const mockOnReaction = vi.fn();
        const wrapper = mount(ImmediateActionToast, {
            props: {
                items: mockItems,
                countdown: 5,
                onReaction: mockOnReaction,
            },
        });

        const reviewButton = wrapper.find('button[aria-label="Review actions"]');
        await reviewButton.trigger('click');

        await wrapper.vm.$nextTick();

        // React to multiple items
        const fileReactions = wrapper.findAllComponents({ name: 'FileReactions' });
        await fileReactions[0].vm.$emit('reaction', 'like');
        await wrapper.vm.$nextTick();

        await fileReactions[1].vm.$emit('reaction', 'love');
        await wrapper.vm.$nextTick();

        // Verify onReaction was called for both
        expect(mockOnReaction).toHaveBeenCalledTimes(2);
        expect(mockOnReaction).toHaveBeenCalledWith(1, 'like');
        expect(mockOnReaction).toHaveBeenCalledWith(2, 'love');
    });
});

