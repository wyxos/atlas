import { useEventListener } from '@vueuse/core';
import type { ComputedRef, Ref } from 'vue';

type SurfaceMode = 'fullscreen' | 'list';

export function useBrowseGridAutoScrollShortcut(options: {
    surfaceMode: ComputedRef<SurfaceMode> | Ref<SurfaceMode>;
    toggleAutoScroll: () => void;
}): void {
    useEventListener('keydown', (event) => {
        if (!shouldToggleAutoScroll(event, options.surfaceMode.value)) {
            return;
        }

        event.preventDefault();
        options.toggleAutoScroll();
    });
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

function isInteractiveTarget(target: EventTarget | null): boolean {
    if (!(target instanceof Element)) {
        return false;
    }

    return target.closest('input, textarea, select, button, a[href], audio, video, [role="button"], [contenteditable="true"]') !== null;
}
