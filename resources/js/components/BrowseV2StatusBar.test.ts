import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import BrowseV2StatusBar from './BrowseV2StatusBar.vue';

vi.mock('lucide-vue-next', () => ({
    Loader2: defineComponent({
        name: 'MockLoaderIcon',
        render() {
            return h('div', { 'data-testid': 'loader-icon' });
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
        fillDelayRemainingMs: null,
        fillTargetCount: null,
        hasNextPage: true,
        itemCount: 20,
        loadState: 'loaded' as const,
        nextCursor: '2',
        phase: 'idle' as const,
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
});
