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

const buttonStub = defineComponent({
    name: 'ButtonStub',
    inheritAttrs: false,
    setup(_, { attrs, slots }) {
        return () => h('button', attrs, slots.default?.());
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
                hasBlacklistState: vi.fn((candidate: FeedItem) => Boolean(candidate.blacklisted_at)),
                onFileBlacklist: vi.fn(),
            },
        } as any,
        localFileDeletion: {
            actions: {
                canDelete: vi.fn().mockReturnValue(false),
                open: vi.fn(),
            },
        } as any,
        isRemovingFromTab: undefined as ((item: FeedItem) => boolean) | undefined,
        openFileSheet: vi.fn(),
        removeItemFromTab: undefined as ((item: FeedItem) => void) | undefined,
        sourceWatchRefresh: {
            canRefreshSourceMedia: vi.fn().mockReturnValue(false),
            canWatchAndRefresh: vi.fn().mockReturnValue(false),
            canUnwatchSourceAccount: vi.fn().mockReturnValue(false),
            pendingOperationFor: vi.fn().mockReturnValue(null),
            refreshSourceMedia: vi.fn(),
            watchAndRefresh: vi.fn(),
            unwatchSourceAccount: vi.fn(),
        },
        onReaction: vi.fn(),
    };
}

function mountOverlay(props: ReturnType<typeof createProps>) {
    return mount(TabContentV2GridOverlay, {
        props,
        global: {
            stubs: {
                Button: buttonStub,
                FileReactions: testStub,
                Pill: testStub,
            },
        },
    });
}

describe('TabContentV2GridOverlay source operation progress', () => {
    it('keeps refresh static while the watch control owns watch progress', () => {
        const props = createProps({ hovered: true });
        props.item.source = 'deviantart.com';
        props.containers.badges.getContainersForItem.mockReturnValue([
            {
                id: 9,
                type: 'User',
                source: 'deviantart.com',
                source_id: 'watch-progress-artist',
            },
        ]);
        props.sourceWatchRefresh.canRefreshSourceMedia.mockReturnValue(true);
        props.sourceWatchRefresh.canWatchAndRefresh.mockReturnValue(true);
        props.sourceWatchRefresh.pendingOperationFor.mockReturnValue('watch');

        const wrapper = mountOverlay(props);
        const refreshButton = wrapper.get('[data-test="source-media-refresh-trigger"]');
        const watchButton = wrapper.get('[data-test="source-watch-refresh-trigger"]');

        expect(refreshButton.attributes('disabled')).toBeDefined();
        expect(refreshButton.attributes('aria-label')).toBe('Source account watch in progress');
        expect(refreshButton.attributes('aria-busy')).toBeUndefined();
        expect(refreshButton.find('.animate-spin').exists()).toBe(false);
        expect(watchButton.attributes('aria-label')).toBe('Watching source account and refreshing media');
        expect(watchButton.attributes('aria-busy')).toBe('true');
        expect(watchButton.find('.animate-spin').exists()).toBe(true);
    });

    it('keeps refresh static while the unwatch control owns unwatch progress', () => {
        const props = createProps({ hovered: true });
        props.item.source = 'deviantart.com';
        props.containers.badges.getContainersForItem.mockReturnValue([
            {
                id: 9,
                type: 'User',
                source: 'deviantart.com',
                source_id: 'unwatch-progress-artist',
            },
        ]);
        props.sourceWatchRefresh.canRefreshSourceMedia.mockReturnValue(true);
        props.sourceWatchRefresh.canUnwatchSourceAccount.mockReturnValue(true);
        props.sourceWatchRefresh.pendingOperationFor.mockReturnValue('unwatch');

        const wrapper = mountOverlay(props);
        const refreshButton = wrapper.get('[data-test="source-media-refresh-trigger"]');
        const unwatchButton = wrapper.get('[data-test="source-unwatch-trigger"]');

        expect(refreshButton.attributes('disabled')).toBeDefined();
        expect(refreshButton.attributes('aria-label')).toBe('Source account unwatch in progress');
        expect(refreshButton.attributes('aria-busy')).toBeUndefined();
        expect(refreshButton.find('.animate-spin').exists()).toBe(false);
        expect(unwatchButton.attributes('aria-label')).toBe('Unwatching source account');
        expect(unwatchButton.attributes('aria-busy')).toBe('true');
        expect(unwatchButton.find('.animate-spin').exists()).toBe(true);
    });

    it('shows source watch refresh action even when the locked asset did not preload', () => {
        const props = createProps({ hovered: true });
        props.itemInteractions.preload.isItemPreloaded.mockReturnValue(false);
        props.containers.badges.getContainersForItem.mockReturnValue([
            {
                id: 9,
                type: 'User',
                source: 'deviantart.com',
                source_id: 'exampleartist',
            },
        ]);
        props.sourceWatchRefresh.canWatchAndRefresh.mockImplementation((_item: FeedItem, username: string | null) => username === 'exampleartist');

        const wrapper = mountOverlay(props);

        expect(wrapper.find('[data-test="source-watch-refresh-trigger"]').exists()).toBe(true);
    });

    it('shows and wires source unwatch action for watched DeviantArt access items', async () => {
        const props = createProps({ hovered: true });
        props.containers.badges.getContainersForItem.mockReturnValue([
            {
                id: 9,
                type: 'User',
                source: 'deviantart.com',
                source_id: 'exampleartist',
            },
        ]);
        props.sourceWatchRefresh.canUnwatchSourceAccount.mockImplementation((_item: FeedItem, username: string | null) => username === 'exampleartist');

        const wrapper = mountOverlay(props);

        await wrapper.get('[data-test="source-unwatch-trigger"]').trigger('click');

        expect(props.sourceWatchRefresh.unwatchSourceAccount).toHaveBeenCalledWith(props.item, 'exampleartist');
    });
});
