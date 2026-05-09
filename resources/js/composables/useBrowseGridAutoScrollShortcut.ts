import { useEventListener } from '@vueuse/core';
import type { ComputedRef, Ref } from 'vue';

type SurfaceMode = 'fullscreen' | 'list';

export function useBrowseGridAutoScrollShortcut(options: {
    pauseAutoScroll?: () => void;
    resumeAutoScroll?: () => void;
    surfaceMode: ComputedRef<SurfaceMode> | Ref<SurfaceMode>;
    toggleAutoScroll: () => void;
    togglePageLoadingLock?: () => void;
}): void {
    useEventListener('keydown', (event) => {
        if (options.pauseAutoScroll && shouldPauseAutoScroll(event, options.surfaceMode.value)) {
            event.preventDefault();
            options.pauseAutoScroll();
            return;
        }

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

    useEventListener('keyup', (event) => {
        if (!options.resumeAutoScroll || !shouldResumeAutoScroll(event, options.surfaceMode.value)) {
            return;
        }

        event.preventDefault();
        options.resumeAutoScroll();
    });

    useEventListener('blur', () => {
        options.resumeAutoScroll?.();
    });
}

function shouldPauseAutoScroll(event: KeyboardEvent, surfaceMode: SurfaceMode): boolean {
    if (
        event.defaultPrevented
        || event.repeat
        || surfaceMode !== 'list'
        || !isAltKey(event)
        || event.ctrlKey
        || event.metaKey
        || event.shiftKey
    ) {
        return false;
    }

    return true;
}

function shouldResumeAutoScroll(event: KeyboardEvent, surfaceMode: SurfaceMode): boolean {
    if (
        event.defaultPrevented
        || surfaceMode !== 'list'
        || !isAltKey(event)
    ) {
        return false;
    }

    return true;
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

function isAltKey(event: KeyboardEvent): boolean {
    return event.code === 'AltLeft' || event.code === 'AltRight' || event.key === 'Alt';
}

function isInteractiveTarget(target: EventTarget | null): boolean {
    if (!(target instanceof Element)) {
        return false;
    }

    return target.closest('input, textarea, select, button, a[href], audio, video, [role="button"], [contenteditable="true"]') !== null;
}
