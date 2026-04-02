import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Browse from './Browse.vue';

const {
    activeTabIdRef,
    closeTabsMock,
    createTabMock,
    duplicateTabMock,
    loadTabsMock,
    reorderTabsMock,
    setActiveTabMock,
    tabsRef,
    updateActiveTabMock,
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
    updateActiveTabMock: vi.fn(),
    updateTabLabelMock: vi.fn(),
    updateTabCustomLabelMock: vi.fn(),
    setActiveTabMock: vi.fn(),
    loadTabsMock: vi.fn(() => Promise.resolve()),
}));

vi.mock('../components/ui/TabPanel.vue', () => ({
    default: {
        name: 'TabPanel',
        template: `
            <div class="tab-panel-mock">
                <slot name="tabs" :isMinimized="false" />
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

vi.mock('../components/TabContent.vue', () => ({
    default: {
        name: 'TabContent',
        template: '<div data-test="tab-content-stub"></div>',
        props: [
            'tabId',
            'availableServices',
            'updateActiveTab',
            'onReaction',
            'onLoadingChange',
            'onTabDataLoadingChange',
            'onUpdateTabLabel',
            'onOpenContainerTab',
        ],
    },
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
            updateActiveTab: updateActiveTabMock,
            updateTabLabel: updateTabLabelMock,
            updateTabCustomLabel: updateTabCustomLabelMock,
            setActiveTab: setActiveTabMock,
        })),
        __tabsRef: liveTabsRef,
        __activeTabIdRef: liveActiveTabIdRef,
    };
});

beforeEach(async () => {
    vi.clearAllMocks();

    const mockedTabsModule = await import('@/composables/useTabs') as {
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

    mockedTabsModule.__tabsRef.value = [
        { id: 1, label: 'Tab 1', params: { service: 'civit-ai-images', page: 4 }, position: 0, isActive: true },
        { id: 2, label: 'Tab 2', params: { service: 'civit-ai-images', page: 7 }, position: 1, isActive: false },
    ];
    mockedTabsModule.__activeTabIdRef.value = 1;
    loadTabsMock.mockResolvedValue(undefined);
});

describe('Browse actions', () => {
    it('forwards duplicate tab requests from the tab UI into useTabs', async () => {
        const wrapper = mount(Browse);

        await flushPromises();

        await wrapper.get('[data-test="browse-tab-2"]').trigger('contextmenu');

        expect(duplicateTabMock).toHaveBeenCalledWith(2);
    });

    it('does not render the first page action in the page shell', async () => {
        const wrapper = mount(Browse);

        await flushPromises();

        expect(wrapper.find('[data-test="browse-page-actions"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="browse-first-page-action"]').exists()).toBe(false);
    });
});
