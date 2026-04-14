import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h, reactive, ref } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TabContentV2 from './TabContentV2.vue';

const mockAxios = vi.hoisted(() => ({ delete: vi.fn(), get: vi.fn(), patch: vi.fn(), post: vi.fn(), put: vi.fn() }));

const testState = vi.hoisted(() => ({
    bootstrapBehavior: 'success' as 'error' | 'loading' | 'success', initializeSpy: vi.fn(async () => undefined),
    restoredItems: [] as Array<Record<string, unknown>>, toastError: vi.fn(), viewerOnClose: vi.fn(), viewerOnOpen: vi.fn(),
}));

vi.mock('@/actions/App/Http/Controllers/FilesController', () => ({
    show: {
        url: (fileId: number) => `/api/files/${fileId}`,
    },
}));

vi.mock('@/components/ui/toast/use-toast', () => ({
    useToast: () => ({
        error: testState.toastError,
    }),
}));

vi.mock('@/composables/useBrowseForm', () => ({
    BrowseFormKey: Symbol('BrowseForm'),
    createBrowseForm: () => ({
        data: reactive({
            feed: 'online',
            limit: 20,
            page: 1,
            service: 'civit-ai-images',
            serviceFilters: [],
            source: 'all',
            tab_id: 1,
        }),
        getData() {
            return this.data;
        },
        isLocal: ref(false),
        isLocalMode: ref(false),
        reset: vi.fn(),
        setService: vi.fn(),
        syncFromTab: vi.fn(),
    }),
}));

vi.mock('@/composables/useDownloadedReactionPrompt', () => ({
    useDownloadedReactionPrompt: () => ({
        data: {
            open: ref(false),
        },
        prompt: vi.fn(),
        chooseReact: vi.fn(),
        chooseRedownload: vi.fn(),
        close: vi.fn(),
        setOpen: vi.fn(),
    }),
}));

vi.mock('@/composables/useFileViewerData', () => ({
    useFileViewerData: () => ({
        fileData: ref(null),
        isLoadingFileData: ref(false),
    }),
}));

vi.mock('@/composables/useFileViewerSheetState', () => ({
    useFileViewerSheetState: () => {
        const sheetState = reactive({
            isOpen: false,
        });

        return {
            sheetState,
            setSheetOpen: (value: boolean) => {
                sheetState.isOpen = value;
            },
        };
    },
}));

vi.mock('@/composables/useItemPreview', () => ({
    useItemPreview: () => ({
        incrementPreviewCount: vi.fn(),
        clearPreviewedItems: vi.fn(),
        markPreviewedItems: vi.fn(),
    }),
}));

vi.mock('@/composables/useLocalFileDeletion', () => ({
    useLocalFileDeletion: () => ({
        state: {
            deleteError: ref(null),
            deleting: ref(false),
            dialogOpen: ref(false),
            itemToDelete: ref(null),
        },
        actions: {
            close: vi.fn(),
            confirm: vi.fn(),
        },
    }),
}));

vi.mock('@/composables/useTabContentBrowseState', () => ({
    useTabContentBrowseState: (options: {
        data: { tab: { value: unknown } };
        events: { onTabDataLoadingChange?: (value: boolean) => void };
        tabId: { value: number | null };
    }) => {
        const isInitializing = ref(testState.bootstrapBehavior === 'loading');
        const bootstrapFailed = ref(testState.bootstrapBehavior === 'error');

        if (testState.bootstrapBehavior === 'success') {
            queueMicrotask(() => {
                options.data.tab.value = {
                    id: options.tabId.value ?? 1,
                    isActive: true,
                    items: testState.restoredItems.map((item) => ({ ...item })),
                    label: 'Browse Tab',
                    params: {
                        feed: 'online',
                        page: 1,
                        service: 'civit-ai-images',
                        tab_id: options.tabId.value ?? 1,
                    },
                    position: 0,
                    updatedAt: null,
                };
                isInitializing.value = false;
                options.events.onTabDataLoadingChange?.(false);
            });
        } else if (testState.bootstrapBehavior === 'error') {
            queueMicrotask(() => {
                isInitializing.value = false;
                options.events.onTabDataLoadingChange?.(false);
            });
        }

        return {
            state: {
                bootstrapFailed,
                isInitializing,
                masonryRenderKey: ref(0),
                shouldShowForm: ref(false),
                startPageToken: ref(1),
                totalAvailable: ref(null),
            },
            actions: {
                applyFilters: vi.fn(async () => undefined),
                applyService: vi.fn(async () => undefined),
                goToFirstPage: vi.fn(async () => undefined),
                initialize: testState.initializeSpy,
                updateService: vi.fn(),
            },
        };
    },
}));

vi.mock('@/composables/useTabContentContainerInteractions', () => ({
    useTabContentContainerInteractions: () => ({
        clearHoveredContainer: vi.fn(),
        managerRef: ref(null),
        drawer: {
            actions: {
                setOpen: vi.fn(),
            },
            derived: {
                container: ref(null),
                items: ref([]),
            },
            state: {
                isOpen: ref(false),
            },
        },
    }),
}));

vi.mock('@/composables/useTabContentItemInteractions', () => ({
    useTabContentItemInteractions: (options: { masonry: { value: { remove?: (target: { id: number }) => unknown } | null } }) => ({
        preload: {
            onBatchFailures: vi.fn(),
            onBatchPreloaded: vi.fn(),
            reset: vi.fn(),
        },
        reactions: {
            onFileReaction: vi.fn((item: { id: number }) => { queueMicrotask(() => options.masonry.value?.remove?.(item)); }),
        },
        state: {
            clearHover: vi.fn(),
        },
        viewer: {
            onClose: testState.viewerOnClose,
            onOpen: testState.viewerOnOpen,
        },
    }),
}));

vi.mock('@/composables/useTabContentPromptDialog', () => ({
    useTabContentPromptDialog: () => ({
        data: {
            currentPromptData: ref(null),
            promptDataLoading: ref(false),
            promptDialogItemId: ref(null),
            promptDialogOpen: ref(false),
        },
        close: vi.fn(),
        copy: vi.fn(),
        openTestPage: vi.fn(),
        setOpen: vi.fn(),
    }),
}));

vi.mock('@/lib/browseCatalog', () => ({
    createBrowseCatalog: () => ({
        state: {
            availableServices: ref([{ key: 'civit-ai-images', label: 'CivitAI Images' }]),
            availableSources: ref([]),
            localService: ref(null),
        },
        actions: {
            loadServices: vi.fn(async () => undefined),
            loadSources: vi.fn(async () => undefined),
        },
    }),
}));

vi.mock('@/lib/tabContentV2MouseShortcuts', () => ({
    createBrowseV2MouseShortcutHandlers: () => ({
        handleAuxClickCapture: vi.fn(),
        handleClickCapture: vi.fn(),
        handleContextMenuCapture: vi.fn(),
        handleMouseDownCapture: vi.fn(),
    }),
}));

vi.mock('./TabContentV2View.vue', () => ({
    default: defineComponent({
        name: 'TabContentV2View',
        props: {
            currentVisibleItem: { type: Object, default: null },
            handleReaction: { type: Function, required: true },
            setVibeHandle: { type: Function, default: null },
            surfaceMode: { type: String, default: 'list' },
            updateActiveIndex: { type: Function, required: true },
            updateSurfaceMode: { type: Function, required: true },
        },
        setup(props) {
            const handle = {
                cancel: vi.fn(), clearRemoved: vi.fn(), getRemovedIds: () => [...status.removedIds],
                loadNext: vi.fn(async () => undefined), loadPrevious: vi.fn(async () => undefined),
                remove(target: string | string[]) {
                    const ids = Array.isArray(target) ? target : [target];
                    const nextIds = ids.filter((id) => !status.removedIds.includes(id));
                    status.removedIds = [...status.removedIds, ...nextIds];
                    status.removedCount = status.removedIds.length;

                    return { ids: nextIds };
                },
                restore(target: string | string[]) {
                    const ids = new Set(Array.isArray(target) ? target : [target]);
                    const restoredIds = status.removedIds.filter((id) => ids.has(id));
                    status.removedIds = status.removedIds.filter((id) => !ids.has(id));
                    status.removedCount = status.removedIds.length;
                    return { ids: restoredIds };
                },
                retry: vi.fn(async () => undefined),
                status,
                undo: vi.fn(),
            };

            props.setVibeHandle?.(handle);

            return () => h('div', { 'data-testid': 'tab-content-v2-view' }, [
                h('div', { 'data-testid': 'current-item-id' }, String((props.currentVisibleItem as { id?: number } | null)?.id ?? 'none')),
                h('div', { 'data-testid': 'surface-mode' }, props.surfaceMode),
                h('button', {
                    'data-testid': 'select-first',
                    onClick: () => props.updateActiveIndex(0),
                }),
                h('button', { 'data-testid': 'select-second', onClick: () => props.updateActiveIndex(1) }),
                h('button', {
                    'data-testid': 'open-fullscreen',
                    onClick: () => props.updateSurfaceMode('fullscreen'),
                }),
                h('button', { 'data-testid': 'close-fullscreen', onClick: () => props.updateSurfaceMode('list') }),
                h('button', { 'data-testid': 'react-current', onClick: () => { const current = props.currentVisibleItem as { id?: number } | null; if (current) void props.handleReaction({ feedItem: current, fileId: current.id ?? null, id: String(current.id ?? '') }, 'like'); } }),
                h('button', { 'data-testid': 'remove-second', onClick: () => handle.remove('2') }),
                h('button', { 'data-testid': 'restore-second', onClick: () => handle.restore('2') }),
            ]);
        },
    }),
}));

const status = reactive({
    activeIndex: 0, currentCursor: '1', errorMessage: null, fillCollectedCount: null, fillDelayRemainingMs: null, fillTargetCount: null,
    hasNextPage: true, hasPreviousPage: false, itemCount: 0, loadState: 'loaded' as const, mode: 'dynamic' as const, nextCursor: '2', phase: 'idle' as const,
    previousCursor: null, removedCount: 0, removedIds: [] as string[], surfaceMode: 'list' as const,
});

function createFeedItem(id: number) {
    return {
        id, width: 512, height: 512, page: 1, key: `1-${id}`, index: id - 1,
        src: `https://example.test/${id}/preview.jpg`, preview: `https://example.test/${id}/preview.jpg`,
        original: `https://example.test/${id}/original.jpg`, originalUrl: `https://example.test/${id}/original.jpg`, type: 'image' as const,
    };
}

function createFilePayload(id: number) {
    return {
        file: {
            absolute_path: null,
            absolute_preview_path: null,
            auto_dislike_rule: null,
            auto_disliked: false,
            blacklisted_at: null,
            blacklist_reason: null,
            blacklist_rule: null,
            blacklist_type: null,
            chapter: null,
            containers: [],
            created_at: '2026-04-10T00:00:00Z',
            description: null,
            detail_metadata: null,
            disk_url: `/api/files/${id}/downloaded`,
            download_progress: 0,
            downloaded: true,
            downloaded_at: null,
            ext: 'jpg',
            file_url: `/api/files/${id}/downloaded`,
            filename: `file-${id}.jpg`,
            hash: null,
            height: 768,
            id,
            listing_metadata: null,
            mime_type: 'image/jpeg',
            not_found: false,
            parent_id: null,
            path: null,
            poster_path: null,
            poster_url: null,
            preview_file_url: `/api/files/${id}/preview`,
            preview_path: null,
            preview_url: `/api/files/${id}/preview`,
            previewed_at: null,
            previewed_count: 0,
            referrer_url: null,
            seen_at: null,
            seen_count: 0,
            size: 123,
            source: 'local',
            source_id: null,
            tags: null,
            title: `File ${id}`,
            updated_at: '2026-04-10T00:00:00Z',
            url: null,
            width: 1024,
        },
    };
}

async function createTestRouter(initialPath: string) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/browse', name: 'browse', component: { template: '<div />' } },
            { path: '/browse/file/:fileId', name: 'browse-file', component: { template: '<div />' } },
        ],
    });

    await router.push(initialPath); await router.isReady();

    return router;
}

async function mountTabContent(initialPath = '/browse', updateActiveTab = vi.fn()) {
    const router = await createTestRouter(initialPath);
    const pushSpy = vi.spyOn(router, 'push'); const replaceSpy = vi.spyOn(router, 'replace');

    const wrapper = mount(TabContentV2, {
        props: {
            availableServices: [],
            onReaction: vi.fn(),
            tabId: 1,
            updateActiveTab,
        },
        global: {
            plugins: [router],
        },
    });

    await flushPromises();
    await flushPromises();

    return { pushSpy, replaceSpy, router, updateActiveTab, wrapper };
}

describe('TabContentV2 browse route sync', () => {
    beforeEach(() => {
        testState.bootstrapBehavior = 'success';
        testState.initializeSpy.mockReset();
        testState.restoredItems = [];
        testState.toastError.mockReset();
        testState.viewerOnClose.mockReset();
        testState.viewerOnOpen.mockReset();
        status.itemCount = 0; status.removedCount = 0; status.removedIds = [];
        mockAxios.delete.mockReset();
        mockAxios.get.mockReset();
        mockAxios.patch.mockReset();
        mockAxios.post.mockReset();
        mockAxios.put.mockReset();
        Object.defineProperty(window, 'axios', {
            configurable: true,
            value: mockAxios,
            writable: true,
        });
    });

    it('syncs updateActiveTab from Vibe removed ids instead of a local removal mirror', async () => {
        testState.restoredItems = [createFeedItem(1), createFeedItem(2)];
        const { updateActiveTab, wrapper } = await mountTabContent('/browse', vi.fn());
        expect(updateActiveTab).toHaveBeenLastCalledWith([
            expect.objectContaining({ id: 1 }),
            expect.objectContaining({ id: 2 }),
        ]);
        await wrapper.get('[data-testid="remove-second"]').trigger('click');
        await flushPromises();

        expect(updateActiveTab).toHaveBeenLastCalledWith([
            expect.objectContaining({ id: 1 }),
        ]);

        await wrapper.get('[data-testid="restore-second"]').trigger('click');
        await flushPromises();

        expect(updateActiveTab).toHaveBeenLastCalledWith([
            expect.objectContaining({ id: 1 }),
            expect.objectContaining({ id: 2 }),
        ]);
    });

    it('shows a loading placeholder while the browse tab is bootstrapping', async () => {
        testState.bootstrapBehavior = 'loading';

        const { wrapper } = await mountTabContent('/browse');

        expect(wrapper.find('[data-testid="tab-content-v2-view"]').exists()).toBe(false);
        expect(wrapper.get('[data-test="browse-tab-bootstrap-loading"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="browse-tab-bootstrap-status"]').text()).toBe('Loading browse tab');
    });

    it('shows a retry action instead of a blank surface when browse tab bootstrap fails', async () => {
        testState.bootstrapBehavior = 'error';

        const { wrapper } = await mountTabContent('/browse');

        expect(wrapper.find('[data-testid="tab-content-v2-view"]').exists()).toBe(false);
        expect(wrapper.get('[data-test="browse-tab-bootstrap-status"]').text()).toBe('Browse tab failed to load');

        await wrapper.get('[data-test="browse-tab-bootstrap-retry"]').trigger('click');

        expect(testState.initializeSpy).toHaveBeenCalledTimes(1);
    });

    it('pushes /browse/file/:id when a browse item opens in fullscreen', async () => {
        testState.restoredItems = [createFeedItem(1), createFeedItem(2)];

        const { pushSpy, router, wrapper } = await mountTabContent('/browse');

        await wrapper.get('[data-testid="select-second"]').trigger('click');
        await wrapper.get('[data-testid="open-fullscreen"]').trigger('click');
        await flushPromises();

        expect(pushSpy).toHaveBeenCalledWith('/browse/file/2');
        expect(router.currentRoute.value.fullPath).toBe('/browse/file/2');
    });

    it('replaces the current file route while navigating fullscreen items', async () => {
        testState.restoredItems = [createFeedItem(1), createFeedItem(2)];

        const { replaceSpy, router, wrapper } = await mountTabContent('/browse');

        await wrapper.get('[data-testid="select-second"]').trigger('click');
        await wrapper.get('[data-testid="open-fullscreen"]').trigger('click');
        await flushPromises();
        replaceSpy.mockClear();

        await wrapper.get('[data-testid="select-first"]').trigger('click');
        await flushPromises();

        expect(replaceSpy).toHaveBeenCalledWith('/browse/file/1');
        expect(router.currentRoute.value.fullPath).toBe('/browse/file/1');
    });

    it('replaces back to /browse when fullscreen closes', async () => {
        testState.restoredItems = [createFeedItem(1), createFeedItem(2)];

        const { replaceSpy, router, wrapper } = await mountTabContent('/browse');

        await wrapper.get('[data-testid="select-second"]').trigger('click');
        await wrapper.get('[data-testid="open-fullscreen"]').trigger('click');
        await flushPromises();
        replaceSpy.mockClear();

        await wrapper.get('[data-testid="close-fullscreen"]').trigger('click');
        await flushPromises();

        expect(replaceSpy).toHaveBeenCalledWith('/browse');
        expect(router.currentRoute.value.fullPath).toBe('/browse');
    });

    it('opens a direct file route in the active tab context when the file is already restored', async () => {
        testState.restoredItems = [createFeedItem(1), createFeedItem(2)];

        const { wrapper } = await mountTabContent('/browse/file/2');

        expect(wrapper.get('[data-testid="current-item-id"]').text()).toBe('2');
        expect(wrapper.get('[data-testid="surface-mode"]').text()).toBe('fullscreen');
        expect(mockAxios.get).not.toHaveBeenCalledWith('/api/files/2');
    });

    it('falls back to a standalone fullscreen session for a direct file route outside the active tab context', async () => {
        testState.restoredItems = [createFeedItem(1), createFeedItem(2)];
        mockAxios.get.mockImplementation(async (url: string) => {
            if (url === '/api/files/99') {
                return { data: createFilePayload(99) };
            }

            throw new Error(`Unexpected GET ${url}`);
        });

        const { router, wrapper } = await mountTabContent('/browse/file/99');

        expect(wrapper.get('[data-testid="current-item-id"]').text()).toBe('99');
        expect(wrapper.get('[data-testid="surface-mode"]').text()).toBe('fullscreen');

        await wrapper.get('[data-testid="close-fullscreen"]').trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.fullPath).toBe('/browse');
    });

    it('advances fullscreen and removes the reacted item from restored session visibility', async () => {
        testState.restoredItems = [createFeedItem(1), createFeedItem(2), createFeedItem(3)];
        const { replaceSpy, router, updateActiveTab, wrapper } = await mountTabContent('/browse');
        await wrapper.get('[data-testid="select-second"]').trigger('click'); await wrapper.get('[data-testid="open-fullscreen"]').trigger('click'); await flushPromises();
        replaceSpy.mockClear();
        expect(wrapper.get('[data-testid="current-item-id"]').text()).toBe('2');
        await wrapper.get('[data-testid="react-current"]').trigger('click'); await flushPromises(); await flushPromises();
        expect(wrapper.get('[data-testid="current-item-id"]').text()).toBe('3');
        expect(updateActiveTab).toHaveBeenLastCalledWith([expect.objectContaining({ id: 1 }), expect.objectContaining({ id: 3 })]);
        expect(replaceSpy).toHaveBeenCalledWith('/browse/file/3'); expect(router.currentRoute.value.fullPath).toBe('/browse/file/3');
    });

    it('does not force /browse while fullscreen is waiting for the next page after removing the last visible item', async () => {
        testState.restoredItems = [createFeedItem(1)];
        const { replaceSpy, router, wrapper } = await mountTabContent('/browse');
        await wrapper.get('[data-testid="select-first"]').trigger('click'); await wrapper.get('[data-testid="open-fullscreen"]').trigger('click'); await flushPromises();
        replaceSpy.mockClear();
        await wrapper.get('[data-testid="react-current"]').trigger('click'); await flushPromises(); await flushPromises();
        expect(replaceSpy).not.toHaveBeenCalledWith('/browse');
        expect(router.currentRoute.value.fullPath).toBe('/browse/file/1');
    });
});
