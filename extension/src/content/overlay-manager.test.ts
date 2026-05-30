import { beforeEach, describe, expect, it, vi } from 'vitest';

const triggerReaction = vi.fn();
const unmount = vi.fn();
const triggerReactionByMedia = new WeakMap<Element, ReturnType<typeof vi.fn>>();
const unmountByMedia = new WeakMap<Element, ReturnType<typeof vi.fn>>();
const refreshCheckByMedia = new WeakMap<Element, ReturnType<typeof vi.fn>>();
const initialRefreshOptionsByMedia = new WeakMap<Element, unknown>();

vi.mock('./reaction-badge-app', () => ({
    createReactionBadgeHost: (media: Element, initialRefreshOptions?: unknown) => {
        const mediaTriggerReaction = vi.fn();
        const mediaUnmount = vi.fn();
        const mediaRefreshCheck = vi.fn();
        triggerReactionByMedia.set(media, mediaTriggerReaction);
        unmountByMedia.set(media, mediaUnmount);
        refreshCheckByMedia.set(media, mediaRefreshCheck);
        initialRefreshOptionsByMedia.set(media, initialRefreshOptions);

        return {
            element: document.createElement('div'),
            triggerReaction: (type: string) => {
                triggerReaction(type);
                mediaTriggerReaction(type);
            },
            refreshCheck: mediaRefreshCheck,
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

function appendTrackedVideo(options?: {
    parentRect?: DOMRect;
    videoRect?: DOMRect;
}): { video: HTMLVideoElement; wrapper: HTMLDivElement } {
    const wrapper = document.createElement('div');
    const video = document.createElement('video');
    wrapper.appendChild(video);
    document.body.appendChild(wrapper);

    Object.defineProperty(wrapper, 'getBoundingClientRect', {
        configurable: true,
        value: () => options?.parentRect ?? createRect(0, 0, 320, 240),
    });
    Object.defineProperty(video, 'getBoundingClientRect', {
        configurable: true,
        value: () => options?.videoRect ?? createRect(40, 50, 180, 120),
    });

    return { video, wrapper };
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

    it('passes forced check options into new badges and refreshes existing visible badges', async () => {
        const { OverlayManager } = await import('./overlay-manager');
        const manager = new OverlayManager();
        const { image } = appendTrackedImage({
            imageRect: createRect(40, 50, 180, 120),
        });

        manager.apply(image, { refreshCheck: { bypassCheckCache: true } });

        expect(initialRefreshOptionsByMedia.get(image)).toEqual({ bypassCheckCache: true });
        expect(refreshCheckByMedia.get(image)).not.toHaveBeenCalled();

        manager.apply(image, { refreshCheck: { bypassCheckCache: true } });
        expect(refreshCheckByMedia.get(image)).toHaveBeenCalledWith({ bypassCheckCache: true });

        refreshCheckByMedia.get(image)?.mockClear();
        expect(manager.refreshVisibleChecks({ bypassCheckCache: true })).toBe(1);
        expect(refreshCheckByMedia.get(image)).toHaveBeenCalledWith({ bypassCheckCache: true });

        manager.remove(image);
    });

    it('favorites media on Alt+left click', async () => {
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
        expect(triggerReaction).toHaveBeenCalledWith('love');

        manager.remove(image);
    });

    it('likes media on Alt+middle click', async () => {
        const { OverlayManager } = await import('./overlay-manager');
        const manager = new OverlayManager();
        const { image } = appendTrackedImage();

        manager.apply(image);

        const middleClickEvent = new MouseEvent('mousedown', {
            altKey: true,
            bubbles: true,
            cancelable: true,
            button: 1,
            clientX: 90,
            clientY: 100,
        });
        image.dispatchEvent(middleClickEvent);

        expect(middleClickEvent.defaultPrevented).toBe(true);
        expect(triggerReaction).toHaveBeenCalledTimes(1);
        expect(triggerReaction).toHaveBeenNthCalledWith(1, 'like');

        manager.remove(image);
    });

    it('blacklists media on Alt+right click', async () => {
        const { OverlayManager } = await import('./overlay-manager');
        const manager = new OverlayManager();
        const { image } = appendTrackedImage();

        manager.apply(image);

        const contextmenuEvent = new MouseEvent('contextmenu', {
            altKey: true,
            bubbles: true,
            cancelable: true,
            button: 2,
            clientX: 90,
            clientY: 100,
        });
        image.dispatchEvent(contextmenuEvent);

        expect(contextmenuEvent.defaultPrevented).toBe(true);
        expect(triggerReaction).toHaveBeenCalledTimes(1);
        expect(triggerReaction).toHaveBeenNthCalledWith(1, 'blacklist');

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

        expect(triggerReactionByMedia.get(dialogImage)).toHaveBeenCalledWith('love');
        expect(triggerReactionByMedia.get(regularImage)).not.toHaveBeenCalled();

        manager.remove(regularImage);
        manager.remove(dialogImage);
    });

    it('prefers a video over an overlapping poster image', async () => {
        const { OverlayManager } = await import('./overlay-manager');
        const manager = new OverlayManager();
        const image = appendTrackedImage({
            imageRect: createRect(10, 20, 640, 360),
        }).image;
        const video = appendTrackedVideo({
            videoRect: createRect(10, 20, 640, 360),
        }).video;

        manager.apply(image);
        manager.apply(video);

        expect(document.querySelectorAll('[data-atlas-media-red-badge="1"]')).toHaveLength(1);
        expect(image.getAttribute('data-atlas-media-red-applied')).toBeNull();
        expect(video.getAttribute('data-atlas-media-red-applied')).toBe('1');
        expect(unmountByMedia.get(image)).toHaveBeenCalledTimes(1);
        expect(unmountByMedia.get(video)).not.toHaveBeenCalled();

        manager.remove(video);
    });

    it('does not apply an overlapping poster image after the video already has a badge', async () => {
        const { OverlayManager } = await import('./overlay-manager');
        const manager = new OverlayManager();
        const video = appendTrackedVideo({
            videoRect: createRect(10, 20, 640, 360),
        }).video;
        const image = appendTrackedImage({
            imageRect: createRect(10, 20, 640, 360),
        }).image;

        manager.apply(video);
        manager.apply(image);

        expect(document.querySelectorAll('[data-atlas-media-red-badge="1"]')).toHaveLength(1);
        expect(video.getAttribute('data-atlas-media-red-applied')).toBe('1');
        expect(image.getAttribute('data-atlas-media-red-applied')).toBeNull();
        expect(unmountByMedia.get(video)).not.toHaveBeenCalled();
        expect(unmountByMedia.get(image)).toBeUndefined();

        manager.remove(video);
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

    it('pins the badge to the viewport when media is a shadow-root child', async () => {
        const { OverlayManager } = await import('./overlay-manager');
        const manager = new OverlayManager();
        const host = document.createElement('shreddit-player');
        const shadowRoot = host.attachShadow({ mode: 'open' });
        const video = document.createElement('video');
        shadowRoot.appendChild(video);
        document.body.appendChild(host);

        Object.defineProperty(video, 'getBoundingClientRect', {
            configurable: true,
            value: () => createRect(120, 140, 320, 180),
        });

        manager.apply(video);

        const badge = document.querySelector('[data-atlas-media-red-badge="1"]');
        expect(badge).toBeInstanceOf(HTMLDivElement);
        expect(badge?.parentElement).toBe(document.body);
        expect((badge as HTMLDivElement).style.position).toBe('fixed');
        expect((badge as HTMLDivElement).style.display).toBe('block');

        manager.remove(video);
        expect(unmountByMedia.get(video)).toHaveBeenCalledTimes(1);
    });
});
