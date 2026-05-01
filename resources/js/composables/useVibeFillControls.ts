import { computed, onBeforeUnmount, ref, watch, type ComputedRef, type Ref } from 'vue';
import type { VibeHandle, VibeStatus } from '@wyxos/vibe';

export const AUTO_SCROLL_SPEED_MAX = 150;
export const AUTO_SCROLL_SPEED_MIN = 20;
export const DEFAULT_AUTO_SCROLL_SPEED = 50;
export const DEFAULT_FILL_CALL_COUNT = 10;
export const FILL_CALL_COUNT_MAX = 999;
export const FILL_CALL_COUNT_MIN = 1;

type SurfaceMode = 'fullscreen' | 'list';
type VibeFillHandle = Pick<VibeHandle, 'autoScroll' | 'cancelFill' | 'fillUntil' | 'fillUntilEnd'>;

export function useVibeFillControls(options: {
    getVibeHandle: () => VibeFillHandle | null;
    status: ComputedRef<VibeStatus> | Ref<VibeStatus>;
    surfaceMode: ComputedRef<SurfaceMode> | Ref<SurfaceMode>;
}) {
    const autoScrollActive = ref(false);
    const autoScrollSpeed = ref(DEFAULT_AUTO_SCROLL_SPEED);
    const fillCallCount = ref(DEFAULT_FILL_CALL_COUNT);
    const fillActionsDisabled = computed(() => {
        const status = options.status.value;

        return status.fillMode !== 'idle'
            || status.loadState === 'loading'
            || status.pageLoadingLocked
            || status.phase === 'failed'
            || status.phase === 'filling'
            || status.phase === 'initializing'
            || status.phase === 'loading'
            || status.phase === 'refreshing';
    });

    function setFillCallCount(value: number): void {
        fillCallCount.value = clampInteger(value, FILL_CALL_COUNT_MIN, FILL_CALL_COUNT_MAX);
    }

    function fillUntilCount(): void {
        if (fillActionsDisabled.value) {
            return;
        }

        void options.getVibeHandle()?.fillUntil(fillCallCount.value);
    }

    function fillUntilEnd(): void {
        if (fillActionsDisabled.value) {
            return;
        }

        void options.getVibeHandle()?.fillUntilEnd();
    }

    function cancelFill(): void {
        options.getVibeHandle()?.cancelFill();
    }

    function setAutoScrollSpeed(value: number): void {
        autoScrollSpeed.value = clampInteger(value, AUTO_SCROLL_SPEED_MIN, AUTO_SCROLL_SPEED_MAX);

        if (autoScrollActive.value) {
            options.getVibeHandle()?.autoScroll(autoScrollSpeed.value);
        }
    }

    function stopAutoScroll(): void {
        if (!autoScrollActive.value) {
            return;
        }

        options.getVibeHandle()?.autoScroll(0);
        autoScrollActive.value = false;
    }

    function toggleAutoScroll(): void {
        if (autoScrollActive.value) {
            stopAutoScroll();
            return;
        }

        if (options.surfaceMode.value !== 'list') {
            return;
        }

        options.getVibeHandle()?.autoScroll(autoScrollSpeed.value);
        autoScrollActive.value = true;
    }

    watch(
        () => options.surfaceMode.value,
        (mode) => {
            if (mode !== 'list') {
                stopAutoScroll();
            }
        },
    );

    onBeforeUnmount(() => {
        cancelFill();
        stopAutoScroll();
    });

    return {
        autoScrollActive,
        autoScrollSpeed,
        cancelFill,
        fillActionsDisabled,
        fillCallCount,
        fillUntilCount,
        fillUntilEnd,
        setAutoScrollSpeed,
        setFillCallCount,
        stopAutoScroll,
        toggleAutoScroll,
    };
}

function clampInteger(value: number, min: number, max: number): number {
    const numeric = Number(value);

    if (!Number.isFinite(numeric)) {
        return min;
    }

    return Math.min(Math.max(Math.round(numeric), min), max);
}
