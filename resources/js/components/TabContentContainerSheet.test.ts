import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TabContentContainerSheet from './TabContentContainerSheet.vue';
import type { FeedItem } from '@/composables/useTabs';

const { mockVibeMount, mockVibeRemove } = vi.hoisted(() => ({
    mockVibeMount: vi.fn(),
    mockVibeRemove: vi.fn((ids: string | string[]) => ({
        ids: Array.isArray(ids) ? ids : [ids],
    })),
}));

vi.mock('@wyxos/vibe', () => ({
    VibeLayout: {
        name: 'VibeLayout',
        props: ['initialState'],
        mounted() {
            mockVibeMount();
        },
        methods: {
            getItemByOccurrenceKey(occurrenceKey: string) {
                const match = /^sheet-item-(\d+)$/.exec(occurrenceKey);
                const index = match ? Number(match[1]) : -1;

                return this.initialState.items[index] ?? null;
            },
            remove: mockVibeRemove,
        },
        template: `
            <div data-test="sheet-vibe">
                <article
                    v-for="(item, index) in initialState.items"
                    :key="item.id"
                    :data-test="'sheet-vibe-item-' + index"
                    :data-occurrence-key="'sheet-item-' + index"
                >
                    <div :data-test="'sheet-vibe-item-body-' + index">Card body</div>
                    <slot
                        name="grid-item-overlay"
                        :item="item"
                        :hovered="true"
                        :active="false"
                        :index="index"
                    />
                </article>
            </div>
        `,
    },
}));

function createItem(id: number): FeedItem {
    return {
        id,
        width: 500,
        height: 500,
        page: 1,
        key: `1-${id}`,
        index: id - 1,
        src: `https://example.com/preview${id}.jpg`,
        preview: `https://example.com/preview${id}.jpg`,
        type: 'image',
    } as FeedItem;
}

function createItemInteractions() {
    return {
        reactions: {
            hasActiveReaction: vi.fn(() => false),
            hasBlacklistState: vi.fn(() => false),
            onFileReaction: vi.fn(),
            onFileBlacklist: vi.fn().mockResolvedValue(1),
        },
    };
}

describe('TabContentContainerSheet', () => {
    beforeEach(() => {
        mockVibeMount.mockClear();
        mockVibeRemove.mockClear();
    });

    it('renders a tab-covering Vibe sheet for related items', () => {
        const wrapper = mount(TabContentContainerSheet, {
            props: {
                open: true,
                container: { id: 10, type: 'gallery' },
                items: [createItem(1), createItem(2)],
                itemInteractions: createItemInteractions() as never,
            },
            global: {
                stubs: {
                    FileReactions: {
                        name: 'FileReactions',
                        template: '<div data-test="file-reactions" />',
                    },
                },
            },
        });

        expect(wrapper.get('[data-test="container-related-items-sheet-title"]').text()).toBe('gallery');
        expect(wrapper.get('[data-test="container-related-items-sheet-count"]').text()).toBe('2 items');
        expect(wrapper.findAll('[data-test="file-reactions"]')).toHaveLength(2);

        wrapper.unmount();
    });

    it('closes from the header button', async () => {
        const wrapper = mount(TabContentContainerSheet, {
            props: {
                open: true,
                container: { id: 10, type: 'gallery' },
                items: [createItem(1), createItem(2)],
                itemInteractions: createItemInteractions() as never,
            },
            global: {
                stubs: {
                    FileReactions: {
                        name: 'FileReactions',
                        template: '<div />',
                    },
                },
            },
        });

        await wrapper.get('[data-test="container-related-items-sheet-close"]').trigger('click');

        expect(wrapper.emitted('close')).toEqual([[]]);

        wrapper.unmount();
    });

    it('routes reactions through the shared item interactions', async () => {
        const itemInteractions = createItemInteractions();
        const wrapper = mount(TabContentContainerSheet, {
            props: {
                open: true,
                container: { id: 10, type: 'gallery' },
                items: [createItem(1), createItem(2)],
                itemInteractions: itemInteractions as never,
            },
            global: {
                stubs: {
                    FileReactions: {
                        name: 'FileReactions',
                        emits: ['reaction'],
                        template: '<button data-test="react" type="button" @click="$emit(\'reaction\', \'like\')" />',
                    },
                },
            },
        });

        await wrapper.get('[data-test="react"]').trigger('click');

        expect(itemInteractions.reactions.onFileReaction).toHaveBeenCalledWith(
            expect.objectContaining({ id: 1 }),
            'like',
        );
        expect(mockVibeRemove).toHaveBeenCalledWith('1');

        wrapper.unmount();
    });

    it('keeps the same sheet Vibe instance when parent items change after reaction', async () => {
        const itemInteractions = createItemInteractions();
        const items = [createItem(1), createItem(2), createItem(3)];
        const wrapper = mount(TabContentContainerSheet, {
            props: {
                open: true,
                container: { id: 10, type: 'gallery' },
                items,
                itemInteractions: itemInteractions as never,
            },
            global: {
                stubs: {
                    FileReactions: {
                        name: 'FileReactions',
                        emits: ['reaction'],
                        template: '<button data-test="react" type="button" @click="$emit(\'reaction\', \'like\')" />',
                    },
                },
            },
        });

        expect(mockVibeMount).toHaveBeenCalledTimes(1);

        await wrapper.get('[data-test="react"]').trigger('click');
        await wrapper.setProps({ items: [items[1], items[2]] });

        expect(mockVibeRemove).toHaveBeenCalledWith('1');
        expect(mockVibeMount).toHaveBeenCalledTimes(1);
        expect(wrapper.get('[data-test="container-related-items-sheet-count"]').text()).toBe('2 items');

        wrapper.unmount();
    });

    it('routes alt left click through the shared love reaction shortcut', async () => {
        const itemInteractions = createItemInteractions();
        const wrapper = mount(TabContentContainerSheet, {
            props: {
                open: true,
                container: { id: 10, type: 'gallery' },
                items: [createItem(1), createItem(2)],
                itemInteractions: itemInteractions as never,
            },
            global: {
                stubs: {
                    FileReactions: {
                        name: 'FileReactions',
                        template: '<div />',
                    },
                },
            },
        });

        await wrapper.get('[data-test="sheet-vibe-item-body-1"]').trigger('click', {
            altKey: true,
            button: 0,
        });

        expect(itemInteractions.reactions.onFileReaction).toHaveBeenCalledWith(
            expect.objectContaining({ id: 2 }),
            'love',
        );
        expect(mockVibeRemove).toHaveBeenCalledWith('2');

        wrapper.unmount();
    });

    it('routes alt middle click through the shared like reaction shortcut', async () => {
        const itemInteractions = createItemInteractions();
        const wrapper = mount(TabContentContainerSheet, {
            props: {
                open: true,
                container: { id: 10, type: 'gallery' },
                items: [createItem(1), createItem(2)],
                itemInteractions: itemInteractions as never,
            },
            global: {
                stubs: {
                    FileReactions: {
                        name: 'FileReactions',
                        template: '<div />',
                    },
                },
            },
        });

        await wrapper.get('[data-test="sheet-vibe-item-body-0"]').trigger('mousedown', {
            altKey: true,
            button: 1,
        });

        expect(itemInteractions.reactions.onFileReaction).toHaveBeenCalledWith(
            expect.objectContaining({ id: 1 }),
            'like',
        );
        expect(mockVibeRemove).toHaveBeenCalledWith('1');

        wrapper.unmount();
    });

    it('routes alt right click through the shared blacklist shortcut', async () => {
        const itemInteractions = createItemInteractions();
        const wrapper = mount(TabContentContainerSheet, {
            props: {
                open: true,
                container: { id: 10, type: 'gallery' },
                items: [createItem(1), createItem(2)],
                itemInteractions: itemInteractions as never,
            },
            global: {
                stubs: {
                    FileReactions: {
                        name: 'FileReactions',
                        template: '<div />',
                    },
                },
            },
        });

        await wrapper.get('[data-test="sheet-vibe-item-body-1"]').trigger('contextmenu', {
            altKey: true,
            button: 2,
        });

        expect(itemInteractions.reactions.onFileBlacklist).toHaveBeenCalledWith(
            expect.objectContaining({ id: 2 }),
        );
        expect(mockVibeRemove).toHaveBeenCalledWith('2');

        wrapper.unmount();
    });
});
