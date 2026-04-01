import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { ref } from 'vue';
import Browse from './Browse.vue';
import {
    destroyBatch as tabsDestroyBatch,
    index as tabIndex,
    reorder as tabsReorder,
} from '@/actions/App/Http/Controllers/TabController';
import { index as browseIndex } from '@/actions/App/Http/Controllers/BrowseController';
import {
    createTestRouter,
    setupBrowseTestMocks,
    waitForStable,
    type BrowseMocks,
} from '@/test/browse-test-utils';

const {
    mockAxios,
    mockToast,
    mockIsLoading,
    mockCancelLoad,
    mockRemove,
    mockRestore,
    mockQueuePreviewIncrement,
} = vi.hoisted(() => {
    const toast = vi.fn();
    toast.dismiss = vi.fn();
    toast.error = vi.fn();
    toast.info = vi.fn();
    toast.success = vi.fn();
    toast.warning = vi.fn();

    return {
        mockAxios: {
            get: vi.fn(),
            post: vi.fn(),
            put: vi.fn(),
            delete: vi.fn(),
            patch: vi.fn(),
        },
        mockToast: toast,
        mockIsLoading: { value: false },
        mockCancelLoad: vi.fn(),
        mockRemove: vi.fn(),
        mockRestore: vi.fn(),
        mockQueuePreviewIncrement: vi.fn(),
    };
});

const mocks: BrowseMocks = {
    mockAxios,
    mockIsLoading: ref(false),
    mockCancelLoad,
    mockRemove,
    mockRestore,
    mockQueuePreviewIncrement,
};

const tabIndexUrl = tabIndex.definition?.url ?? tabIndex.url();
const browseIndexUrl = browseIndex.definition?.url ?? browseIndex.url();
const tabsDestroyBatchUrl = tabsDestroyBatch.definition?.url ?? tabsDestroyBatch.url();
const tabsReorderUrl = tabsReorder.definition?.url ?? tabsReorder.url();

global.fetch = vi.fn();

vi.mock('@/components/ui/toast/use-toast', () => ({
    useToast: () => mockToast,
}));

Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

vi.mock('@wyxos/vibe', async () => {
    const { createVibePageMock } = await import('@/test/browse-test-utils');
    return createVibePageMock({ mockIsLoading, mockCancelLoad, mockRemove, mockRestore });
});

vi.mock('@/composables/usePreviewBatch', () => ({
    usePreviewBatch: () => ({
        queuePreviewIncrement: mockQueuePreviewIncrement,
    }),
}));

beforeEach(() => {
    setupBrowseTestMocks(mocks);
});

function mockBrowseTabs(tabRows: Array<Record<string, unknown>>): void {
    mocks.mockAxios.get.mockImplementation((url: string) => {
        const tabShowMatch = url.match(/\/api\/tabs\/(\d+)(?:\?|$)/);
        if (tabShowMatch) {
            const id = Number(tabShowMatch[1]);
            const currentTab = tabRows.find(tab => Number(tab.id) === id);

            return Promise.resolve({
                data: {
                    tab: {
                        id,
                        label: currentTab?.label ?? `Tab ${id}`,
                        params: currentTab?.params ?? {},
                        feed: 'online',
                    },
                },
            });
        }

        if (url.includes(tabIndexUrl)) {
            return Promise.resolve({ data: tabRows });
        }

        if (url.includes(browseIndexUrl)) {
            return Promise.resolve({
                data: {
                    items: [],
                    nextPage: null,
                    services: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
                },
            });
        }

        return Promise.resolve({ data: { items: [], nextPage: null } });
    });
}

describe('Browse - Tab Management', () => {
    it('closes a background tab with middle click through the bulk delete endpoint', async () => {
        const tabRows = [
            { id: 1, label: 'Tab 1', params: { service: 'civit-ai-images', page: 1 }, position: 0, is_active: true },
            { id: 2, label: 'Tab 2', params: { service: 'civit-ai-images', page: 1 }, position: 1, is_active: false },
        ];
        mockBrowseTabs(tabRows);

        mocks.mockAxios.post.mockImplementation((url: string) => {
            if (url === tabsDestroyBatchUrl) {
                return Promise.resolve({
                    data: {
                        deleted_ids: [2],
                        active_tab_id: 1,
                    },
                });
            }

            return Promise.resolve({ data: {} });
        });

        const router = await createTestRouter('/browse');
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const tab2Element = wrapper.get('[data-test="browse-tab-2"]').element as HTMLElement;
        tab2Element.dispatchEvent(new MouseEvent('mousedown', { button: 1, bubbles: true, cancelable: true }));
        tab2Element.dispatchEvent(new MouseEvent('click', { button: 1, bubbles: true, cancelable: true }));

        await flushPromises();
        await wrapper.vm.$nextTick();

        expect(mocks.mockAxios.post).toHaveBeenCalledWith(tabsDestroyBatchUrl, {
            ids: [2],
            next_active_id: null,
        });

        const vm = wrapper.vm as any;
        expect(vm.tabs.map((tab: { id: number }) => tab.id)).toEqual([1]);
        expect(vm.activeTabId).toBe(1);
    });

    it('close others on a background tab activates that tab when the current active tab is removed', async () => {
        const tabRows = [
            { id: 1, label: 'Tab 1', params: { service: 'civit-ai-images', page: 1 }, position: 0, is_active: true },
            { id: 2, label: 'Tab 2', params: { service: 'civit-ai-images', page: 1 }, position: 1, is_active: false },
            { id: 3, label: 'Tab 3', params: { service: 'civit-ai-images', page: 1 }, position: 2, is_active: false },
        ];
        mockBrowseTabs(tabRows);

        mocks.mockAxios.post.mockImplementation((url: string) => {
            if (url === tabsDestroyBatchUrl) {
                return Promise.resolve({
                    data: {
                        deleted_ids: [1, 3],
                        active_tab_id: 2,
                    },
                });
            }

            return Promise.resolve({ data: {} });
        });

        const router = await createTestRouter('/browse');
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        await wrapper.get('[data-test="browse-tab-2"]').trigger('contextmenu', {
            button: 2,
            clientX: 24,
            clientY: 24,
        });
        await new Promise(resolve => window.setTimeout(resolve, 0));

        const closeOthers = document.body.querySelector('[data-test="tab-context-close-others"]');
        if (!(closeOthers instanceof HTMLElement)) {
            throw new Error('Close others action did not render.');
        }

        closeOthers.click();
        await flushPromises();
        await wrapper.vm.$nextTick();

        expect(mocks.mockAxios.post).toHaveBeenCalledWith(tabsDestroyBatchUrl, {
            ids: [1, 3],
            next_active_id: 2,
        });

        const vm = wrapper.vm as any;
        expect(vm.tabs.map((tab: { id: number }) => tab.id)).toEqual([2]);
        expect(vm.activeTabId).toBe(2);
    });

    it('reorders tabs with drag and drop and posts the full ordered id list', async () => {
        const tabRows = [
            { id: 1, label: 'Tab 1', params: { service: 'civit-ai-images', page: 1 }, position: 0, is_active: true },
            { id: 2, label: 'Tab 2', params: { service: 'civit-ai-images', page: 1 }, position: 1, is_active: false },
            { id: 3, label: 'Tab 3', params: { service: 'civit-ai-images', page: 1 }, position: 2, is_active: false },
        ];
        mockBrowseTabs(tabRows);

        mocks.mockAxios.post.mockImplementation((url: string) => {
            if (url === tabsReorderUrl) {
                return Promise.resolve({
                    data: {
                        ordered_ids: [3, 1, 2],
                    },
                });
            }

            return Promise.resolve({ data: {} });
        });

        const router = await createTestRouter('/browse');
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const tab3 = wrapper.get('[data-test="browse-tab-3"]');
        const tab1 = wrapper.get('[data-test="browse-tab-1"]');
        const dataTransfer = {
            setData: vi.fn(),
            effectAllowed: '',
            dropEffect: '',
        };

        Object.defineProperty(tab1.element, 'getBoundingClientRect', {
            value: () => ({
                top: 0,
                bottom: 40,
                left: 0,
                right: 120,
                width: 120,
                height: 40,
                x: 0,
                y: 0,
                toJSON: () => ({}),
            }),
        });

        await tab3.trigger('dragstart', { dataTransfer });
        await tab1.trigger('dragover', { clientY: 5, dataTransfer });
        await tab1.trigger('drop', { clientY: 5, dataTransfer });
        await flushPromises();

        expect(mocks.mockAxios.post).toHaveBeenCalledWith(tabsReorderUrl, {
            ordered_ids: [3, 1, 2],
        });

        const vm = wrapper.vm as any;
        expect(vm.tabs.map((tab: { id: number }) => tab.id)).toEqual([3, 1, 2]);
    });
});
