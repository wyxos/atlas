import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { queueManager } from '@/composables/useQueue';
import { provideBrowseGlobalStartPanel } from '@/composables/useBrowseGlobalStartPanel';
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

const searchableDropdownStub = defineComponent({
    name: 'SearchableDropdownStub',
    props: {
        modelValue: { default: '' },
        options: { type: Array, default: () => [] },
        placeholder: { type: String, default: '' },
    },
    setup(props, { attrs }) {
        return () => {
            const option = (props.options as Array<{ label?: string; value?: unknown }>)
                .find((entry) => entry.value === props.modelValue);

            return h('button', attrs, option?.label ?? props.placeholder);
        };
    },
});

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

function mountHeader(props = createProps()) {
    const HeaderHarness = defineComponent({
        setup() {
            provideBrowseGlobalStartPanel();

            return () => h(TabContentServiceHeader, props);
        },
    });

    return mount(HeaderHarness, {
        global: {
            stubs: {
                Button: buttonStub,
                ChevronDown: simpleStub,
                ChevronsUp: simpleStub,
                ListChecks: simpleStub,
                ModerationRulesManager: simpleStub,
                LocalSourceDropdown: simpleStub,
                Play: simpleStub,
                Select: simpleStub,
                SelectContent: simpleStub,
                SelectItem: simpleStub,
                SelectTrigger: simpleStub,
                SelectValue: simpleStub,
                SearchableDropdown: searchableDropdownStub,
                TabFilter: simpleStub,
                X: simpleStub,
            },
        },
    });
}

describe('TabContentServiceHeader', () => {
    beforeEach(() => {
        queueManager.collection.reset();
    });

    it('keeps only the top-level browse controls in the header', () => {
        const wrapper = mountHeader();

        expect(wrapper.find('[data-test="loaded-items-menu-trigger"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="cancel-loading-button"]').exists()).toBe(false);
        expect(wrapper.get('[data-test="go-first-page-button"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="load-next-page-button"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="page-loading-lock-button"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="loaded-items-preview-to-four-button"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="loaded-items-like-button"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="loaded-items-love-button"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="loaded-items-blacklist-button"]').exists()).toBe(false);
        expect(wrapper.get('[data-test="global-start-panel-button"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="global-start-panel-button"]').text()).not.toContain('Queue');
        expect(wrapper.get('[data-test="apply-service-button"]').exists()).toBe(true);
    });

    it('renders the selected local feed as Library', () => {
        const props = createProps();
        props.form.data.feed = 'local';

        const wrapper = mountHeader(props);

        expect(wrapper.get('[data-test="source-type-select-trigger"]').text()).toContain('Library');
        expect(wrapper.get('[data-test="source-type-select-trigger"]').text()).not.toContain('local');
    });

    it('routes header navigation and apply buttons through the wired handlers', async () => {
        const props = createProps();
        props.masonry = {
            isLoading: false,
        };

        const wrapper = mountHeader(props);

        await wrapper.get('[data-test="go-first-page-button"]').trigger('click');
        await wrapper.get('[data-test="load-next-page-button"]').trigger('click');
        await wrapper.get('[data-test="apply-service-button"]').trigger('click');

        expect(props.goToFirstPage).toHaveBeenCalledTimes(1);
        expect(props.loadNextPage).toHaveBeenCalledTimes(1);
        expect(props.applyService).toHaveBeenCalledTimes(1);
    });

    it('shows and routes the cancel loading CTA while Vibe is loading', async () => {
        const props = createProps();
        props.masonry = {
            cancel: vi.fn(),
            isLoading: true,
            remove: vi.fn(),
            restore: vi.fn(),
        };

        const wrapper = mountHeader(props);

        await wrapper.get('[data-test="cancel-loading-button"]').trigger('click');

        expect(props.cancelMasonryLoad).toHaveBeenCalledTimes(1);
    });

    it('toggles the global start panel from the setup CTA', async () => {
        const wrapper = mountHeader();
        const button = wrapper.get('[data-test="global-start-panel-button"]');

        expect(button.attributes('aria-expanded')).toBe('false');

        await button.trigger('click');

        expect(button.attributes('aria-expanded')).toBe('true');
    });
});
