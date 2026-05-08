import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import BrowseV2 from './BrowseV2.vue';

const {
    activeTabIdRef,
    closeTabsMock,
    createTabMock,
    duplicateTabMock,
    loadTabsMock,
    reorderTabsMock,
    setActiveTabMock,
    tabContentCancelFillMock,
    tabContentStopAutoScrollMock,
    tabsRef,
    updateTabCustomLabelMock,
    updateTabLabelMock,
} = vi.hoisted(() => ({
    tabsRef: { value: [] as Array<{
        id: number;
        label: string;
        customLabel?: string | null;
        params: Record<string, unknown>;
        position: number;
        isActive: boolean;
    }> },
    activeTabIdRef: { value: null as number | null },
    duplicateTabMock: vi.fn(),
    createTabMock: vi.fn(),
    closeTabsMock: vi.fn(),
    reorderTabsMock: vi.fn(),
    updateTabLabelMock: vi.fn(),
    updateTabCustomLabelMock: vi.fn(),
    setActiveTabMock: vi.fn(),
    loadTabsMock: vi.fn(() => Promise.resolve()),
    tabContentCancelFillMock: vi.fn(),
    tabContentStopAutoScrollMock: vi.fn(),
}));

vi.mock('../components/ui/TabPanel.vue', () => ({
    default: {
        name: 'TabPanel',
        template: `
            <div class="tab-panel-mock">
                <div data-test="tab-panel-tabs-scroll">
                    <slot name="tabs" :isMinimized="false" />
                </div>
                <slot name="footer" :isMinimized="false" />
            </div>
        `,
        props: ['modelValue', 'isMinimized'],
        emits: ['update:isMinimized'],
    },
}));

vi.mock('../components/Tab.vue', () => ({
    default: {
        name: 'Tab',
        template: `
            <button
                v-bind="$attrs"
                type="button"
                @click="$emit('click')"
                @contextmenu.prevent="$emit('duplicate')"
            >
                {{ label }}
            </button>
        `,
        props: [
            'id',
            'label',
            'customLabel',
            'isActive',
            'isMinimized',
            'isLoading',
            'isMasonryLoading',
            'isDragging',
            'dropIndicator',
            'canCloseAbove',
            'canCloseBelow',
            'canCloseOthers',
        ],
        emits: ['click', 'close', 'rename', 'duplicate', 'close-above', 'close-below', 'close-others', 'drag-start', 'drag-over', 'drag-drop', 'drag-end'],
    },
}));

vi.mock('../components/TabContentV2.vue', () => ({
    default: defineComponent({
        name: 'TabContentV2',
        props: [
            'tabId',
            'availableServices',
            'onReaction',
            'onLoadingChange',
            'onTabDataLoadingChange',
            'onUpdateTabLabel',
            'onOpenContainerTab',
        ],
        setup(_, { expose }) {
            expose({
                cancelFill: tabContentCancelFillMock,
                stopAutoScroll: tabContentStopAutoScrollMock,
            });

            return () => h('div', { 'data-test': 'tab-content-stub' });
        },
    }),
}));

vi.mock('@/composables/useTabs', async () => {
    const { ref } = await import('vue');
    const liveTabsRef = ref(tabsRef.value);
    const liveActiveTabIdRef = ref(activeTabIdRef.value);

    return {
        useTabs: vi.fn(() => ({
            tabs: liveTabsRef,
            activeTabId: liveActiveTabIdRef,
            loadTabs: loadTabsMock,
            createTab: createTabMock,
            closeTabs: closeTabsMock,
            duplicateTab: duplicateTabMock,
            getActiveTab: () => liveTabsRef.value.find(tab => tab.id === liveActiveTabIdRef.value),
            reorderTabs: reorderTabsMock,
            updateTabLabel: updateTabLabelMock,
            updateTabCustomLabel: updateTabCustomLabelMock,
            setActiveTab: setActiveTabMock,
        })),
        __tabsRef: liveTabsRef,
        __activeTabIdRef: liveActiveTabIdRef,
    };
});

type MockedTabsModule = {
    __tabsRef: {
        value: Array<{
            id: number;
            label: string;
            customLabel?: string | null;
            params: Record<string, unknown>;
            position: number;
            isActive: boolean;
        }>;
    };
    __activeTabIdRef: { value: number | null };
};

function createRect(top: number, height: number = 40): DOMRect {
    return {
        bottom: top + height,
        height,
        left: 0,
        right: 240,
        top,
        width: 240,
        x: 0,
        y: top,
        toJSON: () => ({}),
    } as DOMRect;
}

beforeEach(async () => {
    vi.clearAllMocks();

    const mockedTabsModule = await import('@/composables/useTabs') as MockedTabsModule;

    mockedTabsModule.__tabsRef.value = [
        { id: 1, label: 'Tab 1', params: { service: 'civit-ai-images', page: 4 }, position: 0, isActive: true },
        { id: 2, label: 'Tab 2', params: { service: 'civit-ai-images', page: 7 }, position: 1, isActive: false },
    ];
    mockedTabsModule.__activeTabIdRef.value = 1;
    loadTabsMock.mockResolvedValue(undefined);
    tabContentCancelFillMock.mockClear();
    tabContentStopAutoScrollMock.mockClear();
});

describe('Browse actions', () => {
    it('forwards duplicate tab requests from the tab UI into useTabs', async () => {
        const wrapper = mount(BrowseV2);

        await flushPromises();

        await wrapper.get('[data-test="browse-tab-2"]').trigger('contextmenu');

        expect(duplicateTabMock).toHaveBeenCalledWith(2);
    });

    it('does not render the first page action in the page shell', async () => {
        const wrapper = mount(BrowseV2);

        await flushPromises();

        expect(wrapper.find('[data-test="browse-page-actions"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="browse-first-page-action"]').exists()).toBe(false);
    });

    it('cancels active Vibe filling and auto-scroll before switching tabs', async () => {
        const wrapper = mount(BrowseV2);

        await flushPromises();
        await wrapper.get('[data-test="browse-tab-2"]').trigger('click');

        expect(tabContentCancelFillMock).toHaveBeenCalledTimes(1);
        expect(tabContentStopAutoScrollMock).toHaveBeenCalledTimes(1);
        expect(setActiveTabMock).toHaveBeenCalledWith(2);
        expect(tabContentCancelFillMock.mock.invocationCallOrder[0]).toBeLessThan(setActiveTabMock.mock.invocationCallOrder[0]);
    });

    it('smoothly scrolls a newly opened tab into view', async () => {
        const mockedTabsModule = await import('@/composables/useTabs') as MockedTabsModule;
        const createdTab = {
            id: 3,
            label: 'Tab 3',
            params: { service: 'civit-ai-images', page: 1 },
            position: 2,
            isActive: true,
        };
        createTabMock.mockImplementationOnce(async () => {
            mockedTabsModule.__tabsRef.value = [
                ...mockedTabsModule.__tabsRef.value.map(tab => ({ ...tab, isActive: false })),
                createdTab,
            ];
            mockedTabsModule.__activeTabIdRef.value = createdTab.id;

            return createdTab;
        });

        const wrapper = mount(BrowseV2, { attachTo: document.body });
        const rectSpy = vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
            if (this.getAttribute('data-test') === 'tab-panel-tabs-scroll') {
                return createRect(100, 200);
            }

            if (this.getAttribute('data-browse-tab-id') === '3') {
                return createRect(240);
            }

            return createRect(0);
        });

        try {
            await flushPromises();

            const scrollContainer = wrapper.get('[data-test="tab-panel-tabs-scroll"]').element as HTMLElement;
            const scrollToMock = vi.fn();
            scrollContainer.scrollTop = 40;
            scrollContainer.style.paddingTop = '16px';
            scrollContainer.scrollTo = scrollToMock;

            await wrapper.get('[data-test="create-tab-button"]').trigger('click');
            await flushPromises();

            expect(createTabMock).toHaveBeenCalledTimes(1);
            expect(scrollToMock).toHaveBeenCalledWith({
                top: 164,
                behavior: 'smooth',
            });
        } finally {
            rectSpy.mockRestore();
            wrapper.unmount();
        }
    });

    it('smoothly scrolls the focused tab into view after active tab closure', async () => {
        const mockedTabsModule = await import('@/composables/useTabs') as MockedTabsModule;
        mockedTabsModule.__tabsRef.value = [
            { id: 1, label: 'Tab 1', params: {}, position: 0, isActive: true },
            { id: 2, label: 'Tab 2', params: {}, position: 1, isActive: false },
            { id: 3, label: 'Tab 3', params: {}, position: 2, isActive: false },
        ];
        mockedTabsModule.__activeTabIdRef.value = 1;
        closeTabsMock.mockImplementationOnce(async () => {
            mockedTabsModule.__tabsRef.value = [
                { id: 2, label: 'Tab 2', params: {}, position: 0, isActive: true },
                { id: 3, label: 'Tab 3', params: {}, position: 1, isActive: false },
            ];
            mockedTabsModule.__activeTabIdRef.value = 2;

            return [1];
        });

        const wrapper = mount(BrowseV2, { attachTo: document.body });
        const rectSpy = vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
            if (this.getAttribute('data-test') === 'tab-panel-tabs-scroll') {
                return createRect(50, 200);
            }

            if (this.getAttribute('data-browse-tab-id') === '2') {
                return createRect(180);
            }

            return createRect(0);
        });

        try {
            await flushPromises();

            const scrollContainer = wrapper.get('[data-test="tab-panel-tabs-scroll"]').element as HTMLElement;
            const scrollToMock = vi.fn();
            scrollContainer.scrollTop = 10;
            scrollContainer.style.paddingTop = '8px';
            scrollContainer.scrollTo = scrollToMock;

            wrapper.findAllComponents({ name: 'Tab' })[0].vm.$emit('close');
            await flushPromises();

            expect(closeTabsMock).toHaveBeenCalledWith([1], {
                preferredTabId: null,
            });
            expect(scrollToMock).toHaveBeenCalledWith({
                top: 132,
                behavior: 'smooth',
            });
        } finally {
            rectSpy.mockRestore();
            wrapper.unmount();
        }
    });
});
