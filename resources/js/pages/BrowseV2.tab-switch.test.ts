import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { defineComponent, h, nextTick } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import BrowseV2 from './BrowseV2.vue';

type TestTab = {
    id: number;
    label: string;
    params: Record<string, unknown>;
    position: number;
    isActive: boolean;
    updatedAt: string | null;
};

const tabsMock = vi.hoisted(() => ({
    activeTabIdRef: null as { value: number | null } | null,
    createTab: vi.fn(),
    duplicateTab: vi.fn(),
    initialActiveTabId: 1 as number | null,
    initialTabs: [] as TestTab[],
    loadTabs: vi.fn(),
    setActiveTab: vi.fn(),
    tabsRef: null as { value: TestTab[] } | null,
    updateTabLabel: vi.fn(),
    updateTabCustomLabel: vi.fn(),
}));

vi.mock('@/components/ui/button', () => ({
    Button: defineComponent({
        name: 'ButtonStub',
        setup(_, { attrs, slots }) {
            return () => h('button', attrs, slots.default?.());
        },
    }),
}));

vi.mock('@/composables/useBrowseGlobalStartPanel', () => ({
    provideBrowseGlobalStartPanel: vi.fn(),
}));

vi.mock('@/utils/reactionQueue', () => ({
    undoLatestQueuedReaction: vi.fn(() => false),
}));

vi.mock('../components/ui/TabPanel.vue', () => ({
    default: defineComponent({
        name: 'TabPanelStub',
        setup(_, { slots }) {
            return () => h('div', [
                slots.tabs?.({ isMinimized: false }),
                slots.footer?.({ isMinimized: false }),
            ]);
        },
    }),
}));

vi.mock('../components/Tab.vue', () => ({
    default: defineComponent({
        name: 'TabStub',
        props: {
            id: { type: Number, required: true },
            label: { type: String, required: true },
            isActive: { type: Boolean, required: true },
        },
        emits: ['click', 'close', 'rename', 'duplicate', 'close-above', 'close-below', 'close-others', 'drag-start', 'drag-over', 'drag-drop', 'drag-end'],
        setup(props, { attrs, emit }) {
            return () => h('button', {
                ...attrs,
                'data-active': String(props.isActive),
                onClick: () => emit('click'),
            }, props.label);
        },
    }),
}));

vi.mock('../components/TabContentV2.vue', () => ({
    default: defineComponent({
        name: 'TabContentV2Stub',
        props: {
            tabId: { type: Number, required: true },
        },
        setup(props) {
            return () => h('div', {
                'data-test': 'active-tab-content',
                'data-tab-id': String(props.tabId),
            });
        },
    }),
}));

vi.mock('@/composables/useTabs', async () => {
    const { ref } = await import('vue');

    return {
        useTabs: () => {
            const tabs = ref(tabsMock.initialTabs.map(tab => ({ ...tab })));
            const activeTabId = ref<number | null>(tabsMock.initialActiveTabId);

            tabsMock.tabsRef = tabs;
            tabsMock.activeTabIdRef = activeTabId;

            tabsMock.loadTabs.mockImplementation(async () => undefined);
            tabsMock.setActiveTab.mockImplementation(async (tabId: number) => {
                activeTabId.value = tabId;
                tabs.value = tabs.value.map(tab => ({
                    ...tab,
                    isActive: tab.id === tabId,
                }));

                return tabs.value.find(tab => tab.id === tabId) ?? null;
            });

            return {
                activeTabId,
                closeTabs: vi.fn(),
                createTab: tabsMock.createTab,
                duplicateTab: tabsMock.duplicateTab,
                getActiveTab: () => tabs.value.find(tab => tab.id === activeTabId.value) ?? null,
                loadTabs: tabsMock.loadTabs,
                reorderTabs: vi.fn(),
                setActiveTab: tabsMock.setActiveTab,
                tabs,
                updateTabCustomLabel: tabsMock.updateTabCustomLabel,
                updateTabLabel: tabsMock.updateTabLabel,
            };
        },
    };
});

async function mountBrowseV2(initialPath: string = '/browse/file/1128') {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/browse', name: 'browse', component: { template: '<div />' } },
            { path: '/browse/file/:fileId', name: 'browse-file', component: { template: '<div />' } },
        ],
    });

    await router.push(initialPath);
    await router.isReady();

    const wrapper = mount(BrowseV2, {
        global: {
            plugins: [router],
        },
    });

    await flushPromises();

    return { router, wrapper };
}

describe('BrowseV2 tab switching', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        tabsMock.initialActiveTabId = 1;
        tabsMock.initialTabs = [
            { id: 1, label: 'Feed tab', params: {}, position: 0, isActive: true, updatedAt: null },
            { id: 2, label: 'User tab', params: { username: 'avavivid' }, position: 1, isActive: false, updatedAt: null },
        ];
        tabsMock.tabsRef = null;
        tabsMock.activeTabIdRef = null;
    });

    it('leaves a standalone file route before switching to another tab', async () => {
        const { router, wrapper } = await mountBrowseV2();

        await wrapper.get('[data-test="browse-tab-2"]').trigger('click');
        await flushPromises();
        await nextTick();

        expect(router.currentRoute.value.fullPath).toBe('/browse');
        expect(tabsMock.setActiveTab).toHaveBeenCalledWith(2);
        expect(wrapper.get('[data-test="active-tab-content"]').attributes('data-tab-id')).toBe('2');
    });

    it('keeps the standalone file route when clicking the already active tab', async () => {
        const { router, wrapper } = await mountBrowseV2();

        await wrapper.get('[data-test="browse-tab-1"]').trigger('click');
        await flushPromises();
        await nextTick();

        expect(router.currentRoute.value.fullPath).toBe('/browse/file/1128');
        expect(tabsMock.setActiveTab).not.toHaveBeenCalled();
    });
});
