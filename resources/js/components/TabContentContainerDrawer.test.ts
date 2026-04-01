import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import TabContentContainerDrawer from './TabContentContainerDrawer.vue';
import type { FeedItem } from '@/composables/useTabs';

vi.mock('@/components/ui/sheet', () => ({
    Sheet: {
        name: 'Sheet',
        template: `
            <div class="sheet-mock">
                <slot />
                <button data-test="sheet-outside-close" @click="$emit('update:open', false)">Outside</button>
            </div>
        `,
        props: ['open', 'modal'],
        emits: ['update:open'],
    },
    SheetContent: {
        name: 'SheetContent',
        template: '<div class="sheet-content-mock" v-bind="$attrs"><slot /></div>',
        props: ['side', 'class', 'showOverlay'],
    },
    SheetHeader: {
        name: 'SheetHeader',
        template: '<div class="sheet-header-mock"><slot /></div>',
    },
    SheetTitle: {
        name: 'SheetTitle',
        template: '<div class="sheet-title-mock"><slot /></div>',
        props: ['class'],
    },
    SheetDescription: {
        name: 'SheetDescription',
        template: '<div class="sheet-description-mock"><slot /></div>',
        props: ['class'],
    },
}));

function createItem(id: number, type: 'image' | 'video' = 'image'): FeedItem {
    return {
        id,
        width: 500,
        height: 500,
        page: 1,
        key: `1-${id}`,
        index: id - 1,
        src: `https://example.com/preview${id}.jpg`,
        preview: type === 'image'
            ? `https://example.com/preview${id}.jpg`
            : `https://example.com/preview${id}.mp4`,
        type,
    } as FeedItem;
}

describe('TabContentContainerDrawer', () => {
    it('renders related previews with accessible sheet copy', () => {
        const wrapper = mount(TabContentContainerDrawer, {
            props: {
                open: true,
                container: {
                    id: 10,
                    type: 'gallery',
                },
                items: [createItem(1), createItem(2, 'video')],
            },
        });

        expect(wrapper.getComponent({ name: 'Sheet' }).props('modal')).toBe(false);
        expect(wrapper.getComponent({ name: 'SheetContent' }).props('showOverlay')).toBe(false);
        expect(wrapper.get('[data-test="container-related-items-title"]').text()).toBe('Related items');
        expect(wrapper.get('[data-test="container-related-items-description"]').text()).toBe('2 related items from gallery.');
        expect(wrapper.get('[data-test="container-related-items-track"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="container-related-item-0"] img').exists()).toBe(true);
        expect(wrapper.find('[data-test="container-related-item-1"] video').exists()).toBe(true);
    });

    it('does nothing when clicking a preview tile', async () => {
        const wrapper = mount(TabContentContainerDrawer, {
            props: {
                open: true,
                container: {
                    id: 10,
                    type: 'gallery',
                },
                items: [createItem(1)],
            },
        });

        await wrapper.get('[data-test="container-related-item-0"]').trigger('click');

        expect(wrapper.emitted('update:open')).toBeUndefined();
    });

    it('emits close when the sheet reports an outside click', async () => {
        const wrapper = mount(TabContentContainerDrawer, {
            props: {
                open: true,
                container: {
                    id: 10,
                    type: 'gallery',
                },
                items: [createItem(1)],
            },
        });

        await wrapper.get('[data-test="sheet-outside-close"]').trigger('click');

        expect(wrapper.emitted('update:open')).toEqual([[false]]);
    });

    it('keeps the last rendered content while closing so the exit animation has content', async () => {
        const wrapper = mount(TabContentContainerDrawer, {
            props: {
                open: true,
                container: {
                    id: 10,
                    type: 'gallery',
                },
                items: [createItem(1), createItem(2)],
            },
        });

        await wrapper.setProps({
            open: false,
            container: null,
            items: [],
        });

        expect(wrapper.get('[data-test="container-related-items-description"]').text()).toBe('2 related items from gallery.');
        expect(wrapper.find('[data-test="container-related-item-0"] img').exists()).toBe(true);
        expect(wrapper.find('[data-test="container-related-item-1"] img').exists()).toBe(true);
    });
});
