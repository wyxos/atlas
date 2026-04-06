import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';
import FileViewer from './FileViewer.vue';
import { clearFileViewerPreloadCache } from '@/utils/fileViewer';

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

function clearLocalStorage(): void {
    const storage = window.localStorage as Storage & Record<string, unknown>;

    if (typeof storage?.clear === 'function') {
        storage.clear();
        return;
    }

    for (const key of Object.keys(storage ?? {})) {
        delete storage[key];
    }
}

beforeEach(() => {
    clearLocalStorage();
    clearFileViewerPreloadCache({ abortPending: true });
    Object.defineProperty(window, 'axios', {
        value: {
            post: vi.fn().mockResolvedValue({ data: { seen_count: 1 } }),
            get: vi.fn().mockResolvedValue({ data: { file: null } }),
        },
        writable: true,
    });
});

afterEach(() => {
    containerRef.replaceChildren();
    clearFileViewerPreloadCache({ abortPending: true });
    vi.useRealTimers();
});

describe('FileViewer opening cancellation', () => {
    it('cancels the preload and opening flow when Escape is pressed during image preload', async () => {
        vi.useFakeTimers();

        const originalImage = window.Image;
        class MockImage {
            onload: null | (() => void) = null;
            onerror: null | (() => void) = null;
            naturalWidth = 1200;
            naturalHeight = 800;
            private timer: ReturnType<typeof setTimeout> | null = null;

            set src(value: string) {
                if (this.timer) {
                    clearTimeout(this.timer);
                    this.timer = null;
                }

                if (!value) {
                    return;
                }

                this.timer = setTimeout(() => {
                    this.onload?.();
                }, 1000);
            }
        }

        Object.defineProperty(window, 'Image', {
            value: MockImage,
            configurable: true,
            writable: true,
        });

        const itemCard = document.createElement('article');
        itemCard.setAttribute('data-testid', 'item-card');
        itemCard.getBoundingClientRect = () => ({
            top: 20,
            left: 30,
            width: 200,
            height: 240,
            bottom: 260,
            right: 230,
            x: 30,
            y: 20,
            toJSON: () => ({}),
        });

        const trigger = document.createElement('button');
        trigger.setAttribute('data-file-id', '1');
        const image = document.createElement('img');
        image.setAttribute('src', 'https://image.civitai.com/token/guid/width=1024/guid.jpeg');
        trigger.appendChild(image);
        itemCard.appendChild(trigger);
        containerRef.appendChild(itemCard);

        const items = reactive([
            {
                id: 1,
                width: 300,
                height: 400,
                src: 'https://image.civitai.com/token/guid/width=1024/guid.jpeg',
                preview: 'https://image.civitai.com/token/guid/width=1024/guid.jpeg',
                original: 'https://image.civitai.com/token/guid/original=true/guid.jpeg',
                type: 'image' as const,
                page: 1,
                index: 0,
                notFound: false,
            },
        ]);

        try {
            const wrapper = mount(FileViewer, {
                props: {
                    containerRef,
                    masonryContainerRef: containerRef,
                    items,
                    masonry: null,
                },
            });

            const openPromise = (wrapper.vm as { openFromClick: (event: MouseEvent) => Promise<void> }).openFromClick({
                target: image,
                button: 0,
            } as MouseEvent);

            await nextTick();

            const vm = wrapper.vm as {
                overlayState: {
                    rect: { top: number; left: number; width: number; height: number } | null;
                    isLoading: boolean;
                    fillComplete: boolean;
                };
            };
            expect(vm.overlayState.isLoading).toBe(true);
            expect(vm.overlayState.rect).not.toBeNull();

            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

            await vi.advanceTimersByTimeAsync(1000);
            await openPromise;
            await nextTick();

            const mockAxios = window.axios as { post: ReturnType<typeof vi.fn> };
            expect(vm.overlayState.rect).toBeNull();
            expect(vm.overlayState.isLoading).toBe(false);
            expect(vm.overlayState.fillComplete).toBe(false);
            expect(mockAxios.post).not.toHaveBeenCalled();
            expect(wrapper.find('[data-test="close-overlay-button"]').exists()).toBe(false);
            expect(wrapper.emitted('previewFailure')).toBeUndefined();
        } finally {
            Object.defineProperty(window, 'Image', {
                value: originalImage,
                configurable: true,
                writable: true,
            });
        }
    });
});
