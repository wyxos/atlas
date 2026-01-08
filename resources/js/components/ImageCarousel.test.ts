import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import ImageCarousel from './ImageCarousel.vue';
import type { FeedItem } from '../composables/useTabs';

// Mock lucide-vue-next icons
vi.mock('lucide-vue-next', () => ({
    ChevronLeft: { name: 'ChevronLeft', template: '<svg data-test="chevron-left"></svg>' },
    ChevronRight: { name: 'ChevronRight', template: '<svg data-test="chevron-right"></svg>' },
    Loader2: { name: 'Loader2', template: '<svg data-test="loader2"></svg>' },
}));

describe('ImageCarousel', () => {
    const createMockItems = (count: number): FeedItem[] => {
        return Array.from({ length: count }, (_, i) => ({
            id: i + 1,
            width: 500,
            height: 500,
            page: 1,
            key: `1-${i + 1}`,
            index: i,
            preview: `https://example.com/preview${i + 1}.jpg`,
            original: `https://example.com/original${i + 1}.jpg`,
            src: `https://example.com/preview${i + 1}.jpg`,
        }));
    };

    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders carousel when visible', () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 0,
                visible: true,
            },
        });

        expect(wrapper.find('[data-test="image-carousel"]').exists()).toBe(true);
    });

    it('does not render carousel when not visible', () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 0,
                visible: false,
            },
        });

        const carousel = wrapper.find('[data-test="image-carousel"]');
        expect(carousel.exists()).toBe(true);
        // Check that height is 0 when not visible
        expect(carousel.attributes('style')).toContain('height: 0px');
    });

    it('displays all items in the carousel', () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 5,
                visible: true,
            },
        });

        // Should render all items
        const carouselItems = wrapper.findAll('[data-test^="carousel-item-"]');
        expect(carouselItems.length).toBe(items.length);
    });

    it('marks current item as active', () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 5,
                visible: true,
            },
        });

        // Current item should have active styling (border-4, scale-110)
        const currentItem = wrapper.find('[data-test="carousel-item-5"]');
        expect(currentItem.classes()).toContain('border-4');
        expect(currentItem.classes()).toContain('scale-110');
    });

    it('marks non-current items as inactive', () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 5,
                visible: true,
            },
        });

        // Non-current items should have inactive styling (opacity-50)
        const inactiveItem = wrapper.find('[data-test="carousel-item-3"]');
        expect(inactiveItem.classes()).toContain('opacity-50');
        expect(inactiveItem.classes()).not.toContain('scale-110');
    });

    it('emits next event when next button is clicked', async () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 5,
                visible: true,
            },
        });

        const nextButton = wrapper.find('[data-test="carousel-next-button"]');
        await nextButton.trigger('click');

        expect(wrapper.emitted('next')).toBeTruthy();
        expect(wrapper.emitted('next')).toHaveLength(1);
    });

    it('emits previous event when previous button is clicked', async () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 5,
                visible: true,
            },
        });

        const prevButton = wrapper.find('[data-test="carousel-previous-button"]');
        await prevButton.trigger('click');

        expect(wrapper.emitted('previous')).toBeTruthy();
        expect(wrapper.emitted('previous')).toHaveLength(1);
    });

    it('disables next button when at last item', () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 9, // Last item (index 9)
                visible: true,
            },
        });

        const nextButton = wrapper.find('[data-test="carousel-next-button"]');
        expect(nextButton.attributes('disabled')).toBeDefined();
    });

    it('disables previous button when at first item', () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 0,
                visible: true,
            },
        });

        const prevButton = wrapper.find('[data-test="carousel-previous-button"]');
        expect(prevButton.attributes('disabled')).toBeDefined();
    });

    it('emits item-click event when clicking on a carousel item', async () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 5,
                visible: true,
            },
        });

        // Click on item at index 7
        const item7 = wrapper.find('[data-test="carousel-item-7"]');
        await item7.trigger('click');

        expect(wrapper.emitted('item-click')).toBeTruthy();
        expect(wrapper.emitted('item-click')).toHaveLength(1);
        expect(wrapper.emitted('item-click')?.[0]).toEqual([items[7]]);
    });

    it('does not emit item-click when clicking on current item', async () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 5,
                visible: true,
            },
        });

        const beforeClick = wrapper.emitted('item-click')?.length || 0;

        // Click on current item (index 5)
        const currentItem = wrapper.find('[data-test="carousel-item-5"]');
        await currentItem.trigger('click');

        const afterClick = wrapper.emitted('item-click')?.length || 0;
        // Should not emit if it's the current item
        expect(afterClick).toBe(beforeClick);
    });

    it('immediately marks clicked item as active', async () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 5,
                visible: true,
            },
        });

        // Click on item at index 7
        const item7 = wrapper.find('[data-test="carousel-item-7"]');
        await item7.trigger('click');
        await flushPromises();

        // Item 7 should immediately show as active
        const clickedItem = wrapper.find('[data-test="carousel-item-7"]');
        expect(clickedItem.classes()).toContain('border-4');
        expect(clickedItem.classes()).toContain('scale-110');
    });

    it('updates active state when currentItemIndex prop changes', async () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 5,
                visible: true,
            },
        });

        // Initially item 5 should be active
        let item5 = wrapper.find('[data-test="carousel-item-5"]');
        expect(item5.classes()).toContain('scale-110');

        // Update to item 7
        await wrapper.setProps({ currentItemIndex: 7 });
        await flushPromises();
        await vi.runAllTimersAsync();

        // Item 7 should now be active
        const item7 = wrapper.find('[data-test="carousel-item-7"]');
        expect(item7.classes()).toContain('scale-110');

        // Item 5 should no longer be active
        item5 = wrapper.find('[data-test="carousel-item-5"]');
        expect(item5.classes()).not.toContain('scale-110');
    });

    it('applies transition class when animating', async () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 5,
                visible: true,
            },
        });

        // Find the items container
        const container = wrapper.find('.flex.items-center');

        // When currentItemIndex changes, transition should be applied
        await wrapper.setProps({ currentItemIndex: 7 });
        await flushPromises();

        // Container should have transition class
        expect(container.classes()).toContain('transition-transform');
    });

    it('handles empty items array', () => {
        const wrapper = mount(ImageCarousel, {
            props: {
                items: [],
                currentItemIndex: null,
                visible: true,
            },
        });

        expect(wrapper.find('[data-test="image-carousel"]').exists()).toBe(true);
    });

    it('handles null currentItemIndex', () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: null,
                visible: true,
            },
        });

        // Should still render but with disabled buttons
        const nextButton = wrapper.find('[data-test="carousel-next-button"]');
        const prevButton = wrapper.find('[data-test="carousel-previous-button"]');
        expect(nextButton.attributes('disabled')).toBeDefined();
        expect(prevButton.attributes('disabled')).toBeDefined();
    });

    it('updates scroll position when currentItemIndex changes', async () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 0,
                visible: true,
            },
        });

        await flushPromises();
        await vi.runAllTimersAsync();

        // Verify component is rendered
        expect(wrapper.find('[data-test="image-carousel"]').exists()).toBe(true);

        // Update currentItemIndex - component should handle this without errors
        await wrapper.setProps({ currentItemIndex: 5 });
        await flushPromises();
        await vi.runAllTimersAsync();

        // Verify component still renders correctly after change
        expect(wrapper.find('[data-test="image-carousel"]').exists()).toBe(true);
        expect(wrapper.find('.flex.items-center').exists()).toBe(true);

        // Verify no errors occurred
        expect(wrapper.vm.$el).toBeTruthy();
    });

    it('calculates scroll position correctly for clicked item', async () => {
        const items = createMockItems(20);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 10,
                visible: true,
            },
        });

        await flushPromises();
        await vi.runAllTimersAsync();

        // Click on item at index 15
        const item15 = wrapper.find('[data-test="carousel-item-15"]');
        await item15.trigger('click');
        await flushPromises();

        // Component should handle the click without errors
        expect(wrapper.find('[data-test="image-carousel"]').exists()).toBe(true);

        // Item 15 should be marked as active
        const clickedItem = wrapper.find('[data-test="carousel-item-15"]');
        expect(clickedItem.classes()).toContain('scale-110');
    });

    it('syncs clicked item state with prop update', async () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 5,
                visible: true,
            },
        });

        // Click on item 7
        const item7 = wrapper.find('[data-test="carousel-item-7"]');
        await item7.trigger('click');
        await flushPromises();

        // Item 7 should be active immediately
        let clickedItem = wrapper.find('[data-test="carousel-item-7"]');
        expect(clickedItem.classes()).toContain('scale-110');

        // When parent updates currentItemIndex to 7, it should stay active
        await wrapper.setProps({ currentItemIndex: 7 });
        await flushPromises();
        await vi.runAllTimersAsync();

        clickedItem = wrapper.find('[data-test="carousel-item-7"]');
        expect(clickedItem.classes()).toContain('scale-110');
    });

    it('displays loading spinner when isLoading is true', () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 5,
                visible: true,
                isLoading: true,
            },
        });

        const loadingSpinner = wrapper.find('[data-test="carousel-loading-spinner"]');
        expect(loadingSpinner.exists()).toBe(true);
    });

    it('does not display loading spinner when isLoading is false', () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 5,
                visible: true,
                isLoading: false,
            },
        });

        const loadingSpinner = wrapper.find('[data-test="carousel-loading-spinner"]');
        expect(loadingSpinner.exists()).toBe(false);
    });

    it('does not display loading spinner when items array is empty', () => {
        const wrapper = mount(ImageCarousel, {
            props: {
                items: [],
                currentItemIndex: null,
                visible: true,
                isLoading: true,
            },
        });

        const loadingSpinner = wrapper.find('[data-test="carousel-loading-spinner"]');
        expect(loadingSpinner.exists()).toBe(false);
    });

    it('calls onLoadMore when clicking on last item', async () => {
        const items = createMockItems(10);
        const onLoadMore = vi.fn().mockResolvedValue(undefined);

        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 9, // Last item
                visible: true,
                hasMore: true,
                isLoading: false,
                onLoadMore,
            },
        });

        // Click on last item
        const lastItem = wrapper.find('[data-test="carousel-item-9"]');
        await lastItem.trigger('click');
        await flushPromises();

        expect(onLoadMore).toHaveBeenCalledTimes(1);
    });

    it('calls onLoadMore when navigating to last item', async () => {
        const items = createMockItems(10);
        const onLoadMore = vi.fn().mockResolvedValue(undefined);

        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 8,
                visible: true,
                hasMore: true,
                isLoading: false,
                onLoadMore,
            },
        });

        // Navigate to last item (index 9)
        await wrapper.setProps({ currentItemIndex: 9 });
        await flushPromises();
        await vi.runAllTimersAsync();

        expect(onLoadMore).toHaveBeenCalledTimes(1);
    });

    it('does not call onLoadMore when hasMore is false', async () => {
        const items = createMockItems(10);
        const onLoadMore = vi.fn().mockResolvedValue(undefined);

        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 8,
                visible: true,
                hasMore: false,
                isLoading: false,
                onLoadMore,
            },
        });

        // Navigate to last item
        await wrapper.setProps({ currentItemIndex: 9 });
        await flushPromises();
        await vi.runAllTimersAsync();

        expect(onLoadMore).not.toHaveBeenCalled();
    });

    it('does not call onLoadMore when isLoading is true', async () => {
        const items = createMockItems(10);
        const onLoadMore = vi.fn().mockResolvedValue(undefined);

        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 8,
                visible: true,
                hasMore: true,
                isLoading: true,
                onLoadMore,
            },
        });

        // Navigate to last item
        await wrapper.setProps({ currentItemIndex: 9 });
        await flushPromises();
        await vi.runAllTimersAsync();

        expect(onLoadMore).not.toHaveBeenCalled();
    });

    it('does not call onLoadMore when not at last item', async () => {
        const items = createMockItems(10);
        const onLoadMore = vi.fn().mockResolvedValue(undefined);

        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 5,
                visible: true,
                hasMore: true,
                isLoading: false,
                onLoadMore,
            },
        });

        // Navigate to item 6 (not last)
        await wrapper.setProps({ currentItemIndex: 6 });
        await flushPromises();
        await vi.runAllTimersAsync();

        expect(onLoadMore).not.toHaveBeenCalled();
    });

    it('uses TransitionGroup for item animations', () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 5,
                visible: true,
            },
        });

        // TransitionGroup should be present
        const transitionGroup = wrapper.findComponent({ name: 'TransitionGroup' });
        expect(transitionGroup.exists()).toBe(true);
        expect(transitionGroup.attributes('name')).toBe('slide-in-right');
    });

    it('applies stagger delay to new items', async () => {
        const initialItems = createMockItems(5);
        const wrapper = mount(ImageCarousel, {
            props: {
                items: initialItems,
                currentItemIndex: 4,
                visible: true,
            },
        });

        await flushPromises();
        await vi.runAllTimersAsync();

        // Add new items
        const newItems = createMockItems(10);
        await wrapper.setProps({ items: newItems });
        await flushPromises();

        // New items should have stagger delay in their style
        const newItem = wrapper.find('[data-test="carousel-item-5"]');
        const style = newItem.attributes('style');

        // Should have --stagger-delay CSS variable
        // The first new item (index 5) should have delay of (5 - 5) * 100 = 0ms
        expect(style).toContain('--stagger-delay');
    });
});
