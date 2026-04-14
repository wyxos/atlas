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
        loadedItemsCount: 0,
        performLoadedItemsBulkAction: vi.fn(async () => 0),
    };
}

describe('TabContentServiceHeader', () => {
    it('keeps the compact page navigation buttons while removing the loaded-items dropdown', () => {
        const wrapper = mount(TabContentServiceHeader, {
            props: createProps(),
            global: {
                stubs: {
                    Ban: simpleStub,
                    Button: buttonStub,
                    ChevronDown: simpleStub,
                    ChevronsUp: simpleStub,
                    Eye: simpleStub,
                    Heart: simpleStub,
                    LockKeyhole: simpleStub,
                    LockKeyholeOpen: simpleStub,
                    ModerationRulesManager: simpleStub,
                    Play: simpleStub,
                    Select: simpleStub,
                    SelectContent: simpleStub,
                    SelectItem: simpleStub,
                    SelectTrigger: simpleStub,
                    SelectValue: simpleStub,
                    TabFilter: simpleStub,
                    ThumbsDown: simpleStub,
                    ThumbsUp: simpleStub,
                    X: simpleStub,
                },
            },
        });

        expect(wrapper.find('[data-test="loaded-items-menu-trigger"]').exists()).toBe(false);
        expect(wrapper.get('[data-test="cancel-loading-button"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="go-first-page-button"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="load-next-page-button"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="page-loading-lock-button"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="loaded-items-preview-to-four-button"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="loaded-items-like-button"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="loaded-items-love-button"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="loaded-items-dislike-button"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="loaded-items-blacklist-button"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="apply-service-button"]').exists()).toBe(true);
    });

    it('routes bulk actions and the page-loading lock through the wired handlers', async () => {
        const props = createProps();
        props.loadedItemsCount = 3;
        props.masonry = {
            cancel: vi.fn(),
            hasReachedEnd: false,
            isLoading: false,
            lockPageLoading: vi.fn(),
            pageLoadingLocked: false,
            remove: vi.fn(),
            restore: vi.fn(),
            unlockPageLoading: vi.fn(),
        };

        const wrapper = mount(TabContentServiceHeader, {
            props,
            global: {
                stubs: {
                    Ban: simpleStub,
                    Button: buttonStub,
                    ChevronDown: simpleStub,
                    ChevronsUp: simpleStub,
                    Eye: simpleStub,
                    Heart: simpleStub,
                    LockKeyhole: simpleStub,
                    LockKeyholeOpen: simpleStub,
                    ModerationRulesManager: simpleStub,
                    Play: simpleStub,
                    Select: simpleStub,
                    SelectContent: simpleStub,
                    SelectItem: simpleStub,
                    SelectTrigger: simpleStub,
                    SelectValue: simpleStub,
                    TabFilter: simpleStub,
                    ThumbsDown: simpleStub,
                    ThumbsUp: simpleStub,
                    X: simpleStub,
                },
            },
        });

        await wrapper.get('[data-test="loaded-items-preview-to-four-button"]').trigger('click');
        await wrapper.get('[data-test="loaded-items-like-button"]').trigger('click');
        await wrapper.get('[data-test="loaded-items-love-button"]').trigger('click');
        await wrapper.get('[data-test="loaded-items-dislike-button"]').trigger('click');
        await wrapper.get('[data-test="loaded-items-blacklist-button"]').trigger('click');
        await wrapper.get('[data-test="page-loading-lock-button"]').trigger('click');

        expect(props.performLoadedItemsBulkAction).toHaveBeenNthCalledWith(1, 'preview-to-4-and-remove');
        expect(props.performLoadedItemsBulkAction).toHaveBeenNthCalledWith(2, 'like');
        expect(props.performLoadedItemsBulkAction).toHaveBeenNthCalledWith(3, 'love');
        expect(props.performLoadedItemsBulkAction).toHaveBeenNthCalledWith(4, 'dislike');
        expect(props.performLoadedItemsBulkAction).toHaveBeenNthCalledWith(5, 'blacklist');
        expect(props.masonry.lockPageLoading).toHaveBeenCalledTimes(1);

        await wrapper.setProps({
            masonry: {
                ...props.masonry,
                pageLoadingLocked: true,
            },
        });
        await wrapper.get('[data-test="page-loading-lock-button"]').trigger('click');

        expect(props.masonry.unlockPageLoading).toHaveBeenCalledTimes(1);
    });
});
