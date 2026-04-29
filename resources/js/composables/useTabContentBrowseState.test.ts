import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { computed, defineComponent, ref, shallowRef } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { createBrowseForm } from './useBrowseForm';
import { useTabContentBrowseState } from './useTabContentBrowseState';
import type { FeedItem, TabData } from './useTabs';

const { mockAxios, mockToast } = vi.hoisted(() => {
    const toast = vi.fn();
    toast.error = vi.fn();

    return {
        mockAxios: {
            get: vi.fn(),
        },
        mockToast: toast,
    };
});

vi.mock('@/components/ui/toast/use-toast', () => ({
    useToast: () => mockToast,
}));

Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

describe('useTabContentBrowseState', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('boots a backend-active params-only CivitAI tab into a page one Vibe load state', async () => {
        const loadServices = vi.fn().mockResolvedValue(undefined);
        const loadSources = vi.fn().mockResolvedValue(undefined);
        const clearPreviewedItems = vi.fn();
        const resetPreloadedItems = vi.fn();
        const onTabDataLoadingChange = vi.fn();

        mockAxios.get.mockResolvedValueOnce({
            data: {
                tab: {
                    id: 44,
                    label: 'CivitAI Images: Model 1894057 @ 2457413 - 1',
                    params: {
                        feed: 'online',
                        service: 'civit-ai-images',
                        page: 1,
                        limit: 20,
                        modelId: 1894057,
                        modelVersionId: 2457413,
                    },
                    items: [],
                    position: 2,
                    isActive: true,
                },
            },
        });

        const Probe = defineComponent({
            setup() {
                const form = createBrowseForm();
                const items = shallowRef<FeedItem[]>([]);
                const tab = ref<TabData | null>(null);
                const browse = useTabContentBrowseState({
                    tabId: ref(44),
                    form,
                    data: { items, tab },
                    catalog: {
                        availableServices: computed(() => [{
                            key: 'civit-ai-images',
                            label: 'CivitAI Images',
                            defaults: {
                                limit: 20,
                                nsfw: false,
                                period: 'AllTime',
                                sort: 'Newest',
                                type: 'all',
                            },
                        }]),
                        localService: ref(null),
                        loadServices,
                        loadSources,
                    },
                    view: {
                        clearPreviewedItems,
                        resetPreloadedItems,
                    },
                    events: {
                        onTabDataLoadingChange,
                    },
                });

                return {
                    browse,
                    form,
                    items,
                    tab,
                };
            },
            template: '<div />',
        });

        const wrapper = mount(Probe);
        await flushPromises();

        expect(mockAxios.get).toHaveBeenCalledWith('/api/tabs/44', {
            headers: {
                'Cache-Control': 'no-cache',
                Pragma: 'no-cache',
            },
        });
        expect(wrapper.vm.tab?.id).toBe(44);
        expect(wrapper.vm.form.data.tab_id).toBe(44);
        expect(wrapper.vm.form.data.service).toBe('civit-ai-images');
        expect(wrapper.vm.form.data.serviceFilters.modelId).toBe(1894057);
        expect(wrapper.vm.form.data.serviceFilters.modelVersionId).toBe(2457413);
        expect(wrapper.vm.browse.state.shouldShowForm.value).toBe(false);
        expect(wrapper.vm.browse.state.startPageToken.value).toBe(1);
        expect(wrapper.vm.browse.state.masonryRenderKey.value).toBe(1);
        expect(wrapper.vm.items).toEqual([]);
        expect(loadServices).toHaveBeenCalledTimes(1);
        expect(loadSources).toHaveBeenCalledTimes(1);
        expect(clearPreviewedItems).not.toHaveBeenCalled();
        expect(resetPreloadedItems).toHaveBeenCalledTimes(1);
        expect(onTabDataLoadingChange).toHaveBeenNthCalledWith(1, true);
        expect(onTabDataLoadingChange).toHaveBeenLastCalledWith(false);
    });
});
