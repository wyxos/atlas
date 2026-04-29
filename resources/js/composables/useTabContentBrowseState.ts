import { computed, nextTick, onMounted, ref, type ComputedRef, type Ref, type ShallowRef } from 'vue';
import { useToast } from '@/components/ui/toast/use-toast';
import { show as tabsShow } from '@/actions/App/Http/Controllers/TabController';
import type { ServiceOption } from '@/lib/browseCatalog';
import { extractRestoredBrowseSession, resolveLegacyBrowseService } from '@/lib/tabContentBrowseBootstrap';
import type { BrowsePageToken } from '@/types/browse';
import type { BrowseFormInstance } from './useBrowseForm';
import type { FeedItem, TabData } from './useTabs';

const NO_CACHE_REQUEST_CONFIG = {
    headers: {
        'Cache-Control': 'no-cache',
        Pragma: 'no-cache',
    },
};

type UseTabContentBrowseStateOptions = {
    tabId: Ref<number | null>;
    form: BrowseFormInstance;
    data: {
        items: ShallowRef<FeedItem[]>;
        tab: Ref<TabData | null>;
    };
    catalog: {
        availableServices: ComputedRef<ServiceOption[]>;
        localService?: Ref<ServiceOption | null | undefined>;
        loadServices: () => Promise<void>;
        loadSources: () => Promise<void>;
    };
    view: {
        clearPreviewedItems: () => void;
        resetPreloadedItems: () => void;
    };
    events: {
        onTabDataLoadingChange?: (isLoading: boolean) => void;
        onUpdateTabLabel?: (label: string) => void;
    };
};

type TabContentBrowseStateRefs = {
    totalAvailable: Ref<number | null>;
    masonryRenderKey: Ref<number>;
    startPageToken: Ref<BrowsePageToken>;
    shouldShowForm: Ref<boolean>;
    isInitializing: Ref<boolean>;
    bootstrapFailed: Ref<boolean>;
};

function normalizeLocalPage(form: BrowseFormInstance): number {
    const page = Number(form.data.page);

    if (!Number.isFinite(page) || page < 1) {
        return 1;
    }

    return Math.floor(page);
}

function resetBrowseResults(
    state: TabContentBrowseStateRefs,
    options: UseTabContentBrowseStateOptions,
    nextStart: BrowsePageToken,
): void {
    state.shouldShowForm.value = false;
    state.totalAvailable.value = null;
    options.view.clearPreviewedItems();
    options.data.items.value = [];
    options.view.resetPreloadedItems();
    state.startPageToken.value = nextStart;
    state.masonryRenderKey.value += 1;
}

function createTabContentBootstrap(args: {
    options: UseTabContentBrowseStateOptions;
    state: TabContentBrowseStateRefs;
    toast: ReturnType<typeof useToast>;
    updateService: (nextService: string) => void;
}) {
    async function restoreTabState(): Promise<void> {
        const restoredSession = extractRestoredBrowseSession(args.options.data.tab.value);

        if (!restoredSession) {
            return;
        }

        args.state.shouldShowForm.value = false;
        args.options.data.items.value = restoredSession.items;
        args.options.view.resetPreloadedItems();
        args.options.form.data.page = restoredSession.startPageToken;
        args.state.startPageToken.value = restoredSession.startPageToken;
        args.state.masonryRenderKey.value += 1;

        await nextTick();
    }

    function applyLegacyServiceFallback(): void {
        const serviceKey = resolveLegacyBrowseService(
            args.options.form.data,
            args.options.data.tab.value,
            args.options.catalog.availableServices.value,
        );

        if (!serviceKey) {
            return;
        }

        args.updateService(serviceKey);
        args.options.form.data.source = 'all';
    }

    async function initialize(): Promise<void> {
        if (!args.options.tabId.value) {
            args.state.isInitializing.value = false;
            args.state.bootstrapFailed.value = false;
            return;
        }

        args.state.isInitializing.value = true;
        args.state.bootstrapFailed.value = false;
        args.options.events.onTabDataLoadingChange?.(true);

        try {
            const { data } = await window.axios.get(tabsShow.url(args.options.tabId.value), NO_CACHE_REQUEST_CONFIG);

            if (!data.tab) {
                const message = 'Browse tab not found.';

                args.state.bootstrapFailed.value = true;
                args.toast.error(message);
                console.error('Failed to initialize browse tab:', new Error(message));
                return;
            }

            args.options.data.tab.value = data.tab;
            args.options.form.syncFromTab(args.options.data.tab.value ?? undefined);
            await restoreTabState();
            await args.options.catalog.loadServices();
            applyLegacyServiceFallback();
            await args.options.catalog.loadSources();
        } catch (error: unknown) {
            const err = error as { message?: unknown; response?: { data?: { message?: unknown } } };
            const message =
                (typeof err?.response?.data?.message === 'string' ? err.response.data.message : null)
                || (typeof err?.message === 'string' ? err.message : null)
                || 'Failed to load browse tab.';
            const trimmed = message.length > 280 ? `${message.slice(0, 280)}…` : message;

            args.state.bootstrapFailed.value = true;
            args.toast.error(trimmed);
            console.error('Failed to initialize browse tab:', error);
        } finally {
            args.state.isInitializing.value = false;
            args.options.events.onTabDataLoadingChange?.(false);
        }
    }

    return {
        initialize,
    };
}

export function useTabContentBrowseState(options: UseTabContentBrowseStateOptions) {
    const toast = useToast();

    const totalAvailable = ref<number | null>(null);
    const masonryRenderKey = ref(0);
    const startPageToken = ref<BrowsePageToken>(1);
    const shouldShowForm = ref(true);
    const isInitializing = ref(true);
    const bootstrapFailed = ref(false);

    const selectedService = computed({
        get: () => options.form.data.service,
        set: (value: string) => {
            options.form.data.service = value;
        },
    });

    const currentTabService = computed(() => {
        const fromTab = options.data.tab.value?.params?.service;

        return (typeof fromTab === 'string' && fromTab.length > 0)
            ? fromTab
            : (options.form.data.service || null);
    });

    const hasServiceSelected = computed(() => {
        if (options.form.data.feed === 'online') {
            return Boolean(options.form.data.service);
        }

        return true;
    });

    function updateService(nextService: string): void {
        const defaults = options.catalog.availableServices.value.find((service) => service.key === nextService)?.defaults;
        options.form.setService(nextService, defaults);
    }
    const state: TabContentBrowseStateRefs = {
        totalAvailable,
        masonryRenderKey,
        startPageToken,
        shouldShowForm,
        isInitializing,
        bootstrapFailed,
    };
    const bootstrap = createTabContentBootstrap({
        options,
        state,
        toast,
        updateService,
    });

    async function applyFilters(): Promise<void> {
        const nextStart: BrowsePageToken = options.form.data.feed === 'local' ? normalizeLocalPage(options.form) : 1;

        options.form.data.page = nextStart;
        resetBrowseResults(state, options, nextStart);
        await nextTick();
    }

    async function goToFirstPage(): Promise<void> {
        options.form.data.page = 1;
        await applyFilters();
    }

    async function applyService(): Promise<void> {
        resetBrowseResults(state, options, 1);
        await nextTick();
    }

    onMounted(() => {
        void bootstrap.initialize();
    });

    return {
        state: {
            totalAvailable,
            masonryRenderKey,
            startPageToken,
            shouldShowForm,
            isInitializing,
            bootstrapFailed,
        },
        derived: {
            selectedService,
            currentTabService,
            hasServiceSelected,
        },
        actions: {
            initialize: bootstrap.initialize,
            updateService,
            applyFilters,
            goToFirstPage,
            applyService,
        },
    };
}
