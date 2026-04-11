import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import BrowseV2 from './BrowseV2.vue';

const {
    mockLoadTabs,
    mockCreateTab,
    mockCloseTab,
    mockUpdateActiveTab,
    mockUpdateTabLabel,
    mockUpdateTabCustomLabel,
    mockSetActiveTab,
    mockUndoLatestQueuedReaction,
} = vi.hoisted(() => ({
    mockLoadTabs: vi.fn(),
    mockCreateTab: vi.fn(),
    mockCloseTab: vi.fn(),
    mockUpdateActiveTab: vi.fn(),
    mockUpdateTabLabel: vi.fn(),
    mockUpdateTabCustomLabel: vi.fn(),
    mockSetActiveTab: vi.fn(),
    mockUndoLatestQueuedReaction: vi.fn(),
}));

vi.mock('@/composables/useTabs', async () => {
    const { ref } = await import('vue');

    return {
        useTabs: () => ({
            tabs: ref([]),
            activeTabId: ref<number | null>(null),
            loadTabs: mockLoadTabs,
            createTab: mockCreateTab,
            closeTab: mockCloseTab,
            getActiveTab: () => null,
            updateActiveTab: mockUpdateActiveTab,
            updateTabLabel: mockUpdateTabLabel,
            updateTabCustomLabel: mockUpdateTabCustomLabel,
            setActiveTab: mockSetActiveTab,
        }),
    };
});

vi.mock('@/utils/reactionQueue', () => ({
    undoLatestQueuedReaction: mockUndoLatestQueuedReaction,
}));

describe('Browse undo shortcut', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockLoadTabs.mockResolvedValue(undefined);
        mockUndoLatestQueuedReaction.mockReturnValue(false);
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('uses Ctrl+Z to undo the latest queued reaction', () => {
        mockUndoLatestQueuedReaction.mockReturnValue(true);
        const wrapper = shallowMount(BrowseV2);

        const event = new KeyboardEvent('keydown', {
            key: 'z',
            ctrlKey: true,
            bubbles: true,
            cancelable: true,
        });

        window.dispatchEvent(event);

        expect(mockUndoLatestQueuedReaction).toHaveBeenCalledTimes(1);
        expect(event.defaultPrevented).toBe(true);

        wrapper.unmount();
    });

    it('leaves text inputs on their native undo behavior', () => {
        const wrapper = shallowMount(BrowseV2);
        const input = document.createElement('input');
        document.body.appendChild(input);

        const event = new KeyboardEvent('keydown', {
            key: 'z',
            ctrlKey: true,
            bubbles: true,
            cancelable: true,
        });

        input.dispatchEvent(event);

        expect(mockUndoLatestQueuedReaction).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(false);

        wrapper.unmount();
    });

    it('does not prevent default when there is nothing queued to undo', () => {
        const wrapper = shallowMount(BrowseV2);

        const event = new KeyboardEvent('keydown', {
            key: 'z',
            ctrlKey: true,
            bubbles: true,
            cancelable: true,
        });

        window.dispatchEvent(event);

        expect(mockUndoLatestQueuedReaction).toHaveBeenCalledTimes(1);
        expect(event.defaultPrevented).toBe(false);

        wrapper.unmount();
    });
});
