import { effectScope, ref } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useBrowseGridAutoScrollShortcut } from './useBrowseGridAutoScrollShortcut';

function dispatchSpace(target: EventTarget = window, options: KeyboardEventInit = {}): KeyboardEvent {
    const event = new KeyboardEvent('keydown', {
        bubbles: true,
        cancelable: true,
        code: 'Space',
        key: ' ',
        ...options,
    });

    target.dispatchEvent(event);

    return event;
}

function dispatchAlt(type: 'keydown' | 'keyup', target: EventTarget = window, options: KeyboardEventInit = {}): KeyboardEvent {
    const event = new KeyboardEvent(type, {
        altKey: type === 'keydown',
        bubbles: true,
        cancelable: true,
        code: 'AltLeft',
        key: 'Alt',
        ...options,
    });

    target.dispatchEvent(event);

    return event;
}

function createShortcut(surfaceMode: 'fullscreen' | 'list' = 'list') {
    const pauseAutoScroll = vi.fn();
    const resumeAutoScroll = vi.fn();
    const toggleAutoScroll = vi.fn();
    const togglePageLoadingLock = vi.fn();
    const mode = ref(surfaceMode);
    const scope = effectScope();

    scope.run(() => {
        useBrowseGridAutoScrollShortcut({
            pauseAutoScroll,
            resumeAutoScroll,
            surfaceMode: mode,
            toggleAutoScroll,
            togglePageLoadingLock,
        });
    });

    return {
        mode,
        pauseAutoScroll,
        resumeAutoScroll,
        stop: () => scope.stop(),
        toggleAutoScroll,
        togglePageLoadingLock,
    };
}

describe('useBrowseGridAutoScrollShortcut', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('toggles auto-scroll with space while the grid surface is active', () => {
        const shortcut = createShortcut();
        const event = dispatchSpace();

        expect(shortcut.toggleAutoScroll).toHaveBeenCalledTimes(1);
        expect(event.defaultPrevented).toBe(true);

        shortcut.stop();
    });

    it('ignores space outside the grid surface', () => {
        const shortcut = createShortcut('fullscreen');
        const event = dispatchSpace();

        expect(shortcut.toggleAutoScroll).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(false);

        shortcut.stop();
    });

    it('leaves interactive targets and repeated keydown events alone', () => {
        const shortcut = createShortcut();
        const input = document.createElement('input');
        document.body.appendChild(input);

        const inputEvent = dispatchSpace(input);
        const repeatedEvent = dispatchSpace(window, { repeat: true });

        expect(shortcut.toggleAutoScroll).not.toHaveBeenCalled();
        expect(inputEvent.defaultPrevented).toBe(false);
        expect(repeatedEvent.defaultPrevented).toBe(false);

        shortcut.stop();
    });

    it('toggles the page-loading lock with alt+l while the grid surface is active', () => {
        const shortcut = createShortcut();
        const event = new KeyboardEvent('keydown', {
            altKey: true,
            bubbles: true,
            cancelable: true,
            code: 'KeyL',
            key: 'l',
        });

        window.dispatchEvent(event);

        expect(shortcut.togglePageLoadingLock).toHaveBeenCalledTimes(1);
        expect(shortcut.toggleAutoScroll).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(true);

        shortcut.stop();
    });

    it('temporarily pauses auto-scroll while alt is held', () => {
        const shortcut = createShortcut();
        const keydownEvent = dispatchAlt('keydown');
        const keyupEvent = dispatchAlt('keyup', window, { altKey: false });

        expect(shortcut.pauseAutoScroll).toHaveBeenCalledTimes(1);
        expect(shortcut.resumeAutoScroll).toHaveBeenCalledTimes(1);
        expect(shortcut.toggleAutoScroll).not.toHaveBeenCalled();
        expect(keydownEvent.defaultPrevented).toBe(true);
        expect(keyupEvent.defaultPrevented).toBe(true);

        shortcut.stop();
    });

    it('pauses globally in list mode and ignores alt pause outside list mode', () => {
        const fullscreenShortcut = createShortcut('fullscreen');
        const fullscreenEvent = dispatchAlt('keydown');
        fullscreenShortcut.stop();

        const shortcut = createShortcut();
        const input = document.createElement('input');
        document.body.appendChild(input);
        const inputEvent = dispatchAlt('keydown', input);

        expect(fullscreenShortcut.pauseAutoScroll).not.toHaveBeenCalled();
        expect(fullscreenEvent.defaultPrevented).toBe(false);
        expect(shortcut.pauseAutoScroll).toHaveBeenCalledTimes(1);
        expect(inputEvent.defaultPrevented).toBe(true);

        shortcut.stop();
    });

    it('ignores alt+l outside the grid surface', () => {
        const shortcut = createShortcut('fullscreen');
        const fullscreenEvent = new KeyboardEvent('keydown', {
            altKey: true,
            bubbles: true,
            cancelable: true,
            code: 'KeyL',
            key: 'l',
        });

        window.dispatchEvent(fullscreenEvent);

        expect(shortcut.togglePageLoadingLock).not.toHaveBeenCalled();
        expect(fullscreenEvent.defaultPrevented).toBe(false);

        shortcut.stop();
    });

    it('ignores alt+l inside inputs', () => {
        const shortcut = createShortcut();
        const input = document.createElement('input');
        document.body.appendChild(input);
        const event = new KeyboardEvent('keydown', {
            altKey: true,
            bubbles: true,
            cancelable: true,
            code: 'KeyL',
            key: 'l',
        });

        input.dispatchEvent(event);

        expect(shortcut.togglePageLoadingLock).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(false);

        shortcut.stop();
    });
});
