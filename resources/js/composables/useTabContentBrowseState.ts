import { computed, nextTick, onMounted, ref, type ComputedRef, type Ref, type ShallowRef } from 'vue';
import type { PageToken } from '@wyxos/vibe';
import { useToast } from 'vue-toastification';
import { index as browseIndex } from '@/actions/App/Http/Controllers/BrowseController';
import { show as tabsShow } from '@/actions/App/Http/Controllers/TabController';
import { getLocalPresetLabel } from '@/lib/localPresets';
import type { BrowseFormData, BrowseFormInstance } from './useBrowseForm';
import type { ServiceOption } from './useBrowseService';
import type { FeedItem, TabData } from './useTabs';
import { appendBrowseServiceFilters } from '@/utils/browseQuery';

type UseTabContentBrowseStateOptions = {
    tabId: Ref<number | null>;
    form: BrowseFormInstance;
    data: {
        items: ShallowRef<FeedItem[]>;
        tab: Ref<TabData | null>;
    };
    services: {
        availableServices: ComputedRef<ServiceOption[]>;
        localService?: Ref<ServiceOption | null | undefined>;
        fetchServices: () => Promise<void>;
        fetchSources: () => Promise<void>;
    };
    view: {
        clearPreviewedItems: () => void;
        resetPreloadedItems: () => void;
    };
    events: {
        onPageLoadingChange: (isLoading: boolean) => void;
        onTabDataLoadingChange?: (isLoading: boolean) => void;
        onUpdateTabLabel?: (label: string) => void;
    };
};

type TabContentBrowseStateRefs = {
    totalAvailable: Ref<number | null>;
    masonryRenderKey: Ref<number>;
    startPageToken: Ref<PageToken>;
    shouldShowForm: Ref<boolean>;
};

function normalizeContainerValue(value: unknown): string | null {
    if (typeof value === 'string') {
        const trimmed = value.trim();

        return trimmed.length > 0 ? trimmed : null;
    }

    if (typeof value === 'number') {
        return Number.isFinite(value) ? String(value) : null;
    }

    return null;
}

function normalizeTotal(value: unknown): number | null {
    if (typeof value === 'number') {
        return value;
    }

    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : null;
}

function getContainerLabelFromFilters(formData: BrowseFormData): string | null {
    if (formData.feed !== 'online') {
        return null;
    }

    if (formData.service === 'civit-ai-images') {
        const username = normalizeContainerValue(formData.serviceFilters?.username);
        if (username) {
            return `User ${username}`;
        }

        const postId = normalizeContainerValue(formData.serviceFilters?.postId);
        if (postId) {
            return `Post ${postId}`;
        }
    }

    return null;
}

function formatTabLabel(serviceLabel: string, pageToken: PageToken, containerLabel?: string | null): string {
    const prefix = containerLabel ? `${serviceLabel}: ${containerLabel}` : serviceLabel;

    return `${prefix} - ${String(pageToken)}`;
}

function normalizeLocalPage(form: BrowseFormInstance): number {
    const raw = form.data.page;
    const page = typeof raw === 'number' ? raw : Number(raw);

    if (!Number.isFinite(page) || page < 1) {
        return 1;
    }

    return Math.floor(page);
}

function resetBrowseResults(
    state: TabContentBrowseStateRefs,
    options: UseTabContentBrowseStateOptions,
    nextStart: PageToken,
): void {
    state.shouldShowForm.value = false;
    state.totalAvailable.value = null;
    options.view.clearPreviewedItems();
    options.data.items.value = [];
    options.view.resetPreloadedItems();
    state.startPageToken.value = nextStart;
    state.masonryRenderKey.value += 1;
}

function createTabContentPageLoader(args: {
    form: BrowseFormInstance;
    services: UseTabContentBrowseStateOptions['services'];
    totalAvailable: Ref<number | null>;
    toast: ReturnType<typeof useToast>;
    events: UseTabContentBrowseStateOptions['events'];
}) {
    function updateTabLabel(formData: BrowseFormData, page: PageToken): void {
        if (!args.events.onUpdateTabLabel) {
            return;
        }

        if (formData.feed === 'online' && !formData.service) {
            return;
        }

        const baseServiceLabel = formData.feed === 'local'
            ? (args.services.localService?.value?.label ?? 'Local')
            : (
                args.services.availableServices.value.find((service) => service.key === formData.service)?.label
                ?? formData.service
            );

        const localPresetLabel = formData.feed === 'local'
            ? getLocalPresetLabel(formData.serviceFilters?.local_preset)
            : null;
        const serviceLabel = localPresetLabel ? `${baseServiceLabel} - ${localPresetLabel}` : baseServiceLabel;
        const containerLabel = getContainerLabelFromFilters(formData);

        args.events.onUpdateTabLabel(formatTabLabel(serviceLabel, page, containerLabel));
    }

    async function getPage(page: PageToken, context?: BrowseFormData) {
        const formData = context ?? args.form.getData();
        const params: Record<string, unknown> = {
            feed: formData.feed,
            tab_id: formData.tab_id,
            page,
            limit: formData.limit,
        };

        if (formData.feed === 'online') {
            params.service = formData.service;
        } else {
            params.source = formData.source;
        }

        appendBrowseServiceFilters(params, formData.serviceFilters);
        updateTabLabel(formData, page);

        args.events.onPageLoadingChange(true);

        try {
            const { data } = await window.axios.get(browseIndex.url({ query: params }));

            args.totalAvailable.value = normalizeTotal(data.total);

            return {
                items: data.items || [],
                nextPage: data.nextPage,
            };
        } catch (error: unknown) {
            const err = error as { message?: unknown; response?: { data?: { message?: unknown } } };
            const message =
                (typeof err?.response?.data?.message === 'string' ? err.response.data.message : null)
                || (typeof err?.message === 'string' ? err.message : null)
                || 'Browse request failed.';
            const trimmed = message.length > 280 ? `${message.slice(0, 280)}…` : message;

            args.toast.error(trimmed);
            console.error('Browse request failed', { params, error });
            args.totalAvailable.value = null;

            return {
                items: [],
                nextPage: null,
            };
        } finally {
            args.events.onPageLoadingChange(false);
        }
    }

    return {
        getPage,
    };
}

function createTabContentBootstrap(args: {
    options: UseTabContentBrowseStateOptions;
    state: TabContentBrowseStateRefs;
    updateService: (nextService: string) => void;
}) {
    async function initialize(): Promise<void> {
        if (!args.options.tabId.value) {
            return;
        }

        args.options.events.onTabDataLoadingChange?.(true);

        try {
            const { data } = await window.axios.get(tabsShow.url(args.options.tabId.value));

            if (data.tab) {
                args.options.data.tab.value = data.tab;
                args.options.form.syncFromTab(args.options.data.tab.value ?? undefined);

                const params = (args.options.data.tab.value?.params ?? {}) as Record<string, unknown>;
                const itemsToRestore = Array.isArray(data.tab.items) ? data.tab.items : [];
                const hasRestoredItems = itemsToRestore.length > 0;
                const hasMeaningfulParams = Object.keys(params).length > 0;

                if (hasRestoredItems || hasMeaningfulParams) {
                    args.state.shouldShowForm.value = false;

                    const savedNextToken = params.page as PageToken | null | undefined;

                    args.options.data.items.value = itemsToRestore as FeedItem[];
                    args.options.view.resetPreloadedItems();
                    args.state.startPageToken.value = (savedNextToken ?? 1) as PageToken;
                    args.state.masonryRenderKey.value += 1;

                    await nextTick();
                }
            }

            await args.options.services.fetchServices();

            if (args.options.form.data.feed === 'online' && !args.options.form.data.service) {
                const legacyCandidate = args.options.data.tab.value?.params?.source;

                if (typeof legacyCandidate === 'string' && legacyCandidate.length > 0) {
                    const isKnownService = args.options.services.availableServices.value
                        .some((service) => service.key === legacyCandidate);

                    if (isKnownService) {
                        args.updateService(legacyCandidate);
                        args.options.form.data.source = 'all';
                    }
                }
            }

            await args.options.services.fetchSources();
        } finally {
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
    const startPageToken = ref<PageToken>(1);
    const shouldShowForm = ref(true);

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
        const defaults = options.services.availableServices.value.find((service) => service.key === nextService)?.defaults;
        options.form.setService(nextService, defaults);
    }
    const state: TabContentBrowseStateRefs = {
        totalAvailable,
        masonryRenderKey,
        startPageToken,
        shouldShowForm,
    };
    const loader = createTabContentPageLoader({
        form: options.form,
        services: options.services,
        totalAvailable,
        toast,
        events: options.events,
    });
    const bootstrap = createTabContentBootstrap({
        options,
        state,
        updateService,
    });

    async function applyFilters(): Promise<void> {
        const nextStart: PageToken = options.form.data.feed === 'local' ? normalizeLocalPage(options.form) : 1;

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
        },
        derived: {
            selectedService,
            currentTabService,
            hasServiceSelected,
        },
        actions: {
            updateService,
            getPage: loader.getPage,
            applyFilters,
            goToFirstPage,
            applyService,
        },
        formatters: {
            formatTabLabel,
        },
    };
}
