import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
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
    const mockAxios = {
        post: vi.fn().mockResolvedValue({ data: { seen_count: 1 } }),
        get: vi.fn().mockResolvedValue({ data: { file: null } }),
    };
    Object.defineProperty(window, 'axios', { value: mockAxios, writable: true });
    if (!window.requestAnimationFrame) {
        Object.defineProperty(window, 'requestAnimationFrame', {
            value: (cb: FrameRequestCallback) => setTimeout(() => cb(0), 0),
            writable: true,
        });
    }
});

describe('FileViewer reactions', () => {
    it('navigates to the next item after reacting when the current item is removed', async () => {
        const items = [
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'video', page: 1, index: 0, notFound: false },
            { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'video', page: 1, index: 1, notFound: false },
        ];

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
});
