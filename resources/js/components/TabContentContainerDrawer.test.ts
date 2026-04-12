import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import TabContentContainerDrawer from './TabContentContainerDrawer.vue';
import type { FeedItem } from '@/composables/useTabs';

vi.mock('@/components/ui/carousel', () => ({
    Carousel: {
        name: 'Carousel',
        template: '<div v-bind="$attrs" :class="$props.class"><slot /></div>',
        props: ['opts', 'plugins', 'orientation', 'class'],
    },
    CarouselContent: {
        name: 'CarouselContent',
        template: '<div v-bind="$attrs"><div :class="$props.class"><slot /></div></div>',
        props: ['class'],
    },
    CarouselItem: {
        name: 'CarouselItem',
        template: '<div v-bind="$attrs" :class="$props.class"><slot /></div>',
        props: ['class'],
    },
    CarouselPrevious: {
        name: 'CarouselPrevious',
        template: '<button type="button" v-bind="$attrs" :class="$props.class"><slot /></button>',
        props: ['class', 'variant', 'size'],
    },
    CarouselNext: {
        name: 'CarouselNext',
        template: '<button type="button" v-bind="$attrs" :class="$props.class"><slot /></button>',
        props: ['class', 'variant', 'size'],
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

afterEach(() => {
    document.body.innerHTML = '';
});

describe('TabContentContainerDrawer', () => {
    it('renders related previews with accessible dialog copy', () => {
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

        const drawer = wrapper.get('[data-test="container-related-items-drawer"]');

        expect(drawer.attributes('role')).toBe('dialog');
        expect(drawer.attributes('aria-modal')).toBe('false');
        expect(wrapper.get('[data-test="container-related-items-title"]').text()).toBe('Related items');
        expect(wrapper.get('[data-test="container-related-items-description"]').text()).toBe('2 related items from gallery.');
        expect(wrapper.get('[data-test="container-related-items-carousel"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="container-related-items-track"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="container-related-item-0"] img').exists()).toBe(true);
        expect(wrapper.find('[data-test="container-related-item-1"] video').exists()).toBe(true);

        wrapper.unmount();
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

        wrapper.unmount();
    });

    it('emits close when clicking outside the drawer', async () => {
        const wrapper = mount(TabContentContainerDrawer, {
            attachTo: document.body,
            props: {
                open: true,
                container: {
                    id: 10,
                    type: 'gallery',
                },
                items: [createItem(1)],
            },
        });

        document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('update:open')).toEqual([[false]]);

        wrapper.unmount();
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

        wrapper.unmount();
    });

    it('emits close on drawer mouseleave when configured for hover-open behavior', async () => {
        const wrapper = mount(TabContentContainerDrawer, {
            props: {
                open: true,
                closeOnMouseLeave: true,
                container: {
                    id: 10,
                    type: 'gallery',
                },
                items: [createItem(1)],
            },
        });

        await wrapper.get('[data-test="container-related-items-drawer"]').trigger('mouseleave');

        expect(wrapper.emitted('update:open')).toEqual([[false]]);

        wrapper.unmount();
    });

    it('does not emit close on drawer mouseleave for click-open behavior', async () => {
        const wrapper = mount(TabContentContainerDrawer, {
            props: {
                open: true,
                closeOnMouseLeave: false,
                container: {
                    id: 10,
                    type: 'gallery',
                },
                items: [createItem(1)],
            },
        });

        await wrapper.get('[data-test="container-related-items-drawer"]').trigger('mouseleave');

        expect(wrapper.emitted('update:open')).toBeUndefined();

        wrapper.unmount();
    });
});
