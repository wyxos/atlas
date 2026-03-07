import { computed, nextTick, onMounted, ref, type ComputedRef, type Ref, type ShallowRef } from 'vue';
import type { MasonryRestoredPages, PageToken } from '@wyxos/vibe';
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
    items: ShallowRef<FeedItem[]>;
    tab: Ref<TabData | null>;
    availableServices: ComputedRef<ServiceOption[]>;
    localService?: Ref<ServiceOption | null | undefined>;
    fetchServices: () => Promise<void>;
    fetchSources: () => Promise<void>;
    clearPreviewedItems: () => void;
    resetPreloadedItems: () => void;
    onLoadingStart: () => void;
    onLoadingStop: () => void;
    onUpdateTabLabel?: (label: string) => void;
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

export function useTabContentBrowseState(options: UseTabContentBrowseStateOptions) {
    const toast = useToast();

    const totalAvailable = ref<number | null>(null);
    const masonryRenderKey = ref(0);
    const startPageToken = ref<PageToken>(1);
    const restoredPages = ref<MasonryRestoredPages | null>(null);
    const loadAtPage = ref<number | string | null>(null);
    const isTabRestored = ref(false);
    const shouldShowForm = ref(true);

    const selectedService = computed({
        get: () => options.form.data.service,
        set: (value: string) => {
            options.form.data.service = value;
        },
    });

    const currentTabService = computed(() => {
        const fromTab = options.tab.value?.params?.service;

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
        const defaults = options.availableServices.value.find((service) => service.key === nextService)?.defaults;
        options.form.setService(nextService, defaults);
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

    function resetBrowseResults(nextStart: PageToken): void {
        shouldShowForm.value = false;
        options.clearPreviewedItems();
        options.items.value = [];
        options.resetPreloadedItems();
        restoredPages.value = null;
        startPageToken.value = nextStart;
        masonryRenderKey.value += 1;
    }

    function normalizeLocalPage(): number {
        const raw = options.form.data.page;
        const page = typeof raw === 'number' ? raw : Number(raw);

        if (!Number.isFinite(page) || page < 1) {
            return 1;
        }

        return Math.floor(page);
    }

    async function getPage(page: PageToken, context?: BrowseFormData) {
        const formData = context ?? options.form.getData();
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

        if (
            options.onUpdateTabLabel
            && (formData.feed === 'local' || (formData.feed === 'online' && formData.service))
        ) {
            const baseServiceLabel = formData.feed === 'local'
                ? (options.localService?.value?.label ?? 'Local')
                : (options.availableServices.value.find((service) => service.key === formData.service)?.label ?? formData.service);

            const localPresetLabel = formData.feed === 'local'
                ? getLocalPresetLabel(formData.serviceFilters?.local_preset)
                : null;

            const serviceLabel = localPresetLabel ? `${baseServiceLabel} - ${localPresetLabel}` : baseServiceLabel;
            const containerLabel = getContainerLabelFromFilters(formData);
            options.onUpdateTabLabel(formatTabLabel(serviceLabel, page, containerLabel));
        }

        options.onLoadingStart();

        try {
            const { data } = await window.axios.get(browseIndex.url({ query: params }));

            totalAvailable.value = normalizeTotal(data.total);

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

            toast.error(trimmed);
            console.error('Browse request failed', { params, error });
            totalAvailable.value = null;

            return {
                items: [],
                nextPage: null,
            };
        } finally {
            options.onLoadingStop();
        }
    }

    async function applyFilters(): Promise<void> {
        const nextStart: PageToken = options.form.data.feed === 'local' ? normalizeLocalPage() : 1;

        options.form.data.page = nextStart;
        resetBrowseResults(nextStart);
        await nextTick();
    }

    async function goToFirstPage(): Promise<void> {
        options.form.data.page = 1;
        await applyFilters();
    }

    async function applyService(): Promise<void> {
        resetBrowseResults(1);
    }

    async function initialize(): Promise<void> {
        if (!options.tabId.value) {
            return;
        }

        const { data } = await window.axios.get(tabsShow.url(options.tabId.value));

        if (data.tab) {
            options.tab.value = data.tab;
            options.form.syncFromTab(options.tab.value ?? undefined);

            const params = (options.tab.value?.params ?? {}) as Record<string, unknown>;
            const itemsToRestore = Array.isArray(data.tab.items) ? data.tab.items : [];
            const hasRestoredItems = itemsToRestore.length > 0;
            const hasMeaningfulParams = Object.keys(params).length > 0;
            const shouldRestoreUi = hasRestoredItems || hasMeaningfulParams;

            if (shouldRestoreUi) {
                shouldShowForm.value = false;
                isTabRestored.value = hasRestoredItems;

                const savedNextToken = params.page as PageToken | null | undefined;

                options.items.value = itemsToRestore as FeedItem[];
                options.resetPreloadedItems();
                restoredPages.value = null;
                startPageToken.value = (savedNextToken ?? 1) as PageToken;
                masonryRenderKey.value += 1;

                await nextTick();
            }
        }

        await options.fetchServices();

        if (options.form.data.feed === 'online' && !options.form.data.service) {
            const legacyCandidate = options.tab.value?.params?.source;

            if (typeof legacyCandidate === 'string' && legacyCandidate.length > 0) {
                const isKnownService = options.availableServices.value.some((service) => service.key === legacyCandidate);

                if (isKnownService) {
                    updateService(legacyCandidate);
                    options.form.data.source = 'all';
                }
            }
        }

        await options.fetchSources();
    }

    onMounted(() => {
        void initialize();
    });

    return {
        state: {
            totalAvailable,
            masonryRenderKey,
            startPageToken,
            restoredPages,
            loadAtPage,
            isTabRestored,
            shouldShowForm,
        },
        derived: {
            selectedService,
            currentTabService,
            hasServiceSelected,
        },
        actions: {
            updateService,
            getPage,
            applyFilters,
            goToFirstPage,
            applyService,
        },
        formatters: {
            formatTabLabel,
        },
    };
}
