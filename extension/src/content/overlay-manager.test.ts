import { beforeEach, describe, expect, it, vi } from 'vitest';

const triggerReaction = vi.fn();
const unmount = vi.fn();
const triggerReactionByMedia = new WeakMap<Element, ReturnType<typeof vi.fn>>();
const unmountByMedia = new WeakMap<Element, ReturnType<typeof vi.fn>>();

vi.mock('./reaction-badge-app', () => ({
    createReactionBadgeHost: (media: Element) => {
        const mediaTriggerReaction = vi.fn();
        const mediaUnmount = vi.fn();
        triggerReactionByMedia.set(media, mediaTriggerReaction);
        unmountByMedia.set(media, mediaUnmount);

        return {
            element: document.createElement('div'),
            triggerReaction: (type: string) => {
                triggerReaction(type);
                mediaTriggerReaction(type);
            },
            unmount: () => {
                unmount();
                mediaUnmount();
            },
        };
    },
}));

function createRect(left: number, top: number, width: number, height: number): DOMRect {
    return {
        x: left,
        y: top,
        left,
        top,
        right: left + width,
        bottom: top + height,
        width,
        height,
        toJSON: () => ({}),
    } as DOMRect;
}

function appendTrackedImage(options?: {
    parentRect?: DOMRect;
    imageRect?: DOMRect;
    insideDialog?: boolean;
}): { image: HTMLImageElement; wrapper: HTMLDivElement } {
    const root = options?.insideDialog ? document.createElement('div') : document.body;
    if (options?.insideDialog) {
        root.setAttribute('role', 'dialog');
    }

    const wrapper = document.createElement('div');
    const image = document.createElement('img');
    wrapper.appendChild(image);
    root.appendChild(wrapper);
    if (root !== document.body) {
        document.body.appendChild(root);
    }

    Object.defineProperty(wrapper, 'getBoundingClientRect', {
        configurable: true,
        value: () => options?.parentRect ?? createRect(0, 0, 320, 240),
    });
    Object.defineProperty(image, 'getBoundingClientRect', {
        configurable: true,
        value: () => options?.imageRect ?? createRect(40, 50, 180, 120),
    });

    return { image, wrapper };
}

describe('OverlayManager', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        document.body.innerHTML = '';
    });

    it('ignores legacy L, D, and F keyboard shortcuts', async () => {
        const { OverlayManager } = await import('./overlay-manager');
        const manager = new OverlayManager();
        const { image } = appendTrackedImage();

        manager.apply(image);

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'l', bubbles: true, cancelable: true }));
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'd', bubbles: true, cancelable: true }));
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'f', bubbles: true, cancelable: true }));

        expect(triggerReaction).not.toHaveBeenCalled();

        manager.remove(image);
    });

    it('keeps Alt+click mouse reactions working', async () => {
        const { OverlayManager } = await import('./overlay-manager');
        const manager = new OverlayManager();
        const { image } = appendTrackedImage();

        manager.apply(image);

        const event = new MouseEvent('click', {
            altKey: true,
            bubbles: true,
            cancelable: true,
            button: 0,
            clientX: 80,
            clientY: 90,
        });
        image.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(triggerReaction).toHaveBeenCalledWith('like');

        manager.remove(image);
    });

    it('ignores Alt+contextmenu while keeping Alt+middle-click reactions working', async () => {
        const { OverlayManager } = await import('./overlay-manager');
        const manager = new OverlayManager();
        const { image } = appendTrackedImage();

        manager.apply(image);

        const contextmenuEvent = new MouseEvent('contextmenu', {
            altKey: true,
            bubbles: true,
            cancelable: true,
            clientX: 90,
            clientY: 100,
        });
        image.dispatchEvent(contextmenuEvent);

        const middleClickEvent = new MouseEvent('mousedown', {
            altKey: true,
            bubbles: true,
            cancelable: true,
            button: 1,
            clientX: 90,
            clientY: 100,
        });
        image.dispatchEvent(middleClickEvent);

        expect(contextmenuEvent.defaultPrevented).toBe(false);
        expect(middleClickEvent.defaultPrevented).toBe(true);
        expect(triggerReaction).toHaveBeenCalledTimes(1);
        expect(triggerReaction).toHaveBeenNthCalledWith(1, 'love');

        manager.remove(image);
    });

    it('prefers dialog media when multiple active media overlap at the pointer', async () => {
        const { OverlayManager } = await import('./overlay-manager');
        const manager = new OverlayManager();
        const regularImage = appendTrackedImage({
            parentRect: createRect(0, 0, 320, 240),
            imageRect: createRect(80, 80, 160, 120),
        }).image;
        const dialogImage = appendTrackedImage({
            insideDialog: true,
            parentRect: createRect(0, 0, 320, 240),
            imageRect: createRect(70, 70, 180, 140),
        }).image;

        manager.apply(regularImage);
        manager.apply(dialogImage);

        dialogImage.dispatchEvent(new MouseEvent('click', {
            altKey: true,
            bubbles: true,
            cancelable: true,
            button: 0,
            clientX: 120,
            clientY: 120,
        }));

        expect(triggerReactionByMedia.get(dialogImage)).toHaveBeenCalledWith('like');
        expect(triggerReactionByMedia.get(regularImage)).not.toHaveBeenCalled();

        manager.remove(regularImage);
        manager.remove(dialogImage);
    });

    it('pins the badge to the viewport when the media parent is collapsed', async () => {
        const { OverlayManager } = await import('./overlay-manager');
        const manager = new OverlayManager();
        const { image } = appendTrackedImage({
            parentRect: createRect(0, 0, 0, 0),
            imageRect: createRect(40, 50, 180, 120),
        });

        manager.apply(image);

        const badge = document.querySelector('[data-atlas-media-red-badge="1"]');
        expect(badge).toBeInstanceOf(HTMLDivElement);
        expect(badge?.parentElement).toBe(document.body);
        expect((badge as HTMLDivElement).style.position).toBe('fixed');
        expect((badge as HTMLDivElement).style.display).toBe('block');

        manager.remove(image);
        expect(unmountByMedia.get(image)).toHaveBeenCalledTimes(1);
    });
});
