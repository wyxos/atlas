import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import BrowseV2StatusBar from './BrowseV2StatusBar.vue';

vi.mock('lucide-vue-next', () => ({
    Ban: defineComponent({
        name: 'MockBanIcon',
        render() {
            return h('div', { 'data-testid': 'ban-icon' });
        },
    }),
    ChevronsDown: defineComponent({
        name: 'MockChevronsDownIcon',
        render() {
            return h('div', { 'data-testid': 'chevrons-down-icon' });
        },
    }),
    Heart: defineComponent({
        name: 'MockHeartIcon',
        render() {
            return h('div', { 'data-testid': 'heart-icon' });
        },
    }),
    ListPlus: defineComponent({
        name: 'MockListPlusIcon',
        render() {
            return h('div', { 'data-testid': 'list-plus-icon' });
        },
    }),
    Loader2: defineComponent({
        name: 'MockLoaderIcon',
        render() {
            return h('div', { 'data-testid': 'loader-icon' });
        },
    }),
    LockKeyhole: defineComponent({
        name: 'MockLockKeyholeIcon',
        render() {
            return h('div', { 'data-testid': 'lock-keyhole-icon' });
        },
    }),
    LockKeyholeOpen: defineComponent({
        name: 'MockLockKeyholeOpenIcon',
        render() {
            return h('div', { 'data-testid': 'lock-keyhole-open-icon' });
        },
    }),
    Pause: defineComponent({
        name: 'MockPauseIcon',
        render() {
            return h('div', { 'data-testid': 'pause-icon' });
        },
    }),
    Minus: defineComponent({
        name: 'MockMinusIcon',
        render() {
            return h('div', { 'data-testid': 'minus-icon' });
        },
    }),
    Play: defineComponent({
        name: 'MockPlayIcon',
        render() {
            return h('div', { 'data-testid': 'play-icon' });
        },
    }),
    Plus: defineComponent({
        name: 'MockPlusIcon',
        render() {
            return h('div', { 'data-testid': 'plus-icon' });
        },
    }),
    ThumbsUp: defineComponent({
        name: 'MockThumbsUpIcon',
        render() {
            return h('div', { 'data-testid': 'thumbs-up-icon' });
        },
    }),
    X: defineComponent({
        name: 'MockXIcon',
        render() {
            return h('div', { 'data-testid': 'x-icon' });
        },
    }),
}));

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
            }, slots.value ? slots.value() : String(props.value ?? ''));
        },
    }),
}));

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

describe('BrowseV2StatusBar', () => {
    it('uses the danger variant for failed state', () => {
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus({
                    loadState: 'failed',
                    errorMessage: 'Broken',
                }),
            },
        });

        expect(wrapper.get('[data-testid="browse-v2-status-pill"]').attributes('data-variant')).toBe('danger');
    });

    it('uses the warning variant for loading and refill states', () => {
        const loadingWrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus({
                    loadState: 'loading',
                }),
            },
        });

        const fillingWrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus({
                    phase: 'filling',
                    fillCollectedCount: 4,
                    fillTargetCount: 8,
                }),
            },
        });

        const refreshingWrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus({
                    phase: 'refreshing',
                }),
            },
        });

        expect(loadingWrapper.get('[data-testid="browse-v2-status-pill"]').attributes('data-variant')).toBe('warning');
        expect(fillingWrapper.get('[data-testid="browse-v2-status-pill"]').attributes('data-variant')).toBe('warning');
        expect(refreshingWrapper.get('[data-testid="browse-v2-status-pill"]').attributes('data-variant')).toBe('warning');
    });

    it('uses the success variant when browse-v2 is loaded and idle', () => {
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus(),
            },
        });

        expect(wrapper.get('[data-testid="browse-v2-status-pill"]').attributes('data-variant')).toBe('success');
    });

    it('shows no items available when the feed is empty', () => {
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus({
                    itemCount: 0,
                }),
            },
        });

        expect(wrapper.get('[data-testid="browse-v2-status-pill"]').text()).toContain('No items available');
        expect(wrapper.get('[data-testid="browse-v2-status-pill"]').attributes('data-variant')).toBe('neutral');
    });

    it('shows filling status instead of empty state while refill is active with zero loaded items', () => {
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus({
                    itemCount: 0,
                    phase: 'filling',
                    fillCollectedCount: 0,
                    fillTargetCount: 8,
                    fillDelayRemainingMs: 1200,
                }),
            },
        });

        expect(wrapper.get('[data-testid="browse-v2-status-pill"]').text()).toContain('Filling 0/8');
        expect(wrapper.get('[data-testid="browse-v2-status-pill"]').text()).not.toContain('No items available');
        expect(wrapper.get('[data-testid="browse-v2-status-pill"]').attributes('data-variant')).toBe('warning');
    });

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

    it('routes auto-scroll controls through Vibe handlers and clamps speed input', async () => {
        const setAutoScrollSpeed = vi.fn();
        const toggleAutoScroll = vi.fn();
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                autoScrollMax: 150,
                autoScrollMin: 20,
                autoScrollSpeed: 50,
                setAutoScrollSpeed,
                status: createStatus(),
                toggleAutoScroll,
            },
        });

        await wrapper.get('[data-test="auto-scroll-speed-input"]').setValue('8');
        expect(setAutoScrollSpeed).not.toHaveBeenCalled();

        await wrapper.get('[data-test="auto-scroll-speed-input"]').trigger('blur');
        await wrapper.get('[data-test="auto-scroll-toggle-button"]').trigger('click');

        expect(setAutoScrollSpeed).toHaveBeenCalledWith(20);
        expect(wrapper.get('[data-test="auto-scroll-speed-input"]').attributes('type')).toBe('text');
        expect(toggleAutoScroll).toHaveBeenCalledTimes(1);
        expect(wrapper.get('[data-test="auto-scroll-toggle-button"]').attributes('aria-pressed')).toBe('false');
        expect(wrapper.find('[data-testid="play-icon"]').exists()).toBe(true);

        await wrapper.setProps({ autoScrollActive: true });

        expect(wrapper.get('[data-test="auto-scroll-toggle-button"]').attributes('aria-pressed')).toBe('true');
        expect(wrapper.find('[data-testid="pause-icon"]').exists()).toBe(true);
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

    it('shows the in-flight fill cursor in the next pill while refilling', () => {
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus({
                    phase: 'filling',
                    nextCursor: null,
                    fillCursor: '200|1747849304567',
                    fillCollectedCount: 181,
                    fillTargetCount: 200,
                }),
            },
        });

        expect(wrapper.get('[data-testid="browse-v2-next-pill"]').text()).toContain('200|1747849304567');
    });

    it('shows end of list when browse-v2 is exhausted', () => {
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus({
                    hasNextPage: false,
                    nextCursor: null,
                }),
            },
        });

        expect(wrapper.get('[data-testid="browse-v2-status-pill"]').text()).toContain('End of list');
        expect(wrapper.get('[data-testid="browse-v2-status-pill"]').attributes('data-variant')).toBe('neutral');
    });

    it('keeps showing the retained retry cursor after browse-v2 reaches an exhausted next cursor', () => {
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus({
                    hasNextPage: false,
                    nextCursor: '400|1777443670108',
                }),
            },
        });

        expect(wrapper.get('[data-testid="browse-v2-next-pill"]').text()).toContain('400|1777443670108');
        expect(wrapper.get('[data-testid="browse-v2-status-pill"]').text()).toContain('End of list');
    });

    it('shows loaded and backend available totals when available total exists', () => {
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus({
                    itemCount: 24,
                }),
                totalAvailable: 381,
            },
        });

        expect(wrapper.get('[data-testid="browse-v2-loaded-total-pill"]').text()).toContain('24');
        expect(wrapper.get('[data-testid="browse-v2-available-total-pill"]').text()).toContain('381');
    });

    it('hides backend available total when unavailable', () => {
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus({
                    itemCount: 24,
                }),
                totalAvailable: null,
            },
        });

        expect(wrapper.get('[data-testid="browse-v2-loaded-total-pill"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="browse-v2-available-total-pill"]').exists()).toBe(false);
    });

    it('renders the moved bulk actions as icon-only status bar buttons', () => {
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus(),
                bulkActionsDisabled: false,
                canTogglePageLoadingLock: true,
                performLoadedItemsBulkAction: vi.fn(),
                togglePageLoadingLock: vi.fn(),
            },
        });

        expect(wrapper.get('[data-test="page-loading-lock-button"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="loaded-items-like-button"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="loaded-items-love-button"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="loaded-items-blacklist-button"]').exists()).toBe(true);
        expect(wrapper.text()).not.toContain('Like all');
        expect(wrapper.text()).not.toContain('Lock paging');
    });

    it('mirrors the previous and next boundary progress bars from the Vibe status', () => {
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus({
                    nextBoundaryLoadProgress: 0.42,
                    previousBoundaryLoadProgress: 0.68,
                }),
            },
        });

        expect(wrapper.get('[data-testid="browse-v2-previous-boundary-progress"]').attributes('aria-valuenow')).toBe('68');
        expect(wrapper.get('[data-testid="browse-v2-next-boundary-progress"]').attributes('aria-valuenow')).toBe('42');
        expect(wrapper.html()).not.toContain('max-w-[1280px]');
    });

    it('shows a closed red lock icon while paging is locked', () => {
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus(),
                canTogglePageLoadingLock: true,
                pageLoadingLocked: true,
                togglePageLoadingLock: vi.fn(),
            },
        });

        const lockButton = wrapper.get('[data-test="page-loading-lock-button"]');

        expect(lockButton.classes().join(' ')).toContain('border-danger-400/60');
        expect(lockButton.classes().join(' ')).toContain('text-danger-100');
        expect(lockButton.find('[data-testid="lock-keyhole-icon"]').exists()).toBe(true);
        expect(lockButton.find('[data-testid="lock-keyhole-open-icon"]').exists()).toBe(false);
    });

    it('routes bulk actions and the page-loading lock through the provided handlers', async () => {
        const performLoadedItemsBulkAction = vi.fn(async () => 0);
        const togglePageLoadingLock = vi.fn();
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus(),
                bulkActionsDisabled: false,
                canTogglePageLoadingLock: true,
                performLoadedItemsBulkAction,
                togglePageLoadingLock,
            },
        });

        await wrapper.get('[data-test="loaded-items-like-button"]').trigger('click');
        await wrapper.get('[data-test="loaded-items-love-button"]').trigger('click');
        await wrapper.get('[data-test="loaded-items-blacklist-button"]').trigger('click');
        await wrapper.get('[data-test="page-loading-lock-button"]').trigger('click');

        expect(performLoadedItemsBulkAction).toHaveBeenNthCalledWith(1, 'like');
        expect(performLoadedItemsBulkAction).toHaveBeenNthCalledWith(2, 'love');
        expect(performLoadedItemsBulkAction).toHaveBeenNthCalledWith(3, 'blacklist');
        expect(togglePageLoadingLock).toHaveBeenCalledTimes(1);
    });

    it('disables the action rail when handlers are unavailable', () => {
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus(),
                bulkActionsDisabled: true,
                canTogglePageLoadingLock: false,
                performLoadedItemsBulkAction: vi.fn(),
            },
        });

        expect(wrapper.get('[data-test="page-loading-lock-button"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-test="loaded-items-blacklist-button"]').attributes('disabled')).toBeDefined();
    });
});
