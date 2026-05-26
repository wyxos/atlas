import { mount } from '@vue/test-utils';
import { defineComponent, h, nextTick, ref } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import BrowseV2StatusBar from './BrowseV2StatusBar.vue';

vi.mock('lucide-vue-next', () => {
    const icon = (name: string, testId: string) => defineComponent({
        name,
        render() {
            return h('div', { 'data-testid': testId });
        },
    });

    return {
        ChevronsDown: icon('MockChevronsDownIcon', 'chevrons-down-icon'),
        ListPlus: icon('MockListPlusIcon', 'list-plus-icon'),
        Loader2: icon('MockLoaderIcon', 'loader-icon'),
        LockKeyhole: icon('MockLockKeyholeIcon', 'lock-keyhole-icon'),
        LockKeyholeOpen: icon('MockLockKeyholeOpenIcon', 'lock-keyhole-open-icon'),
        Minus: icon('MockMinusIcon', 'minus-icon'),
        Pause: icon('MockPauseIcon', 'pause-icon'),
        Play: icon('MockPlayIcon', 'play-icon'),
        Plus: icon('MockPlusIcon', 'plus-icon'),
        X: icon('MockXIcon', 'x-icon'),
    };
});

vi.mock('@/components/ui/button', () => ({
    Button: defineComponent({
        name: 'MockButton',
        props: {
            disabled: { type: Boolean, default: false },
        },
        setup(props, { attrs, slots }) {
            return () => h('button', {
                ...attrs,
                disabled: props.disabled,
            }, slots.default?.());
        },
    }),
}));

vi.mock('./ui/Pill.vue', () => ({
    default: defineComponent({
        name: 'MockPill',
        props: {
            label: { type: String, required: true },
            value: { type: [String, Number], default: null },
            variant: { type: String, default: 'neutral' },
        },
        setup(props, { slots }) {
            return () => h('div', {
                'data-testid': `${props.label.toLowerCase()}-pill`,
                'data-variant': props.variant,
            }, [
                h('span', props.label),
                slots.value ? slots.value() : String(props.value ?? ''),
            ]);
        },
    }),
}));

afterEach(() => {
    document.body.innerHTML = '';
});

function createStatus(overrides: Partial<InstanceType<typeof BrowseV2StatusBar>['$props']['status']> = {}) {
    return {
        currentCursor: '1',
        errorMessage: null,
        fillCollectedCount: null,
        fillCompletedCalls: 0,
        fillCursor: null,
        fillDelayRemainingMs: null,
        fillLoadedCount: 20,
        fillMode: 'idle' as const,
        fillProgress: null,
        fillTargetCalls: null,
        fillTargetCount: null,
        fillTotalCount: null,
        hasNextPage: true,
        itemCount: 20,
        loadState: 'loaded' as const,
        nextBoundaryLoadProgress: 0,
        nextCursor: '2',
        pageLoadingLocked: false,
        phase: 'idle' as const,
        previousBoundaryLoadProgress: 0,
        previousCursor: null,
        ...overrides,
    };
}

describe('BrowseV2StatusBar fill controls', () => {
    it('shows fillUntil count progress when Vibe exposes a target call count', () => {
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus({
                    phase: 'filling',
                    fillCompletedCalls: 1,
                    fillMode: 'count',
                    fillTargetCalls: 2,
                }),
            },
        });

        expect(wrapper.get('[data-testid="browse-v2-status-pill"]').text()).toContain('Filling 1/2 calls');
    });

    it('routes the cancel fill action through the provided handler while a fill is active', async () => {
        const cancelFill = vi.fn();
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                cancelFill,
                status: createStatus({
                    phase: 'filling',
                    fillCompletedCalls: 1,
                    fillMode: 'count',
                    fillTargetCalls: 2,
                }),
            },
        });

        await wrapper.get('[data-test="cancel-fill-button"]').trigger('click');

        expect(cancelFill).toHaveBeenCalledTimes(1);
        expect(wrapper.find('[data-testid="x-icon"]').exists()).toBe(true);
    });

    it('routes fill controls through Vibe handlers and clamps count input', async () => {
        const fillUntilCount = vi.fn();
        const fillUntilEnd = vi.fn();
        const setFillCallCount = vi.fn();
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                fillCallCount: 10,
                fillCallCountMax: 25,
                fillCallCountMin: 1,
                fillUntilCount,
                fillUntilEnd,
                setFillCallCount,
                status: createStatus(),
            },
        });

        await wrapper.get('[data-test="fill-call-count-input"]').setValue('30');
        expect(setFillCallCount).not.toHaveBeenCalled();

        await wrapper.get('[data-test="fill-call-count-input"]').trigger('blur');
        await wrapper.get('[data-test="fill-count-button"]').trigger('click');
        await wrapper.get('[data-test="fill-until-end-button"]').trigger('click');

        expect(setFillCallCount).toHaveBeenCalledWith(25);
        expect(wrapper.get('[data-test="fill-call-count-input"]').attributes('type')).toBe('text');
        expect(fillUntilCount).toHaveBeenCalledTimes(1);
        expect(fillUntilEnd).toHaveBeenCalledTimes(1);
        expect(wrapper.find('[data-testid="list-plus-icon"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="chevrons-down-icon"]').exists()).toBe(true);
    });

    it('submits fill until when pressing Enter in the fill call count input', async () => {
        const fillUntilCount = vi.fn();
        const setFillCallCount = vi.fn();
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                fillCallCount: 1,
                fillCallCountMax: 6,
                fillCallCountMin: 1,
                fillUntilCount,
                setFillCallCount,
                status: createStatus(),
            },
        });

        await wrapper.get('[data-test="fill-call-count-input"]').setValue('2');
        await wrapper.get('[data-test="fill-call-count-input"]').trigger('keydown.enter');

        expect(setFillCallCount).toHaveBeenCalledWith(2);
        expect(fillUntilCount).toHaveBeenCalledTimes(1);
    });

    it('blurs fill steppers after mouse activation so Space remains available for global shortcuts', async () => {
        const setFillCallCount = vi.fn();
        const wrapper = mount(BrowseV2StatusBar, {
            attachTo: document.body,
            props: {
                fillCallCount: 1,
                fillCallCountMax: 6,
                fillCallCountMin: 1,
                fillUntilCount: vi.fn(),
                setFillCallCount,
                status: createStatus(),
            },
        });

        const increaseButton = wrapper.get('button[aria-label="Increase Fill call count"]');
        (increaseButton.element as HTMLButtonElement).focus();

        expect(document.activeElement).toBe(increaseButton.element);

        increaseButton.element.dispatchEvent(new MouseEvent('click', {
            bubbles: true,
            cancelable: true,
            detail: 1,
        }));
        await nextTick();

        expect(setFillCallCount).toHaveBeenCalledWith(2);
        expect(document.activeElement).not.toBe(increaseButton.element);
    });

    it('blurs the page-loading lock after mouse activation when the lock state re-renders', async () => {
        const togglePageLoadingLock = vi.fn();
        const Host = defineComponent({
            setup() {
                const pageLoadingLocked = ref(false);

                return () => h(BrowseV2StatusBar, {
                    status: createStatus(),
                    canTogglePageLoadingLock: true,
                    pageLoadingLocked: pageLoadingLocked.value,
                    togglePageLoadingLock: () => {
                        togglePageLoadingLock();
                        pageLoadingLocked.value = !pageLoadingLocked.value;
                    },
                });
            },
        });
        const wrapper = mount(Host, { attachTo: document.body });

        const lockButton = wrapper.get('[data-test="page-loading-lock-button"]');
        (lockButton.element as HTMLButtonElement).focus();

        expect(document.activeElement).toBe(lockButton.element);

        lockButton.element.dispatchEvent(new MouseEvent('click', {
            bubbles: true,
            cancelable: true,
            detail: 1,
        }));
        await nextTick();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(togglePageLoadingLock).toHaveBeenCalledTimes(1);
        expect(document.activeElement?.getAttribute('data-test')).not.toBe('page-loading-lock-button');
    });

    it('disables fill controls while Vibe is filling', () => {
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                fillUntilCount: vi.fn(),
                fillUntilEnd: vi.fn(),
                setFillCallCount: vi.fn(),
                status: createStatus({
                    fillMode: 'count',
                    loadState: 'loading',
                    pageLoadingLocked: true,
                    phase: 'filling',
                }),
            },
        });

        expect(wrapper.get('[data-test="fill-call-count-input"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-test="fill-count-button"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-test="fill-until-end-button"]').attributes('disabled')).toBeDefined();
    });

    it('shows fillUntilEnd loaded total progress when Vibe receives resolver totals', () => {
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus({
                    phase: 'filling',
                    fillCompletedCalls: 3,
                    fillLoadedCount: 75,
                    fillMode: 'end',
                    fillTotalCount: 381,
                }),
            },
        });

        expect(wrapper.get('[data-testid="browse-v2-status-pill"]').text()).toContain('Filling 75/381');
    });

    it('shows fillUntilEnd loaded count and call count when no total is available', () => {
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus({
                    phase: 'filling',
                    fillCompletedCalls: 3,
                    fillLoadedCount: 75,
                    fillMode: 'end',
                    fillTotalCount: null,
                }),
            },
        });

        expect(wrapper.get('[data-testid="browse-v2-status-pill"]').text()).toContain('Filling 75 loaded · 3 calls');
    });
});
