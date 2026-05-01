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

function createShortcut(surfaceMode: 'fullscreen' | 'list' = 'list') {
    const toggleAutoScroll = vi.fn();
    const mode = ref(surfaceMode);
    const scope = effectScope();

    scope.run(() => {
        useBrowseGridAutoScrollShortcut({
            surfaceMode: mode,
            toggleAutoScroll,
        });
    });

    return {
        mode,
        stop: () => scope.stop(),
        toggleAutoScroll,
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
});
