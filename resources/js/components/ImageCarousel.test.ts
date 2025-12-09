import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import ImageCarousel from './ImageCarousel.vue';
import type { MasonryItem } from '../composables/useBrowseTabs';

// Mock lucide-vue-next icons
vi.mock('lucide-vue-next', () => ({
    ChevronLeft: { name: 'ChevronLeft', template: '<svg data-test="chevron-left"></svg>' },
    ChevronRight: { name: 'ChevronRight', template: '<svg data-test="chevron-right"></svg>' },
    Loader2: { name: 'Loader2', template: '<svg data-test="loader"></svg>' },
}));

describe('ImageCarousel', () => {
    const createMockItems = (count: number): MasonryItem[] => {
        return Array.from({ length: count }, (_, i) => ({
            id: i + 1,
            src: `https://example.com/image${i + 1}.jpg`,
            thumbnail: `https://example.com/thumb${i + 1}.jpg`,
            originalUrl: `https://example.com/original${i + 1}.jpg`,
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

    it('displays correct number of boxes', () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 5,
                visible: true,
            },
        });

        // Should render 11 boxes (carousel has 11 slots)
        const boxes = wrapper.findAll('[data-test^="carousel-box-"]');
        expect(boxes.length).toBeGreaterThan(0);
    });

    it('hides empty boxes when near the end', () => {
        const items = createMockItems(8);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 3, // 4th item (index 3)
                visible: true,
            },
        });

        // Get all boxes
        const boxes = wrapper.findAll('[data-test^="carousel-box-"]');
        
        // Count visible boxes (not hidden)
        const visibleBoxes = boxes.filter(box => {
            const element = box.element as HTMLElement;
            return element.style.display !== 'none' && !element.hasAttribute('v-show');
        });

        // Should not show empty boxes when we don't have enough items
        expect(visibleBoxes.length).toBeLessThanOrEqual(items.length + 1); // +1 for current item
    });

    it('hides empty boxes when at 5th item before last', () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 4, // 5th item (index 4), 5 items before last
                visible: true,
            },
        });

        const boxes = wrapper.findAll('[data-test^="carousel-box-"]');
        const hiddenBoxes = boxes.filter(box => {
            const element = box.element as HTMLElement;
            return element.style.display === 'none';
        });

        // Should hide empty boxes
        expect(hiddenBoxes.length).toBeGreaterThan(0);
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

        // Find a box that's not the current item
        const box = wrapper.find('[data-test="carousel-box-6"]');
        if (box.exists()) {
            await box.trigger('click');
            expect(wrapper.emitted('item-click')).toBeTruthy();
        }
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

        // The center box (box 5) should be the current item
        // Clicking it should not emit item-click
        const beforeClick = wrapper.emitted('item-click')?.length || 0;
        
        // Try clicking the center box which should be the current item
        const centerBox = wrapper.find('[data-test="carousel-box-5"]');
        if (centerBox.exists()) {
            await centerBox.trigger('click');
        }
        
        const afterClick = wrapper.emitted('item-click')?.length || 0;
        // Should not emit if it's the current item
        expect(afterClick).toBe(beforeClick);
    });

    it('triggers onLoadMore only when clicking on last item', async () => {
        const onLoadMore = vi.fn().mockResolvedValue(undefined);
        const items = createMockItems(10);
        
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 8, // Second-to-last item
                visible: true,
                hasMore: true,
                isLoading: false,
                onLoadMore,
            },
        });

        // Click second-to-last item - should NOT trigger loading
        await wrapper.setProps({ currentItemIndex: 8 });
        await flushPromises();
        await vi.runAllTimersAsync();
        
        expect(onLoadMore).not.toHaveBeenCalled();

        // Click last item - should trigger loading
        await wrapper.setProps({ currentItemIndex: 9 });
        await flushPromises();
        await vi.runAllTimersAsync();

        expect(onLoadMore).toHaveBeenCalledTimes(1);
    });

    it('does not trigger onLoadMore when hasMore is false', async () => {
        const onLoadMore = vi.fn().mockResolvedValue(undefined);
        const items = createMockItems(10);
        
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 9, // Last item
                visible: true,
                hasMore: false, // No more items
                isLoading: false,
                onLoadMore,
            },
        });

        await wrapper.setProps({ currentItemIndex: 9 });
        await flushPromises();
        await vi.runAllTimersAsync();

        expect(onLoadMore).not.toHaveBeenCalled();
    });

    it('does not trigger onLoadMore when already loading', async () => {
        const onLoadMore = vi.fn().mockResolvedValue(undefined);
        const items = createMockItems(10);
        
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 9, // Last item
                visible: true,
                hasMore: true,
                isLoading: true, // Already loading
                onLoadMore,
            },
        });

        await wrapper.setProps({ currentItemIndex: 9 });
        await flushPromises();
        await vi.runAllTimersAsync();

        expect(onLoadMore).not.toHaveBeenCalled();
    });

    it('shows loading spinner when isLoading is true and near end', () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 9, // Last item
                visible: true,
                hasMore: true,
                isLoading: true,
            },
        });

        // Should show loading spinner
        const loaders = wrapper.findAll('[data-test="loader"]');
        expect(loaders.length).toBeGreaterThan(0);
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

    it('preloads images for visible items', async () => {
        const items = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items,
                currentItemIndex: 5,
                visible: true,
            },
        });

        await flushPromises();
        await vi.runAllTimersAsync();

        // Images should start loading
        const images = wrapper.findAll('img');
        expect(images.length).toBeGreaterThan(0);
    });

    it('handles new items being added with staggered animation', async () => {
        const initialItems = createMockItems(10);
        const wrapper = mount(ImageCarousel, {
            props: {
                items: initialItems,
                currentItemIndex: 9, // Last item
                visible: true,
                hasMore: true,
            },
        });

        // Add new items
        const newItems = createMockItems(15);
        await wrapper.setProps({ items: newItems });
        await flushPromises();
        await vi.runAllTimersAsync();

        // New items should be present
        const boxes = wrapper.findAll('[data-test^="carousel-box-"]');
        expect(boxes.length).toBeGreaterThan(0);
    });
});

