import { beforeEach, describe, expect, it, vi } from 'vitest';

const triggerReaction = vi.fn();
const unmount = vi.fn();

vi.mock('./reaction-badge-app', () => ({
    createReactionBadgeHost: () => ({
        element: document.createElement('div'),
        triggerReaction,
        unmount,
    }),
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

function appendTrackedImage(): { image: HTMLImageElement; wrapper: HTMLDivElement } {
    const wrapper = document.createElement('div');
    const image = document.createElement('img');
    wrapper.appendChild(image);
    document.body.appendChild(wrapper);

    Object.defineProperty(wrapper, 'getBoundingClientRect', {
        configurable: true,
        value: () => createRect(0, 0, 320, 240),
    });
    Object.defineProperty(image, 'getBoundingClientRect', {
        configurable: true,
        value: () => createRect(40, 50, 180, 120),
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
});
