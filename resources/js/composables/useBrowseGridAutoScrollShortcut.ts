import { useEventListener } from '@vueuse/core';
import type { ComputedRef, Ref } from 'vue';

type SurfaceMode = 'fullscreen' | 'list';

export function useBrowseGridAutoScrollShortcut(options: {
    surfaceMode: ComputedRef<SurfaceMode> | Ref<SurfaceMode>;
    toggleAutoScroll: () => void;
    togglePageLoadingLock?: () => void;
}): void {
    useEventListener('keydown', (event) => {
        if (shouldTogglePageLoadingLock(event, options.surfaceMode.value)) {
            event.preventDefault();
            options.togglePageLoadingLock?.();
            return;
        }

        if (!shouldToggleAutoScroll(event, options.surfaceMode.value)) {
            return;
        }

        event.preventDefault();
        options.toggleAutoScroll();
    });
}

function shouldTogglePageLoadingLock(event: KeyboardEvent, surfaceMode: SurfaceMode): boolean {
    if (
        event.defaultPrevented
        || event.repeat
        || surfaceMode !== 'list'
        || !event.altKey
        || event.ctrlKey
        || event.metaKey
        || event.shiftKey
        || !isKeyboardL(event)
    ) {
        return false;
    }

    return !isInteractiveTarget(event.target);
}

function shouldToggleAutoScroll(event: KeyboardEvent, surfaceMode: SurfaceMode): boolean {
    if (
        event.defaultPrevented
        || event.repeat
        || surfaceMode !== 'list'
        || event.altKey
        || event.ctrlKey
        || event.metaKey
        || event.shiftKey
    ) {
        return false;
    }

    if (event.code !== 'Space' && event.key !== ' ' && event.key !== 'Spacebar') {
        return false;
    }

    return !isInteractiveTarget(event.target);
}

function isKeyboardL(event: KeyboardEvent): boolean {
    return event.code === 'KeyL' || event.key.toLowerCase() === 'l';
}

function isInteractiveTarget(target: EventTarget | null): boolean {
    if (!(target instanceof Element)) {
        return false;
    }

    return target.closest('input, textarea, select, button, a[href], audio, video, [role="button"], [contenteditable="true"]') !== null;
}
