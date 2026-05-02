import { mount } from '@vue/test-utils';
import { defineComponent, h, nextTick, ref } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import type { VibeStatus } from '@wyxos/vibe';
import { useVibeFillControls } from './useVibeFillControls';

function createStatus(overrides: Partial<VibeStatus> = {}): VibeStatus {
    return {
        activeIndex: 0,
        currentCursor: '1',
        errorMessage: null,
        fillCollectedCount: null,
        fillCompletedCalls: 0,
        fillCursor: null,
        fillDelayRemainingMs: null,
        fillLoadedCount: 20,
        fillMode: 'idle',
        fillProgress: null,
        fillTargetCalls: null,
        fillTargetCount: null,
        fillTotalCount: null,
        hasNextPage: true,
        hasPreviousPage: false,
        itemCount: 20,
        loadState: 'loaded',
        nextBoundaryLoadProgress: 0,
        nextCursor: '2',
        pageLoadingLocked: false,
        phase: 'idle',
        previousBoundaryLoadProgress: 0,
        previousCursor: null,
        removedCount: 0,
        removedIds: [],
        surfaceMode: 'list',
        ...overrides,
    };
}

function mountControls() {
    const handle = {
        autoScroll: vi.fn(),
        cancelFill: vi.fn(),
        fillUntil: vi.fn(async () => undefined),
        fillUntilEnd: vi.fn(async () => undefined),
    };
    const status = ref(createStatus());
    const surfaceMode = ref<'fullscreen' | 'list'>('list');
    const wrapper = mount(defineComponent({
        setup(_, { expose }) {
            const controls = useVibeFillControls({
                getVibeHandle: () => handle,
                status,
                surfaceMode,
            });

            expose({ controls });
            return () => h('div');
        },
    }));

    return {
        controls: (wrapper.vm as unknown as { controls: ReturnType<typeof useVibeFillControls> }).controls,
        handle,
        status,
        surfaceMode,
        wrapper,
    };
}

describe('useVibeFillControls', () => {
    it('routes fill count and fill-to-end through the Vibe handle', () => {
        const { controls, handle, status } = mountControls();

        controls.setFillCallCount(1500);
        controls.fillUntilCount();
        controls.fillUntilEnd();

        expect(controls.fillCallCount.value).toBe(999);
        expect(handle.fillUntil).toHaveBeenCalledWith(999);
        expect(handle.fillUntilEnd).toHaveBeenCalledTimes(1);

        status.value = createStatus({ pageLoadingLocked: true });
        controls.fillUntilCount();

        expect(handle.fillUntil).toHaveBeenCalledTimes(1);
    });

    it('routes auto-scroll through the Vibe handle and stops outside list mode', async () => {
        const { controls, handle, surfaceMode } = mountControls();

        controls.setAutoScrollSpeed(8);
        controls.toggleAutoScroll();

        expect(controls.autoScrollSpeed.value).toBe(20);
        expect(handle.autoScroll).toHaveBeenLastCalledWith(20);
        expect(controls.autoScrollActive.value).toBe(true);

        controls.setAutoScrollSpeed(301);

        expect(controls.autoScrollSpeed.value).toBe(300);
        expect(handle.autoScroll).toHaveBeenLastCalledWith(300);

        surfaceMode.value = 'fullscreen';
        await nextTick();

        expect(handle.autoScroll).toHaveBeenLastCalledWith(0);
        expect(controls.autoScrollActive.value).toBe(false);
    });

    it('cancels fill and stops auto-scroll on unmount', () => {
        const { controls, handle, wrapper } = mountControls();

        controls.toggleAutoScroll();
        wrapper.unmount();

        expect(handle.cancelFill).toHaveBeenCalledTimes(1);
        expect(handle.autoScroll).toHaveBeenLastCalledWith(0);
    });
});
