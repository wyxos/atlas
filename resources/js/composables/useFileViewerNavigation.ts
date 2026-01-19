import { onUnmounted, ref, watch, type Ref } from 'vue';

export function useFileViewerNavigation(params: {
    overlayRect: Ref<{ top: number; left: number; width: number; height: number } | null>;
    overlayFillComplete: Ref<boolean>;
    overlayIsClosing: Ref<boolean>;
    onClose: () => void;
    onNext: () => void;
    onPrevious: () => void;
    onFullscreenChange?: () => void;
    swipeThreshold?: number;
    wheelThreshold?: number;
    navThrottleMs?: number;
}) {
    const swipeStart = ref<{ x: number; y: number } | null>(null);
    const lastWheelAt = ref(0);
    const lastSwipeAt = ref(0);
    const swipeThreshold = params.swipeThreshold ?? 60;
    const wheelThreshold = params.wheelThreshold ?? 40;
    const navThrottleMs = params.navThrottleMs ?? 400;

    let isHandlingMouseNavigation = false;
    let overlayStatePushed = false;

    function shouldIgnoreGesture(target: EventTarget | null): boolean {
        const el = target as HTMLElement | null;
        if (!el) {
            return false;
        }
        return Boolean(el.closest('button, input, textarea, select, a, .file-viewer-video-slider'));
    }

    function handleWheel(e: WheelEvent): void {
        if (!params.overlayRect.value || !params.overlayFillComplete.value || params.overlayIsClosing.value) return;
        if (shouldIgnoreGesture(e.target)) return;

        const now = Date.now();
        if (now - lastWheelAt.value < navThrottleMs) return;

        const deltaY = e.deltaY;
        if (Math.abs(deltaY) < wheelThreshold) return;

        e.preventDefault();
        lastWheelAt.value = now;

        if (deltaY > 0) {
            params.onNext();
        } else {
            params.onPrevious();
        }
    }

    function handleTouchStart(e: TouchEvent): void {
        if (!params.overlayRect.value || !params.overlayFillComplete.value || params.overlayIsClosing.value) return;
        if (shouldIgnoreGesture(e.target)) return;

        const touch = e.touches[0];
        if (!touch) return;
        swipeStart.value = { x: touch.clientX, y: touch.clientY };
    }

    function handleTouchEnd(e: TouchEvent): void {
        if (!params.overlayRect.value || !params.overlayFillComplete.value || params.overlayIsClosing.value) return;
        if (shouldIgnoreGesture(e.target)) return;

        const start = swipeStart.value;
        swipeStart.value = null;
        if (!start) return;

        const touch = e.changedTouches[0];
        if (!touch) return;

        const deltaX = touch.clientX - start.x;
        const deltaY = touch.clientY - start.y;
        if (Math.abs(deltaY) < swipeThreshold) return;
        if (Math.abs(deltaY) < Math.abs(deltaX)) return;

        const now = Date.now();
        if (now - lastSwipeAt.value < navThrottleMs) return;
        lastSwipeAt.value = now;

        if (deltaY < 0) {
            params.onNext();
        } else {
            params.onPrevious();
        }
    }

    function handleKeyDown(e: KeyboardEvent): void {
        if (!params.overlayRect.value || params.overlayIsClosing.value) return;

        if (e.key === 'Escape') {
            params.onClose();
        } else if (e.key === 'ArrowDown' || e.key === 'ArrowRight') {
            e.preventDefault();
            params.onNext();
        } else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') {
            e.preventDefault();
            params.onPrevious();
        }
    }

    function handleMouseButton(e: MouseEvent): void {
        if (!params.overlayRect.value || !params.overlayFillComplete.value || params.overlayIsClosing.value) return;

        if (e.button === 3) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            isHandlingMouseNavigation = true;
            params.onPrevious();
            setTimeout(() => {
                isHandlingMouseNavigation = false;
            }, 100);
        } else if (e.button === 4) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            isHandlingMouseNavigation = true;
            params.onNext();
            setTimeout(() => {
                isHandlingMouseNavigation = false;
            }, 100);
        }
    }

    function handlePopState(): void {
        if (isHandlingMouseNavigation || (params.overlayRect.value && params.overlayFillComplete.value && !params.overlayIsClosing.value)) {
            history.pushState({ preventBack: true }, '', window.location.href);
        }
    }

    watch(() => params.overlayRect.value !== null && params.overlayFillComplete.value, (isVisible) => {
        if (isVisible) {
            if (!overlayStatePushed) {
                history.pushState({ fileViewerOpen: true }, '', window.location.href);
                overlayStatePushed = true;
            }
            window.addEventListener('keydown', handleKeyDown);
            window.addEventListener('wheel', handleWheel, { passive: false });
            document.addEventListener('mousedown', handleMouseButton, true);
            document.addEventListener('mouseup', handleMouseButton, true);
            document.addEventListener('auxclick', handleMouseButton, true);
            window.addEventListener('popstate', handlePopState);
            if (params.onFullscreenChange) {
                document.addEventListener('fullscreenchange', params.onFullscreenChange);
            }
        } else {
            overlayStatePushed = false;
            window.removeEventListener('keydown', handleKeyDown);
            window.removeEventListener('wheel', handleWheel);
            document.removeEventListener('mousedown', handleMouseButton, true);
            document.removeEventListener('mouseup', handleMouseButton, true);
            document.removeEventListener('auxclick', handleMouseButton, true);
            window.removeEventListener('popstate', handlePopState);
            if (params.onFullscreenChange) {
                document.removeEventListener('fullscreenchange', params.onFullscreenChange);
            }
        }
    }, { immediate: true });

    onUnmounted(() => {
        window.removeEventListener('keydown', handleKeyDown);
        window.removeEventListener('wheel', handleWheel);
        document.removeEventListener('mousedown', handleMouseButton, true);
        document.removeEventListener('mouseup', handleMouseButton, true);
        document.removeEventListener('auxclick', handleMouseButton, true);
        window.removeEventListener('popstate', handlePopState);
        if (params.onFullscreenChange) {
            document.removeEventListener('fullscreenchange', params.onFullscreenChange);
        }
    });

    return {
        handleTouchStart,
        handleTouchEnd,
    };
}
