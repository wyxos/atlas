import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
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
    } as FeedItem;
}

function createProps(overrides: Partial<{ hovered: boolean }> = {}) {
    const item = createFeedItem();

    return {
        active: false,
        hovered: overrides.hovered ?? false,
        index: 0,
        item,
        totalItems: 1,
        vibeItem: {
            fileId: 42,
            feedItem: item,
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
    };
}

describe('TabContentV2GridOverlay', () => {
it('renders hover actions without preview-side dependencies', () => {
        const props = createProps({ hovered: true });

        const wrapper = mount(TabContentV2GridOverlay, {
            props,
            global: {
                stubs: {
                    Button: testStub,
                    FileReactions: testStub,
                    Pill: testStub,
                },
            },
        });

        expect(wrapper.exists()).toBe(true);
    });
});
