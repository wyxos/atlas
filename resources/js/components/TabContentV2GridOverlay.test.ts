import { mount } from '@vue/test-utils';
import { defineComponent, h, ref } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import TabContentV2GridOverlay from './TabContentV2GridOverlay.vue';
import type { FeedItem } from '@/composables/useTabs';

const testStub = defineComponent({
    name: 'TestStub',
    render() {
        return h('div');
    },
});

function createFeedItem(): FeedItem {
    return {
        id: 42,
        width: 500,
        height: 500,
        page: 1,
        key: '1-42',
        index: 0,
        src: 'https://example.com/image-42.jpg',
        will_auto_dislike: true,
    } as FeedItem;
}

function createProps(overrides: Partial<{ hovered: boolean; hasActiveCountdown: boolean }> = {}) {
    const freezeAll = vi.fn();
    const unfreezeAll = vi.fn();
    const hasActiveCountdown = vi.fn().mockReturnValue(overrides.hasActiveCountdown ?? true);

    return {
        active: false,
        hovered: overrides.hovered ?? false,
        index: 0,
        item: createFeedItem(),
        totalItems: 1,
        vibeItem: {
            fileId: 42,
            feedItem: createFeedItem(),
        } as any,
        containers: {
            badges: {
                getContainersForItem: vi.fn().mockReturnValue([]),
                getItemCountForContainerId: vi.fn().mockReturnValue(0),
                getVariantForContainerType: vi.fn().mockReturnValue('default'),
            },
            pillHandlers: {
                onMouseEnter: vi.fn(),
                onMouseLeave: vi.fn(),
                onClick: vi.fn(),
                onDoubleClick: vi.fn(),
                onContextMenu: vi.fn(),
                onMouseDown: vi.fn(),
                onAuxClick: vi.fn(),
                onDismiss: vi.fn(),
            },
            isBlacklistable: vi.fn().mockReturnValue(false),
        } as any,
        itemInteractions: {
            preload: {
                isItemPreloaded: vi.fn().mockReturnValue(true),
            },
            reactions: {
                hasActiveReaction: vi.fn().mockReturnValue(false),
            },
            autoDislikeQueue: {
                hasActiveCountdown,
                formatCountdown: vi.fn().mockReturnValue('00:00'),
                getCountdownRemainingTime: vi.fn().mockReturnValue(0),
                getCountdownProgress: vi.fn().mockReturnValue(0),
                freezeAll,
                unfreezeAll,
                isFrozen: ref(false),
            },
        } as any,
        promptDialog: {
            open: vi.fn(),
        } as any,
        localFileDeletion: {
            actions: {
                canDelete: vi.fn().mockReturnValue(false),
                open: vi.fn(),
            },
        } as any,
        onReaction: vi.fn(),
        freezeAll,
        unfreezeAll,
    };
}

describe('TabContentV2GridOverlay', () => {
    it('pauses all countdown queues when hovering an item with active auto-dislike countdown', async () => {
        const props = createProps({ hovered: true, hasActiveCountdown: true });
        const wrapper = mount(TabContentV2GridOverlay, {
            props,
            global: {
                stubs: {
                    Button: testStub,
                    DislikeProgressBar: testStub,
                    FileReactions: testStub,
                    Pill: testStub,
                },
            },
        });

        expect(props.freezeAll).toHaveBeenCalledTimes(1);

        await wrapper.setProps({ hovered: false });

        expect(props.unfreezeAll).toHaveBeenCalledTimes(1);
    });

    it('does not pause queues on hover when no active countdown exists for the item', () => {
        const props = createProps({ hovered: true, hasActiveCountdown: false });

        mount(TabContentV2GridOverlay, {
            props,
            global: {
                stubs: {
                    Button: testStub,
                    DislikeProgressBar: testStub,
                    FileReactions: testStub,
                    Pill: testStub,
                },
            },
        });

        expect(props.freezeAll).not.toHaveBeenCalled();
        expect(props.unfreezeAll).not.toHaveBeenCalled();
    });

    it('resumes queues on unmount when hover-paused countdowns were active', () => {
        const props = createProps({ hovered: true, hasActiveCountdown: true });
        const wrapper = mount(TabContentV2GridOverlay, {
            props,
            global: {
                stubs: {
                    Button: testStub,
                    DislikeProgressBar: testStub,
                    FileReactions: testStub,
                    Pill: testStub,
                },
            },
        });

        expect(props.freezeAll).toHaveBeenCalledTimes(1);

        wrapper.unmount();

        expect(props.unfreezeAll).toHaveBeenCalledTimes(1);
    });

});
