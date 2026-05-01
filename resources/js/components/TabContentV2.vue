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
import { useTabContentV2ContainerBlacklists } from '@/composables/useTabContentV2ContainerBlacklists';
import { useTabContentPromptDialog } from '@/composables/useTabContentPromptDialog';
import { AUTO_SCROLL_SPEED_MAX, AUTO_SCROLL_SPEED_MIN, FILL_CALL_COUNT_MAX, FILL_CALL_COUNT_MIN, useVibeFillControls } from '@/composables/useVibeFillControls';
import type { ServiceOption } from '@/lib/browseCatalog';
import { createBrowseCatalog } from '@/lib/browseCatalog';
import { loadBrowseV2StandaloneFileItem } from '@/lib/browseV2StandaloneItem';
import { buildBrowseTabLabel } from '@/lib/browseTabLabel';
import { extractRestoredBrowseSession } from '@/lib/tabContentBrowseBootstrap';
import { createRemovedItemIdSet, createTabContentV2EmptyStatus, createTabContentV2Resolve, mapFeedItemToVibeItem, normalizeCursor, resolveOverlayMediaType, type OverlayMediaType } from '@/lib/tabContentV2';
import { createBrowseV2MouseShortcutHandlers } from '@/lib/tabContentV2MouseShortcuts';
import { getFeedItemFromVibeItem, getFeedItemsFromVibeHandle, getFeedItemFromVibeOccurrenceTarget, type AtlasVibeHandle } from '@/lib/tabContentV2VibeItems';
import type { FeedItem, TabData } from '@/composables/useTabs';
import type { ReactionType } from '@/types/reaction';
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
    onOpenContainerTab?: (payload: { label: string; params: Record<string, unknown> }) => void;
    onUpdateTabLabel?: (label: string) => void;
}

const props = defineProps<Props>();
const emit = defineEmits<{ 'update:loading': [isLoading: boolean] }>();

const toast = useToast();
const route = useRoute();
const tabId = toRef(props, 'tabId');
const items = shallowRef<FeedItem[]>([]);
const vibeRef = ref<AtlasVibeHandle | null>(null);
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
const removedItemIds = ref<Set<number>>(new Set());
const visibleItems = computed(() => {
    if (removedItemIds.value.size === 0) {
        return items.value;
    }

    return items.value.filter((item) => !removedItemIds.value.has(item.id));
});
const sessionItems = computed(() => standaloneItem.value ? [standaloneItem.value] : visibleItems.value);
const interactionItems = computed(() => {
    if (standaloneItem.value) {
        return [standaloneItem.value];
    }

    void vibeStatus.value.itemCount;
    void vibeStatus.value.removedIds.join(',');

    return getCurrentVibeFeedItems();
});
const isVibeLoading = computed(() => vibeStatus.value.phase === 'loading'
    || vibeStatus.value.phase === 'filling'
    || vibeStatus.value.phase === 'refreshing'
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
        autoScroll: (speedPxPerSecond: number) => handle.autoScroll(speedPxPerSecond),
        cancel: () => handle.cancel(),
        cancelFill: () => handle.cancelFill(),
        fillUntil: async (count: number) => {
            await handle.fillUntil(count);
        },
        fillUntilEnd: async () => {
            await handle.fillUntilEnd();
        },
        isLoading: isVibeLoading.value,
        lockPageLoading: () => handle.lockPageLoading(),
        loadNextPage: async () => {
            await handle.loadNext();
        },
        pageLoadingLocked: vibeStatus.value.pageLoadingLocked,
        getItemByOccurrenceKey: (occurrenceKey: string) => {
            const item = handle.getItemByOccurrenceKey(occurrenceKey);
            return item ? getFeedItemFromVibeItem(item) : null;
        },
        getItems: getCurrentVibeFeedItems,
        remove: async (target: FeedItem | FeedItem[] | string | string[]) => {
            return handle.remove(collectTargetIds(target));
        },
        restore: async (target: FeedItem | FeedItem[] | string | string[]) => {
            return handle.restore(collectTargetIds(target));
        },
        unlockPageLoading: () => handle.unlockPageLoading(),
    };
});

const containerInteractions = useTabContentContainerInteractions({
    items,
    getItems: () => interactionItems.value,
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
    loadedItems: interactionItems,
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
const totalAvailable = browseState.totalAvailable;
const isFilterSheetOpen = ref(false);
const hydratedInitialState = ref<VibeInitialState | undefined>(undefined);
const localFileDeletion = useLocalFileDeletion({
    items,
    masonry: vibeMasonry,
    isLocal: form.isLocal,
    totalAvailable: browseState.totalAvailable,
    clearHover: itemInteractions.state.clearHover,
});
const containerBlacklists = useTabContentV2ContainerBlacklists({
    items,
    masonry: vibeMasonry,
});

const currentNavigation = reactive({ currentItemIndex: 0 as number | null });
const fullscreenOverlayState = reactive({
    fillComplete: true,
    isClosing: false,
    mediaType: 'image' as OverlayMediaType,
});
const fileViewerSheet = useFileViewerSheetState({ overlay: fullscreenOverlayState });
const fileSheetState = fileViewerSheet.sheetState;
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
const shouldShowStandaloneRouteBootstrap = computed(() => Boolean(tab.value)
    && hasRouteFileSelection.value
    && !isClosingFullscreenRoute.value
    && standaloneItem.value === null
    && surfaceMode.value !== 'fullscreen');
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
const fillControls = useVibeFillControls({
    getVibeHandle: () => vibeRef.value,
    status: vibeStatus,
    surfaceMode,
});
const { autoScrollActive, autoScrollSpeed, fillActionsDisabled, fillCallCount } = fillControls;

function setTabDataLoading(isLoading: boolean): void {
    isTabDataLoading.value = isLoading;
    props.onTabDataLoadingChange?.(isLoading);
}

function setVibeHandle(handle: VibeHandle | null): void {
    vibeRef.value = handle as AtlasVibeHandle | null;

    if (handle) {
        containerBlacklists.applyActiveContainerBlacklistFilter();
    }
}

function handleVibeItemsChange(vibeItems: VibeViewerItem[]): void {
    items.value = vibeItems
        .map(getFeedItemFromVibeItem)
        .filter((item): item is FeedItem => item !== null);
}

function resetLocalFeedState(): void {
    items.value = [];
    removedItemIds.value = new Set();
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

const resolve = createTabContentV2Resolve({
    form,
    startPageToken: browseState.startPageToken,
    totalAvailable,
    updateTabLabel,
    filterItems: containerBlacklists.filterItemsByActiveContainerBlacklists,
    toast,
});

function getCurrentVibeFeedItems(): FeedItem[] {
    return getFeedItemsFromVibeHandle(vibeRef.value, visibleItems.value);
}
function getFeedItemFromShortcutTarget(target: EventTarget | null): FeedItem | null {
    return getFeedItemFromVibeOccurrenceTarget(vibeRef.value, target);
}
function cancelActiveVibeFill(): void { fillControls.cancelFill(); }
function stopActiveAutoScroll(): void { fillControls.stopAutoScroll(); }
function stopActiveVibeAutomation(): void { cancelActiveVibeFill(); stopActiveAutoScroll(); }
function handleAssetLoads(loads: VibeAssetLoadEvent[]): void { const batch = loads.map((load) => getFeedItemFromVibeItem(load.item)).filter((item): item is FeedItem => item !== null); if (batch.length > 0) itemInteractions.preload.onBatchPreloaded(batch); }
function handleAssetErrors(errors: VibeAssetErrorEvent[]): void { itemInteractions.preload.onBatchFailures(errors.map((error) => ({ item: error.item.feedItem as FeedItem, error }))); }
async function handleReaction(item: VibeViewerItem, type: ReactionType): Promise<void> { const feedItem = getFeedItemFromVibeItem(item); if (feedItem) itemInteractions.reactions.onFileReaction(feedItem, type); }

function openFileSheet(): void { fileViewerSheet.setSheetOpen(true); }
function closeFileSheet(): void { fileViewerSheet.setSheetOpen(false); }

async function loadStandaloneFileItem(fileId: number): Promise<FeedItem | null> {
    try {
        return await loadBrowseV2StandaloneFileItem(fileId);
    } catch (error) {
        console.error('Failed to load standalone browse-v2 file:', error);
        toast.error('Failed to open the requested file.');
        return null;
    }
}

const { handleVibeActiveIndexUpdate, handleVibeSurfaceModeUpdate, isClosingFullscreenRoute } = useBrowseV2SurfaceRouteSync({
    activeIndex,
    currentVisibleItem,
    isSessionReady,
    isTabDataLoading,
    loadStandaloneFileItem,
    removedItemIds,
    sessionItems,
    standaloneItem,
    surfaceMode,
    tabId: computed(() => tab.value?.id ?? null),
    visibleItems,
});

const mouseShortcuts = createBrowseV2MouseShortcutHandlers({
    getCurrentItem: () => currentVisibleItem.value,
    getItemFromTarget: getFeedItemFromShortcutTarget,
    getSurfaceMode: () => surfaceMode.value,
    onReaction: async (item, type) => {
        itemInteractions.reactions.onFileReaction(item, type);
    },
});

async function applyFilters(): Promise<void> { stopActiveVibeAutomation(); hydratedInitialState.value = undefined; await browseActions.applyFilters(); }
async function applyService(): Promise<void> { stopActiveVibeAutomation(); hydratedInitialState.value = undefined; await browseActions.applyService(); }
async function goToFirstPage(): Promise<void> { stopActiveVibeAutomation(); hydratedInitialState.value = undefined; await browseActions.goToFirstPage(); }
async function retryTabBootstrap(): Promise<void> { await browseActions.initialize(); }

watch(
    () => vibeStatus.value.loadState,
    () => {
        const isLoading = vibeStatus.value.phase === 'loading'
            || vibeStatus.value.phase === 'filling'
            || vibeStatus.value.phase === 'refreshing'
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
    () => items.value,
    () => {
        containerBlacklists.applyActiveContainerBlacklistFilter();
    },
    { deep: false },
);

watch(
    () => vibeStatus.value.removedIds.join(','),
    () => {
        removedItemIds.value = createRemovedItemIdSet(vibeStatus.value.removedIds);
    },
    { immediate: true },
);

watchEffect(() => {
    currentNavigation.currentItemIndex = activeIndex.value;
    fullscreenOverlayState.mediaType = currentVisibleItem.value ? resolveOverlayMediaType(currentVisibleItem.value) : 'image';
    fullscreenOverlayState.fillComplete = vibeStatus.value.phase === 'idle'
        || vibeStatus.value.phase === 'failed'
        || vibeStatus.value.loadState === 'loaded';
    fullscreenOverlayState.isClosing = false;
});

defineExpose({ cancelFill: cancelActiveVibeFill, stopAutoScroll: stopActiveAutoScroll });

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
        v-if="tab && !shouldShowStandaloneRouteBootstrap"
        :active-index="activeIndex"
        :tab="tab"
        :total-available="totalAvailable"
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
        :auto-scroll-active="autoScrollActive"
        :auto-scroll-max="AUTO_SCROLL_SPEED_MAX"
        :auto-scroll-min="AUTO_SCROLL_SPEED_MIN"
        :auto-scroll-speed="autoScrollSpeed"
        :cancel-fill="cancelActiveVibeFill"
        :cancel-load="() => vibeRef?.cancel()"
        :fill-actions-disabled="fillActionsDisabled"
        :fill-call-count="fillCallCount"
        :fill-call-count-max="FILL_CALL_COUNT_MAX"
        :fill-call-count-min="FILL_CALL_COUNT_MIN"
        :fill-until-count="fillControls.fillUntilCount"
        :fill-until-end="fillControls.fillUntilEnd"
        :load-next="() => vibeRef?.loadNext()"
        :set-auto-scroll-speed="fillControls.setAutoScrollSpeed"
        :set-fill-call-count="fillControls.setFillCallCount"
        :toggle-auto-scroll="fillControls.toggleAutoScroll"
        :vibe-status="vibeStatus"
        :set-vibe-handle="setVibeHandle"
        :masonry-render-key="masonryRenderKey"
        :resolve="resolve"
        :viewer-key="viewerKey"
        :vibe-initial-cursor="vibeInitialCursor"
        :vibe-initial-state="vibeInitialState"
        :handle-asset-loads="handleAssetLoads"
        :handle-asset-errors="handleAssetErrors"
        :handle-items-change="handleVibeItemsChange"
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
        :handle-container-blacklist-change="containerBlacklists.handleContainerBlacklistChange"
    />
    <TabContentV2BootstrapState
        v-else
        :is-loading="isTabBootstrapping || shouldShowStandaloneRouteBootstrap"
        :has-error="hasTabBootstrapError"
        :on-retry="retryTabBootstrap"
        :status-label="shouldShowStandaloneRouteBootstrap ? 'Loading file' : undefined"
        :message="shouldShowStandaloneRouteBootstrap ? 'Opening the requested file directly in fullscreen.' : undefined"
    />
</template>
