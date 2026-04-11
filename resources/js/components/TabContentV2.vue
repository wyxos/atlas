<script setup lang="ts">
import { computed, provide, reactive, ref, shallowRef, toRef, watch, watchEffect } from 'vue';
import { useRoute } from 'vue-router';
import { type VibeAssetErrorEvent, type VibeAssetLoadEvent, type VibeHandle, type VibeInitialState, type VibeViewerItem } from '@wyxos/vibe';
import { useToast } from '@/components/ui/toast/use-toast';
import { useBrowseV2SurfaceRouteSync } from '@/composables/useBrowseV2SurfaceRouteSync';
import { createBrowseForm, BrowseFormKey } from '@/composables/useBrowseForm';
import { useDownloadedReactionPrompt } from '@/composables/useDownloadedReactionPrompt';
import { useFileViewerData } from '@/composables/useFileViewerData';
import { useFileViewerSheetState } from '@/composables/useFileViewerSheetState';
import { useItemPreview } from '@/composables/useItemPreview';
import { useLocalFileDeletion } from '@/composables/useLocalFileDeletion';
import { useTabContentBrowseState } from '@/composables/useTabContentBrowseState';
import { useTabContentContainerInteractions } from '@/composables/useTabContentContainerInteractions';
import { useTabContentItemInteractions } from '@/composables/useTabContentItemInteractions';
import { useTabContentPromptDialog } from '@/composables/useTabContentPromptDialog';
import type { ServiceOption } from '@/lib/browseCatalog';
import { createBrowseCatalog } from '@/lib/browseCatalog';
import { loadBrowseV2StandaloneFileItem } from '@/lib/browseV2StandaloneItem';
import { buildBrowseTabLabel } from '@/lib/browseTabLabel';
import { extractRestoredBrowseSession } from '@/lib/tabContentBrowseBootstrap';
import { filterItemsByContainerBlacklists, removeContainerBlacklist, upsertContainerBlacklist } from '@/lib/tabContentV2Blacklists';
import { createTabContentV2EmptyStatus, createTabContentV2Resolve, mapFeedItemToVibeItem, normalizeCursor, resolveOverlayMediaType, type OverlayMediaType } from '@/lib/tabContentV2';
import { createBrowseV2MouseShortcutHandlers } from '@/lib/tabContentV2MouseShortcuts';
import type { FeedItem, TabData } from '@/composables/useTabs';
import type { ReactionType } from '@/types/reaction';
import type { ContainerBlacklist } from '@/types/container-blacklist';
import type { BrowseFeedHandle } from '@/types/browse';
import { isPositiveOnlyLocalView, matchesLocalViewFilters } from '@/utils/localReactionState';
import TabContentV2BootstrapState from './TabContentV2BootstrapState.vue';
import TabContentV2View from './TabContentV2View.vue';

interface Props {
    tabId: number | null;
    availableServices: ServiceOption[];
    onReaction: (fileId: number, type: ReactionType) => void;
    onLoadingChange?: (isLoading: boolean) => void;
    onTabDataLoadingChange?: (isLoading: boolean) => void;
    updateActiveTab: (items: FeedItem[]) => void;
    onOpenContainerTab?: (payload: { label: string; params: Record<string, unknown> }) => void;
    onUpdateTabLabel?: (label: string) => void;
}

const props = defineProps<Props>();
const emit = defineEmits<{ 'update:loading': [isLoading: boolean] }>();

const toast = useToast();
const route = useRoute();
const tabId = toRef(props, 'tabId');
const items = shallowRef<FeedItem[]>([]);
const itemsBuckets = ref<Array<{ cursor: string | null; items: FeedItem[]; nextCursor: string | null; previousCursor: string | null }>>([]);
const removedIds = ref<Set<number>>(new Set());
const vibeRef = ref<VibeHandle | null>(null);
const tab = ref<TabData | null>(null);
const activeIndex = ref(0);
const surfaceMode = ref<'fullscreen' | 'list'>('list');
const standaloneItem = ref<FeedItem | null>(null);
const isSessionReady = ref(false);
const isTabDataLoading = ref(true);
const form = createBrowseForm();
provide(BrowseFormKey, form);

const browseCatalog = createBrowseCatalog();
const browseCatalogState = browseCatalog.state;
const availableServices = computed(() => (props.availableServices.length > 0 ? props.availableServices : browseCatalogState.availableServices.value));
const availableSources = browseCatalogState.availableSources;
const localService = browseCatalogState.localService;
const itemPreview = useItemPreview(items, computed(() => tab.value ?? undefined));
const downloadedReactionPrompt = useDownloadedReactionPrompt();
const promptDialog = useTabContentPromptDialog(items);
const emptyStatus = createTabContentV2EmptyStatus();
const vibeStatus = computed(() => vibeRef.value?.status ?? emptyStatus);
const isVibeLoading = computed(() => vibeStatus.value.phase === 'loading'
    || vibeStatus.value.phase === 'filling'
    || vibeStatus.value.phase === 'reloading'
    || vibeStatus.value.loadState === 'loading');
const hasRouteFileSelection = computed(() => route.name === 'browse-file');

function matchesActiveLocalFilters(item: FeedItem): boolean {
    return !form.isLocal.value || matchesLocalViewFilters(item, form.data.serviceFilters);
}

function isActivePositiveOnlyLocalView(): boolean {
    return form.isLocal.value && isPositiveOnlyLocalView(form.data.serviceFilters);
}

function collectTargetIds(target: FeedItem | FeedItem[] | string | string[]): string[] {
    const values = Array.isArray(target) ? target : [target];

    return values
        .map((value) => {
            if (typeof value === 'string') {
                return value;
            }

            return String(value.id);
        })
        .filter((value) => value.trim().length > 0);
}

const vibeMasonry = computed<BrowseFeedHandle | null>(() => {
    const handle = vibeRef.value;
    if (!handle) {
        return null;
    }

    return {
        cancel: () => handle.cancel(),
        hasReachedEnd: !vibeStatus.value.hasNextPage,
        isLoading: isVibeLoading.value,
        loadNextPage: async () => {
            await handle.loadNext();
        },
        nextPage: vibeStatus.value.nextCursor,
        remove: async (target: FeedItem | FeedItem[] | string | string[]) => {
            const ids = collectTargetIds(target);
            const result = handle.remove(ids);
            syncRemovedIds(result?.ids ?? ids, 'remove');
            return result;
        },
        restore: async (target: FeedItem | FeedItem[] | string | string[]) => {
            const ids = collectTargetIds(target);
            const result = handle.restore(ids);
            syncRemovedIds(result?.ids ?? ids, 'restore');
            return result;
        },
    };
});

const containerInteractions = useTabContentContainerInteractions({
    items,
    tab,
    form,
    masonry: vibeMasonry,
    matchesActiveLocalFilters,
    onReaction: props.onReaction,
    onOpenContainerTab: props.onOpenContainerTab,
});
const fileViewerStub = ref<{ openFromClick: (event: MouseEvent) => void } | null>({
    openFromClick: () => undefined,
});
const itemInteractions = useTabContentItemInteractions({
    items,
    tab,
    form,
    masonry: vibeMasonry,
    fileViewer: fileViewerStub,
    matchesActiveLocalFilters,
    isPositiveOnlyLocalView: isActivePositiveOnlyLocalView,
    itemPreview,
    onReaction: props.onReaction,
    promptDownloadedReaction: downloadedReactionPrompt.prompt,
    clearHoveredContainer: containerInteractions.clearHoveredContainer,
});

const browse = useTabContentBrowseState({
    tabId,
    form,
    data: { items, tab },
    catalog: {
        availableServices,
        localService,
        loadServices: browseCatalog.actions.loadServices,
        loadSources: browseCatalog.actions.loadSources,
    },
    view: {
        clearPreviewedItems: () => itemPreview.clearPreviewedItems(),
        resetPreloadedItems: () => itemInteractions.preload.reset(),
    },
    events: {
        onPageLoadingChange: setTabDataLoading,
        onTabDataLoadingChange: setTabDataLoading,
        onUpdateTabLabel: props.onUpdateTabLabel,
    },
});

const browseState = browse.state;
const browseActions = browse.actions;
const shouldShowForm = browseState.shouldShowForm;
const isTabBootstrapping = browseState.isInitializing;
const hasTabBootstrapError = computed(() => !isTabBootstrapping.value && browseState.bootstrapFailed.value);
const masonryRenderKey = browseState.masonryRenderKey;
const isFilterSheetOpen = ref(false);
const hydratedInitialState = ref<VibeInitialState | undefined>(undefined);
const localFileDeletion = useLocalFileDeletion({
    items,
    masonry: vibeMasonry,
    isLocal: form.isLocal,
    totalAvailable: browseState.totalAvailable,
    clearHover: itemInteractions.state.clearHover,
});

const currentNavigation = reactive({ currentItemIndex: 0 as number | null });
const fullscreenOverlayState = reactive({
    fillComplete: true,
    isClosing: false,
    mediaType: 'image' as OverlayMediaType,
});
const fileViewerSheet = useFileViewerSheetState({ overlay: fullscreenOverlayState });
const fileSheetState = fileViewerSheet.sheetState;
const visibleItems = computed(() => items.value.filter((item) => !removedIds.value.has(item.id)));
const sessionItems = computed(() => standaloneItem.value ? [standaloneItem.value] : visibleItems.value);
const vibeFeedMode = computed(() => {
    if (standaloneItem.value) {
        return 'static';
    }

    return form.data.feed === 'local' ? 'static' : 'dynamic';
});
const vibeInitialCursor = computed(() => (standaloneItem.value ? null : normalizeCursor(browseState.startPageToken.value)));
const vibeInitialState = computed<VibeInitialState | undefined>(() => {
    if (standaloneItem.value) {
        return {
            items: [mapFeedItemToVibeItem(standaloneItem.value)],
            cursor: null,
            nextCursor: null,
            previousCursor: null,
            activeIndex: 0,
        };
    }

    return hydratedInitialState.value;
});
const viewerKey = computed(() => `${tab.value?.id ?? 'tab'}-${masonryRenderKey.value}-${standaloneItem.value?.id ?? 'feed'}`);
const fileViewerData = useFileViewerData({
    items: sessionItems,
    navigation: currentNavigation,
    overlay: fullscreenOverlayState,
    sheet: fileSheetState,
});
const currentVisibleItem = computed(() => {
    if (sessionItems.value.length === 0) {
        return null;
    }
    const safeIndex = Math.max(0, Math.min(activeIndex.value, sessionItems.value.length - 1));
    return sessionItems.value[safeIndex] ?? null;
});
const headerMasonry = vibeMasonry;

function setTabDataLoading(isLoading: boolean): void {
    isTabDataLoading.value = isLoading;
    props.onTabDataLoadingChange?.(isLoading);
}
function setVibeHandle(handle: VibeHandle | null): void { vibeRef.value = handle; }

function syncRemovedIds(ids: string[], mode: 'remove' | 'restore' | 'undo' = 'remove'): void {
    const next = new Set(removedIds.value);
    for (const id of ids) {
        const parsed = Number(id);
        if (!Number.isFinite(parsed)) {
            continue;
        }

        if (mode === 'restore' || mode === 'undo') {
            next.delete(parsed);
        } else {
            next.add(parsed);
        }
    }
    removedIds.value = next;
}

function resetLocalFeedState(): void {
    items.value = [];
    itemsBuckets.value = [];
    removedIds.value = new Set();
    fileViewerSheet.setSheetOpen(false, { persist: false });
}

function applyRestoredSession(): void {
    isSessionReady.value = false;
    resetLocalFeedState();
    hydratedInitialState.value = undefined;

    if (!hasRouteFileSelection.value) {
        standaloneItem.value = null;
        surfaceMode.value = 'list';
        activeIndex.value = 0;
    }

    const restored = extractRestoredBrowseSession(tab.value);
    if (!restored || restored.items.length === 0) {
        isSessionReady.value = true;
        return;
    }

    hydratedInitialState.value = {
        items: restored.items.map(mapFeedItemToVibeItem),
        cursor: normalizeCursor(restored.cursor),
        nextCursor: normalizeCursor(restored.nextCursor),
        previousCursor: normalizeCursor(restored.previousCursor),
        activeIndex: restored.activeIndex,
    };

    itemsBuckets.value = [{
        cursor: normalizeCursor(restored.cursor),
        items: restored.items,
        nextCursor: normalizeCursor(restored.nextCursor),
        previousCursor: normalizeCursor(restored.previousCursor),
    }];
    items.value = [...restored.items];
    activeIndex.value = restored.activeIndex;
    updateTabLabel(restored.cursor);
    isSessionReady.value = true;
}

function updateTabLabel(cursor: string | number | null | undefined): void {
    if (!props.onUpdateTabLabel) {
        return;
    }

    const label = buildBrowseTabLabel({
        formData: form.getData(),
        pageToken: cursor ?? browseState.startPageToken.value,
        availableServices: availableServices.value,
        localService: localService.value,
    });

    if (label) {
        props.onUpdateTabLabel(label);
    }
}

type ContainerBlacklistChange = {
    action: 'created' | 'deleted';
    blacklist: ContainerBlacklist;
};

const activeContainerBlacklists = ref<ContainerBlacklist[]>([]);

function filterItemsByActiveContainerBlacklists(candidateItems: FeedItem[]): FeedItem[] {
    return filterItemsByContainerBlacklists(candidateItems, activeContainerBlacklists.value);
}

function applyActiveContainerBlacklistFilter(): void {
    const filteredItems = filterItemsByActiveContainerBlacklists(items.value);
    if (filteredItems.length === items.value.length) {
        return;
    }

    const filteredIds = new Set(filteredItems.map((item) => item.id));
    const itemsInBlacklistedContainers = items.value.filter((item) => !filteredIds.has(item.id));
    if (itemsInBlacklistedContainers.length === 0) {
        return;
    }

    if (vibeMasonry.value) {
        void vibeMasonry.value.remove(itemsInBlacklistedContainers).catch((error: unknown) => {
            console.error('Failed to remove blacklisted container items from browse-v2:', error);
        });
        return;
    }

    items.value = filterItemsByActiveContainerBlacklists(items.value);
}

function handleContainerBlacklistChange(change: ContainerBlacklistChange): void {
    if (change.action === 'created' && change.blacklist.action_type === 'blacklist') {
        activeContainerBlacklists.value = upsertContainerBlacklist(activeContainerBlacklists.value, change.blacklist);
        applyActiveContainerBlacklistFilter();
        return;
    }

    activeContainerBlacklists.value = removeContainerBlacklist(activeContainerBlacklists.value, change.blacklist);
}

const resolve = createTabContentV2Resolve({
    form,
    startPageToken: browseState.startPageToken,
    updateActiveTab: props.updateActiveTab,
    updateTabLabel,
    items,
    itemsBuckets,
    availableServices,
    filterItems: filterItemsByActiveContainerBlacklists,
    localService,
    toast,
});

function getFeedItemFromVibeItem(item: VibeViewerItem): FeedItem | null { return (item.feedItem as FeedItem | undefined) ?? null; }
function handleAssetLoads(loads: VibeAssetLoadEvent[]): void { const batch = loads.map((load) => getFeedItemFromVibeItem(load.item)).filter((item): item is FeedItem => item !== null); if (batch.length > 0) itemInteractions.preload.onBatchPreloaded(batch); }
function handleAssetErrors(errors: VibeAssetErrorEvent[]): void { itemInteractions.preload.onBatchFailures(errors.map((error) => ({ item: error.item.feedItem as FeedItem, error }))); }
async function handleReaction(item: VibeViewerItem, type: ReactionType): Promise<void> { const feedItem = getFeedItemFromVibeItem(item); if (feedItem) itemInteractions.reactions.onFileReaction(feedItem, type); }

function openFileSheet(): void { fileViewerSheet.setSheetOpen(true); }
function closeFileSheet(): void { fileViewerSheet.setSheetOpen(false); }
function handleLoadedItemsAction(): void {}

async function loadStandaloneFileItem(fileId: number): Promise<FeedItem | null> {
    try {
        return await loadBrowseV2StandaloneFileItem(fileId);
    } catch (error) {
        console.error('Failed to load standalone browse-v2 file:', error);
        toast.error('Failed to open the requested file.');
        return null;
    }
}

const { handleVibeActiveIndexUpdate, handleVibeSurfaceModeUpdate } = useBrowseV2SurfaceRouteSync({
    activeIndex,
    currentVisibleItem,
    isSessionReady,
    isTabDataLoading,
    loadStandaloneFileItem,
    sessionItems,
    standaloneItem,
    surfaceMode,
    tabId: computed(() => tab.value?.id ?? null),
    visibleItems,
});

const mouseShortcuts = createBrowseV2MouseShortcutHandlers({
    getCurrentItem: () => currentVisibleItem.value,
    getVisibleItems: () => sessionItems.value,
    getSurfaceMode: () => surfaceMode.value,
    onReaction: async (item, type) => {
        itemInteractions.reactions.onFileReaction(item, type);
    },
});

async function applyFilters(): Promise<void> { hydratedInitialState.value = undefined; await browseActions.applyFilters(); }
async function applyService(): Promise<void> { hydratedInitialState.value = undefined; await browseActions.applyService(); }
async function goToFirstPage(): Promise<void> { hydratedInitialState.value = undefined; await browseActions.goToFirstPage(); }
async function retryTabBootstrap(): Promise<void> { await browseActions.initialize(); }

watch(
    () => vibeStatus.value.loadState,
    () => {
        const isLoading = vibeStatus.value.phase === 'loading'
            || vibeStatus.value.phase === 'filling'
            || vibeStatus.value.phase === 'reloading'
            || vibeStatus.value.loadState === 'loading';
        emit('update:loading', isLoading);
        props.onLoadingChange?.(isLoading);
    },
    { immediate: true },
);

watch(
    surfaceMode,
    (mode, previousMode) => {
        if (mode === 'fullscreen' && previousMode !== 'fullscreen') {
            itemInteractions.viewer.onOpen();
            return;
        }

        if (previousMode === 'fullscreen' && mode !== 'fullscreen') {
            itemInteractions.viewer.onClose();
        }
    },
);

watch(
    () => visibleItems.value,
    (nextItems) => {
        props.updateActiveTab(nextItems);
    },
    { deep: false, immediate: true },
);

watch(
    () => items.value,
    () => {
        applyActiveContainerBlacklistFilter();
    },
    { deep: false },
);

watchEffect(() => {
    currentNavigation.currentItemIndex = activeIndex.value;
    fullscreenOverlayState.mediaType = currentVisibleItem.value ? resolveOverlayMediaType(currentVisibleItem.value) : 'image';
    fullscreenOverlayState.fillComplete = vibeStatus.value.phase === 'idle'
        || vibeStatus.value.phase === 'failed'
        || vibeStatus.value.loadState === 'loaded';
    fullscreenOverlayState.isClosing = false;
});

watch(
    () => tab.value?.id ?? null,
    () => {
        applyRestoredSession();
    },
    { immediate: true },
);
</script>

<template>
    <TabContentV2View
        v-if="tab"
        :active-index="activeIndex"
        :tab="tab"
        :should-show-form="shouldShowForm"
        :form="form"
        :available-services="availableServices"
        :available-sources="availableSources"
        :local-service="localService"
        :header-masonry="headerMasonry"
        :is-filter-sheet-open="isFilterSheetOpen"
        :set-filter-sheet-open="(value) => isFilterSheetOpen = value"
        :update-feed="(value) => form.data.feed = value"
        :set-local-mode="(value) => form.isLocalMode.value = value"
        :update-service="browseActions.updateService"
        :update-source="(value) => form.data.source = value"
        :apply-service="applyService"
        :apply-filters="applyFilters"
        :go-to-first-page="goToFirstPage"
        :handle-loaded-items-action="handleLoadedItemsAction"
        :cancel-load="() => vibeRef?.cancel()"
        :load-next="() => vibeRef?.loadNext()"
        :load-previous="() => vibeRef?.loadPrevious()"
        :retry-load="() => vibeRef?.retry()"
        :vibe-status="vibeStatus"
        :set-vibe-handle="setVibeHandle"
        :masonry-render-key="masonryRenderKey"
        :resolve="resolve"
        :viewer-key="viewerKey"
        :vibe-feed-mode="vibeFeedMode"
        :vibe-initial-cursor="vibeInitialCursor"
        :vibe-initial-state="vibeInitialState"
        :handle-asset-loads="handleAssetLoads"
        :handle-asset-errors="handleAssetErrors"
        :surface-mode="surfaceMode"
        :update-active-index="handleVibeActiveIndexUpdate"
        :update-surface-mode="handleVibeSurfaceModeUpdate"
        :mouse-shortcuts="mouseShortcuts"
        :container-interactions="containerInteractions"
        :item-interactions="itemInteractions"
        :prompt-dialog="promptDialog"
        :local-file-deletion="localFileDeletion"
        :handle-reaction="handleReaction"
        :file-sheet-state="fileSheetState"
        :current-visible-item="currentVisibleItem"
        :file-viewer-data="fileViewerData"
        :open-file-sheet="openFileSheet"
        :close-file-sheet="closeFileSheet"
        :downloaded-reaction-prompt="downloadedReactionPrompt"
        :handle-container-blacklist-change="handleContainerBlacklistChange"
    />
    <TabContentV2BootstrapState
        v-else
        :is-loading="isTabBootstrapping"
        :has-error="hasTabBootstrapError"
        :on-retry="retryTabBootstrap"
    />
</template>
