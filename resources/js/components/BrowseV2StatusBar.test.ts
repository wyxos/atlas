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
            }, [
                h('span', props.label),
                slots.value ? slots.value() : String(props.value ?? ''),
            ]);
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

        await wrapper.get('button[aria-label="Increase Auto scroll speed"]').trigger('click');
        await wrapper.get('button[aria-label="Decrease Auto scroll speed"]').trigger('click');

        expect(setAutoScrollSpeed).toHaveBeenNthCalledWith(1, 60);
        expect(setAutoScrollSpeed).toHaveBeenNthCalledWith(2, 40);

        setAutoScrollSpeed.mockClear();

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
                    removedCount: 4,
                }),
                totalAvailable: 381,
            },
        });

        expect(wrapper.get('[data-testid="browse-v2-loaded-total-pill"]').text()).toContain('24');
        expect(wrapper.get('[data-testid="browse-v2-available-total-pill"]').text()).toContain('377');
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

    it('does not render batch reaction buttons in the status bar', () => {
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus(),
                canTogglePageLoadingLock: true,
                togglePageLoadingLock: vi.fn(),
            },
        });

        expect(wrapper.get('[data-test="page-loading-lock-button"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="loaded-items-like-button"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="loaded-items-love-button"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="loaded-items-blacklist-button"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Like all');
        expect(wrapper.text()).not.toContain('Lock paging');
    });

    it('mirrors the next boundary proximity from the Vibe status', () => {
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus({
                    nextBoundaryLoadProgress: 0.42,
                    previousBoundaryLoadProgress: 0.68,
                }),
            },
        });

        expect(wrapper.get('[data-testid="browse-v2-next-boundary-progress"]').attributes('aria-valuenow')).toBe('42');
        expect(wrapper.get('[data-testid="browse-v2-next-boundary-pill"]').attributes('data-variant')).toBe('info');
        expect(wrapper.find('[data-testid="browse-v2-previous-boundary-pill"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Proximity');
        expect(wrapper.html()).toContain('bg-amber-300/80');
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

        expect(lockButton.classes().join(' ')).toContain('bg-danger-600');
        expect(lockButton.classes().join(' ')).toContain('text-white');
        expect(lockButton.find('[data-testid="lock-keyhole-icon"]').exists()).toBe(true);
        expect(lockButton.find('[data-testid="lock-keyhole-open-icon"]').exists()).toBe(false);
    });

    it('routes the page-loading lock through the provided handler', async () => {
        const togglePageLoadingLock = vi.fn();
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus(),
                canTogglePageLoadingLock: true,
                togglePageLoadingLock,
            },
        });

        await wrapper.get('[data-test="page-loading-lock-button"]').trigger('click');

        expect(togglePageLoadingLock).toHaveBeenCalledTimes(1);
    });

    it('hides the action rail when no action handlers are available', () => {
        const wrapper = mount(BrowseV2StatusBar, {
            props: {
                status: createStatus(),
                canTogglePageLoadingLock: false,
            },
        });

        expect(wrapper.find('[data-test="page-loading-lock-button"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="loaded-items-blacklist-button"]').exists()).toBe(false);
    });
});
