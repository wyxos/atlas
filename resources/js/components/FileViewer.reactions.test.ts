import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';
import FileViewer from './FileViewer.vue';

const containerRef = document.createElement('div');
containerRef.getBoundingClientRect = () => ({
    top: 0,
    left: 0,
    width: 800,
    height: 600,
    bottom: 600,
    right: 800,
    x: 0,
    y: 0,
    toJSON: () => ({}),
});

beforeEach(() => {
    window.localStorage.clear();

    const mockAxios = {
        post: vi.fn().mockResolvedValue({ data: { seen_count: 1 } }),
        get: vi.fn().mockResolvedValue({ data: { file: null } }),
    };
    Object.defineProperty(window, 'axios', { value: mockAxios, writable: true });
});

describe('FileViewer reactions', () => {
    it('navigates to the next item after reacting when the current item is removed', async () => {
        const items = reactive([
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'video', page: 1, index: 0, notFound: false },
            { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'video', page: 1, index: 1, notFound: false },
        ]);

        const wrapper = mount(FileViewer, {
            props: {
                containerRef,
                masonryContainerRef: containerRef,
                items,
                masonry: null,
                onReaction: (fileId: number) => {
                    const index = items.findIndex((item) => item.id === fileId);
                    if (index !== -1) {
                        items.splice(index, 1);
                    }
                },
            },
        });

        const vm = wrapper.vm as any;
        vm.overlayState.rect = { top: 0, left: 0, width: 800, height: 600 };
        vm.overlayState.image = { src: 'test1.jpg', alt: 'Test 1' };
        vm.overlayState.isFilled = true;
        vm.overlayState.fillComplete = true;
        vm.navigationState.currentItemIndex = 0;
        await nextTick();

        const likeButton = wrapper.find('button[aria-label="Like"]');
        expect(likeButton.exists()).toBe(true);

        await likeButton.trigger('click');
        await nextTick();

        expect(vm.navigationState.currentItemIndex).toBe(0);
        expect(items[vm.navigationState.currentItemIndex]?.id).toBe(2);
    });

    it('triggers like reaction on alt+click and moves to next item', async () => {
        const items = reactive([
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'video', page: 1, index: 0, notFound: false },
            { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'video', page: 1, index: 1, notFound: false },
        ]);

        const wrapper = mount(FileViewer, {
            props: {
                containerRef,
                masonryContainerRef: containerRef,
                items,
                masonry: null,
            },
        });

        const vm = wrapper.vm as any;
        vm.overlayState.rect = { top: 0, left: 0, width: 800, height: 600 };
        vm.overlayState.image = { src: 'test1.jpg', alt: 'Test 1' };
        vm.overlayState.isFilled = true;
        vm.overlayState.fillComplete = true;
        vm.navigationState.currentItemIndex = 0;
        await nextTick();

        const image = wrapper.find('img[alt="Test 1"]');
        expect(image.exists()).toBe(true);

        await image.trigger('click', { altKey: true, button: 0 });
        await nextTick();

        expect(wrapper.emitted('reaction')?.[0]).toEqual([1, 'like']);
        expect(vm.navigationState.currentItemIndex).toBe(1);
    });

    it('keeps current item in sync when items restore before the current item', async () => {
        const items = reactive([
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'video', page: 1, index: 0, notFound: false },
            { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'video', page: 1, index: 1, notFound: false },
        ]);

        const wrapper = mount(FileViewer, {
            props: {
                containerRef,
                masonryContainerRef: containerRef,
                items,
                masonry: null,
            },
        });

        const vm = wrapper.vm as any;
        vm.overlayState.rect = { top: 0, left: 0, width: 800, height: 600 };
        vm.overlayState.image = { src: 'test2.jpg', alt: 'Test 2' };
        vm.overlayState.isFilled = true;
        vm.overlayState.fillComplete = true;
        vm.navigationState.currentItemIndex = 1;
        await nextTick();

        items.unshift({ id: 99, width: 300, height: 400, src: 'test99.jpg', type: 'video', page: 1, index: 0, notFound: false });
        await nextTick();

        expect(vm.navigationState.currentItemIndex).toBe(2);
    });

    it('updates index after undo restores an item before the current one', async () => {
        const items = reactive([
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'video', page: 1, index: 0, notFound: false },
            { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'video', page: 1, index: 1, notFound: false },
            { id: 3, width: 300, height: 400, src: 'test3.jpg', type: 'video', page: 1, index: 2, notFound: false },
            { id: 4, width: 300, height: 400, src: 'test4.jpg', type: 'video', page: 1, index: 3, notFound: false },
        ]);

        const wrapper = mount(FileViewer, {
            props: {
                containerRef,
                masonryContainerRef: containerRef,
                items,
                masonry: null,
            },
        });

        const vm = wrapper.vm as any;
        vm.overlayState.rect = { top: 0, left: 0, width: 800, height: 600 };
        vm.overlayState.image = { src: 'test3.jpg', alt: 'Test 3' };
        vm.overlayState.isFilled = true;
        vm.overlayState.fillComplete = true;
        vm.navigationState.currentItemIndex = 2;
        await nextTick();

        const likeButton = wrapper.find('button[aria-label="Like"]');
        await likeButton.trigger('click');
        await nextTick();

        expect(vm.navigationState.currentItemIndex).toBe(3);
        expect(items[vm.navigationState.currentItemIndex]?.id).toBe(4);

        items.splice(2, 1);
        await nextTick();
        await nextTick();

        expect(vm.navigationState.currentItemIndex).toBe(2);
        expect(items[vm.navigationState.currentItemIndex]?.id).toBe(4);

        items.splice(2, 0, { id: 3, width: 300, height: 400, src: 'test3.jpg', type: 'video', page: 1, index: 2, notFound: false });
        await nextTick();
        await nextTick();

        expect(vm.navigationState.currentItemIndex).toBe(3);
        expect(items[vm.navigationState.currentItemIndex]?.id).toBe(4);
    });
});

describe('FileViewer sheet persistence', () => {
    it('restores sheet open state from localStorage', async () => {
        window.localStorage.setItem('atlas:fileViewerSheetOpen', '1');

        const items = reactive([
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'video', page: 1, index: 0, notFound: false },
        ]);

        const wrapper = mount(FileViewer, {
            props: {
                containerRef,
                masonryContainerRef: containerRef,
                items,
                masonry: null,
            },
        });

        const vm = wrapper.vm as any;
        expect(vm.sheetState.isOpen).toBe(true);
    });

    it('persists user toggles for sheet visibility', async () => {
        window.localStorage.setItem('atlas:fileViewerSheetOpen', '0');

        const items = reactive([
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'video', page: 1, index: 0, notFound: false },
        ]);

        const wrapper = mount(FileViewer, {
            props: {
                containerRef,
                masonryContainerRef: containerRef,
                items,
                masonry: null,
            },
        });

        const vm = wrapper.vm as any;
        vm.overlayState.rect = { top: 0, left: 0, width: 800, height: 600 };
        vm.overlayState.image = { src: 'test1.jpg', alt: 'Test 1' };
        vm.overlayState.isFilled = true;
        vm.overlayState.fillComplete = true;
        vm.navigationState.currentItemIndex = 0;
        await nextTick();

        // Open via taskbar CTA and persist.
        await wrapper.get('button[aria-label="Open sheet"]').trigger('click');
        await nextTick();
        expect(window.localStorage.getItem('atlas:fileViewerSheetOpen')).toBe('1');

        // Close via sheet header CTA and persist.
        await wrapper.get('button[aria-label="Hide details panel"]').trigger('click');
        await nextTick();
        expect(window.localStorage.getItem('atlas:fileViewerSheetOpen')).toBe('0');
    });

    it('keeps sheet state when closing and reopening the overlay', async () => {
        vi.useFakeTimers();

        let wrapper: ReturnType<typeof mount> | null = null;

        try {
            window.localStorage.setItem('atlas:fileViewerSheetOpen', '0');

            const items = reactive([
                { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'video', page: 1, index: 0, notFound: false },
            ]);

            wrapper = mount(FileViewer, {
                props: {
                    containerRef,
                    masonryContainerRef: containerRef,
                    items,
                    masonry: null,
                },
            });

            const vm = wrapper.vm as any;
            vm.overlayState.rect = { top: 0, left: 0, width: 800, height: 600 };
            vm.overlayState.image = { src: 'test1.jpg', alt: 'Test 1' };
            vm.overlayState.isFilled = true;
            vm.overlayState.fillComplete = true;
            vm.navigationState.currentItemIndex = 0;
            await nextTick();

            await wrapper.get('button[aria-label="Open sheet"]').trigger('click');
            await nextTick();

            expect(vm.sheetState.isOpen).toBe(true);
            expect(window.localStorage.getItem('atlas:fileViewerSheetOpen')).toBe('1');

            await wrapper.get('[data-test="close-overlay-button"]').trigger('click');
            vi.advanceTimersByTime(500);
            await nextTick();

            expect(vm.sheetState.isOpen).toBe(true);
            expect(window.localStorage.getItem('atlas:fileViewerSheetOpen')).toBe('1');

            // Simulate reopening the overlay.
            vm.overlayState.rect = { top: 0, left: 0, width: 800, height: 600 };
            vm.overlayState.image = { src: 'test1.jpg', alt: 'Test 1' };
            vm.overlayState.isFilled = true;
            vm.overlayState.fillComplete = true;
            vm.overlayState.isClosing = false;
            vm.navigationState.currentItemIndex = 0;
            await nextTick();

            expect(vm.sheetState.isOpen).toBe(true);
        } finally {
            wrapper?.unmount();
            vi.clearAllTimers();
            vi.useRealTimers();
        }
    });

    it('does not auto-open the sheet for file media when user preference is closed', async () => {
        window.localStorage.setItem('atlas:fileViewerSheetOpen', '0');

        const items = reactive([
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'file', page: 1, index: 0, notFound: false },
        ]);

        const wrapper = mount(FileViewer, {
            props: {
                containerRef,
                masonryContainerRef: containerRef,
                items,
                masonry: null,
            },
        });

        const vm = wrapper.vm as any;
        vm.overlayState.rect = { top: 0, left: 0, width: 800, height: 600 };
        vm.overlayState.image = { src: 'test1.jpg', alt: 'Test 1' };
        vm.overlayState.isFilled = true;
        vm.overlayState.fillComplete = true;
        vm.overlayState.mediaType = 'file';
        vm.navigationState.currentItemIndex = 0;
        await nextTick();

        expect(vm.sheetState.isOpen).toBe(false);
    });
});


