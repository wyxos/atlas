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

const dropdownMenuStubs = {
    DropdownMenu: { template: '<div><slot /></div>' },
    DropdownMenuTrigger: { template: '<div><slot /></div>' },
    DropdownMenuContent: { template: '<div><slot /></div>' },
    DropdownMenuLabel: { template: '<div><slot /></div>' },
    DropdownMenuSeparator: { template: '<div />' },
    DropdownMenuItem: {
        props: ['disabled'],
        emits: ['select'],
        template: '<button :disabled="disabled" @click="$emit(\'select\')"><slot /></button>',
    },
};

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
        loadedItemsCount: 45,
        activeLoadedItemsAction: null,
        onRunLoadedItemsAction: vi.fn(),
        cancelMasonryLoad: vi.fn(),
        goToFirstPage: vi.fn(async () => undefined),
        loadNextPage: vi.fn(async () => undefined),
    };
}

describe('TabContentServiceHeader', () => {
    it('shows the live loaded-items count and concise action labels', async () => {
        const props = createProps();
        const wrapper = mount(TabContentServiceHeader, {
            props,
            global: {
                stubs: {
                    ...dropdownMenuStubs,
                    Button: buttonStub,
                    ChevronDown: simpleStub,
                    ChevronsUp: simpleStub,
                    ModerationRulesManager: simpleStub,
                    Play: simpleStub,
                    RotateCcw: simpleStub,
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

        expect(wrapper.text()).toContain('Loaded items (45)');
        expect(wrapper.text()).toContain('Favorite');
        expect(wrapper.text()).toContain('Like');
        expect(wrapper.text()).toContain('Funny');
        expect(wrapper.text()).toContain('Dislike');
        expect(wrapper.text()).toContain('Blacklist');
        expect(wrapper.text()).not.toContain('Favorite all');
        expect(wrapper.text()).not.toContain('Like all');
        expect(wrapper.text()).not.toContain('Funny all');
        expect(wrapper.text()).not.toContain('Dislike all');
        expect(wrapper.text()).not.toContain('Blacklist all');

        await wrapper.get('[data-test="loaded-items-like-all"]').trigger('click');

        expect(props.onRunLoadedItemsAction).toHaveBeenCalledWith('like');
        expect(wrapper.get('[data-test="loaded-items-like-all"]').attributes('disabled')).toBeUndefined();
    });
});
