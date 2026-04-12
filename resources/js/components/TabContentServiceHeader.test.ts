import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import TabContentServiceHeader from './TabContentServiceHeader.vue';

const buttonStub = defineComponent({
    name: 'ButtonStub',
    props: {
        disabled: { type: Boolean, default: false },
    },
    setup(props, { attrs, slots }) {
        return () => h('button', {
            ...attrs,
            disabled: props.disabled,
        }, slots.default?.());
    },
});

const simpleStub = { template: '<div><slot /></div>' };

function createProps() {
    return {
        form: {
            data: {
                feed: 'online',
                service: 'civit-ai-images',
                source: 'all',
            },
            reset: vi.fn(),
        },
        availableServices: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
        availableSources: ['all'],
        localService: null,
        masonry: null,
        filterSheetOpen: false,
        updateFilterSheetOpen: vi.fn(),
        updateFeed: vi.fn(),
        updateService: vi.fn(),
        updateSource: vi.fn(),
        applyService: vi.fn(async () => undefined),
        applyFilters: vi.fn(async () => undefined),
        resetFilters: vi.fn(),
        cancelMasonryLoad: vi.fn(),
        goToFirstPage: vi.fn(async () => undefined),
        loadNextPage: vi.fn(async () => undefined),
    };
}

describe('TabContentServiceHeader', () => {
    it('keeps the compact page navigation buttons while removing the loaded-items dropdown', () => {
        const wrapper = mount(TabContentServiceHeader, {
            props: createProps(),
            global: {
                stubs: {
                    Button: buttonStub,
                    ChevronDown: simpleStub,
                    ChevronsUp: simpleStub,
                    ModerationRulesManager: simpleStub,
                    Play: simpleStub,
                    Select: simpleStub,
                    SelectContent: simpleStub,
                    SelectItem: simpleStub,
                    SelectTrigger: simpleStub,
                    SelectValue: simpleStub,
                    TabFilter: simpleStub,
                    X: simpleStub,
                },
            },
        });

        expect(wrapper.find('[data-test="loaded-items-menu-trigger"]').exists()).toBe(false);
        expect(wrapper.get('[data-test="cancel-loading-button"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="go-first-page-button"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="load-next-page-button"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="apply-service-button"]').exists()).toBe(true);
    });
});
