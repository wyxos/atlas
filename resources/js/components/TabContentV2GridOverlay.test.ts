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
            isWatchingAndRefreshing: vi.fn().mockReturnValue(false),
            refreshSourceMedia: vi.fn(),
            watchAndRefresh: vi.fn(),
            unwatchSourceAccount: vi.fn(),
        },
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

    it('uses the grid item info action to open the file sheet for that grid item', async () => {
        const props = createProps({ hovered: true });

        const wrapper = mount(TabContentV2GridOverlay, {
            props,
            global: {
                stubs: {
                    Button: buttonStub,
                    FileReactions: testStub,
                    Pill: testStub,
                },
            },
        });

        await wrapper.get('button[aria-label="Show prompt"]').trigger('click');

        expect(props.openFileSheet).toHaveBeenCalledWith(props.item, props.index);
    });

    it('shows failed preview generation state and wires retry', async () => {
        const props = createProps();
        props.item.src = '';
        props.item.preview = '';
        props.item.thumbnail = '';
        props.item.downloaded = true;
        props.item.preview_generation = {
            status: 'failed',
            can_retry: true,
            message: 'Processor exited with code 1.',
        };
        const queuePreviewRegeneration = vi.fn();

        const wrapper = mount(TabContentV2GridOverlay, {
            props: {
                ...props,
                queuePreviewRegeneration,
                isPreviewRegenerationQueued: vi.fn().mockReturnValue(false),
            },
            global: {
                stubs: {
                    Button: buttonStub,
                    FileReactions: testStub,
                    Pill: testStub,
                },
            },
        });

        expect(wrapper.get('[data-test="preview-generation-state"]').text()).toContain('Preview failed');
        expect(wrapper.text()).toContain('Processor exited with code 1.');

        await wrapper.get('[data-test="preview-regeneration-trigger"]').trigger('click');

        expect(queuePreviewRegeneration).toHaveBeenCalledWith(props.item);
    });

    it('marks non-sibling cards as dimmed while a container drawer is open', () => {
        const wrapper = mount(TabContentV2GridOverlay, {
            props: {
                ...createProps(),
                dimmed: true,
            },
            global: {
                stubs: {
                    Button: testStub,
                    FileReactions: testStub,
                    Pill: testStub,
                },
            },
        });

        expect(wrapper.attributes('data-container-drawer-dimmed')).toBe('true');
    });

    it('shows and wires the blacklist state action for blacklisted items', async () => {
        const props = createProps({ hovered: true });
        props.item.blacklisted_at = '2026-04-30T00:00:00Z';
        const fileReactionsSpy = vi.fn();

        const fileReactionsStub = defineComponent({
            name: 'FileReactionsStub',
            emits: ['blacklist'],
            props: {
                blacklistedAt: { type: String, default: null },
            },
            setup(stubProps, { emit }) {
                fileReactionsSpy(stubProps);

                return () => h('button', {
                    'data-testid': 'blacklist-trigger',
                    onClick: () => emit('blacklist'),
                });
            },
        });

        const wrapper = mount(TabContentV2GridOverlay, {
            props,
            global: {
                stubs: {
                    Button: testStub,
                    FileReactions: fileReactionsStub,
                    Pill: testStub,
                },
            },
        });

        expect(fileReactionsSpy).toHaveBeenCalledWith(expect.objectContaining({
            blacklistedAt: '2026-04-30T00:00:00Z',
        }));

        await wrapper.get('[data-testid="blacklist-trigger"]').trigger('click');

        expect(props.itemInteractions.reactions.onFileBlacklist).toHaveBeenCalledWith(props.item);
    });

    it('shows and wires the remove from tab action when provided', async () => {
        const props = createProps({ hovered: true });
        props.removeItemFromTab = vi.fn();
        props.isRemovingFromTab = vi.fn().mockReturnValue(true);
        const fileReactionsSpy = vi.fn();

        const fileReactionsStub = defineComponent({
            name: 'FileReactionsStub',
            emits: ['remove'],
            props: {
                removing: { type: Boolean, default: false },
                showRemove: { type: Boolean, default: false },
            },
            setup(stubProps, { emit }) {
                fileReactionsSpy(stubProps);

                return () => h('button', {
                    'data-testid': 'remove-from-tab-trigger',
                    onClick: () => emit('remove'),
                });
            },
        });

        const wrapper = mount(TabContentV2GridOverlay, {
            props,
            global: {
                stubs: {
                    Button: testStub,
                    FileReactions: fileReactionsStub,
                    Pill: testStub,
                },
            },
        });

        expect(fileReactionsSpy).toHaveBeenCalledWith(expect.objectContaining({
            removing: true,
            showRemove: true,
        }));

        await wrapper.get('[data-testid="remove-from-tab-trigger"]').trigger('click');

        expect(props.removeItemFromTab).toHaveBeenCalledWith(props.item);
    });

    it('shows source watch refresh action for DeviantArt items with a user container', async () => {
        const props = createProps({ hovered: true });
        props.item.source = 'deviantart.com';
        props.containers.badges.getContainersForItem.mockReturnValue([
            {
                id: 9,
                type: 'User',
                source: 'deviantart.com',
                source_id: 'exampleartist',
            },
        ]);
        props.sourceWatchRefresh.canWatchAndRefresh.mockImplementation((_item: FeedItem, username: string | null) => username === 'exampleartist');

        const wrapper = mount(TabContentV2GridOverlay, {
            props,
            global: {
                stubs: {
                    Button: buttonStub,
                    FileReactions: testStub,
                    Pill: testStub,
                },
            },
        });

        await wrapper.get('[data-test="source-watch-refresh-trigger"]').trigger('click');

        expect(props.sourceWatchRefresh.watchAndRefresh).toHaveBeenCalledWith(props.item, 'exampleartist');
    });

    it('shows source media refresh action before watch access metadata is known', async () => {
        const props = createProps({ hovered: true });
        props.itemInteractions.preload.isItemPreloaded.mockReturnValue(false);
        props.item.source = 'deviantart.com';
        props.item.source_access = null;
        props.item.capabilities = {
            refresh_source_media: true,
            watch_source_and_refresh: true,
            unwatch_source_account: true,
        };
        props.sourceWatchRefresh.canRefreshSourceMedia.mockReturnValue(true);

        const wrapper = mount(TabContentV2GridOverlay, {
            props,
            global: {
                stubs: {
                    Button: buttonStub,
                    FileReactions: testStub,
                    Pill: testStub,
                },
            },
        });

        await wrapper.get('[data-test="source-media-refresh-trigger"]').trigger('click');

        expect(props.sourceWatchRefresh.refreshSourceMedia).toHaveBeenCalledWith(props.item);
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

        const wrapper = mount(TabContentV2GridOverlay, {
            props,
            global: {
                stubs: {
                    Button: buttonStub,
                    FileReactions: testStub,
                    Pill: testStub,
                },
            },
        });

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

        const wrapper = mount(TabContentV2GridOverlay, {
            props,
            global: {
                stubs: {
                    Button: buttonStub,
                    FileReactions: testStub,
                    Pill: testStub,
                },
            },
        });

        await wrapper.get('[data-test="source-unwatch-trigger"]').trigger('click');

        expect(props.sourceWatchRefresh.unwatchSourceAccount).toHaveBeenCalledWith(props.item, 'exampleartist');
    });
});
