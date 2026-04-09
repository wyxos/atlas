<script setup lang="ts">
import { computed, provide, reactive, ref, shallowRef, toRef, watch, watchEffect, type Ref } from 'vue';
import { ArrowLeft, Loader2, PanelRightOpen } from 'lucide-vue-next';
import { VibeLayout, type VibeAssetErrorEvent, type VibeAssetLoadEvent, type VibeHandle, type VibeInitialState, type VibeViewerItem } from '@wyxos/vibe-v3';
import type { MasonryInstance } from '@wyxos/vibe';
import { useToast } from '@/components/ui/toast/use-toast';
import { Button } from '@/components/ui/button';
import { createBrowseForm, BrowseFormKey } from '@/composables/useBrowseForm';
import { useDownloadedReactionPrompt } from '@/composables/useDownloadedReactionPrompt';
import { useFileViewerData } from '@/composables/useFileViewerData';
import { useFileViewerSheetState } from '@/composables/useFileViewerSheetState';
import { useItemPreview } from '@/composables/useItemPreview';
import { useMasonryReactionHandler } from '@/composables/useMasonryReactionHandler';
import { useTabContentBrowseState } from '@/composables/useTabContentBrowseState';
import { useTabContentNotFoundReconciliation } from '@/composables/useTabContentNotFoundReconciliation';
import type { ServiceOption } from '@/lib/browseCatalog';
import { createBrowseCatalog } from '@/lib/browseCatalog';
import { buildBrowseTabLabel } from '@/lib/browseTabLabel';
import { extractRestoredBrowseSession } from '@/lib/tabContentBrowseBootstrap';
import { createTabContentV2EmptyStatus, createTabContentV2Resolve, mapFeedItemToVibeItem, normalizeCursor, resolveOverlayMediaType, type OverlayMediaType } from '@/lib/tabContentV2';
import { createBrowseV2MouseShortcutHandlers } from '@/lib/tabContentV2MouseShortcuts';
import type { FeedItem, TabData } from '@/composables/useTabs';
import type { ReactionType } from '@/types/reaction';
import FileReactions from './FileReactions.vue';
import FileViewerSheet from './FileViewerSheet.vue';
import BrowseV2StatusBar from './BrowseV2StatusBar.vue';
import TabContentStartForm from './TabContentStartForm.vue';
import TabContentServiceHeader from './TabContentServiceHeader.vue';

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
const tabId = toRef(props, 'tabId');
const items = shallowRef<FeedItem[]>([]);
const itemsBuckets = ref<Array<{ cursor: string | null; items: FeedItem[]; nextCursor: string | null; previousCursor: string | null }>>([]);
const removedIds = ref<Set<number>>(new Set());
const vibeRef = ref<VibeHandle | null>(null);
const tab = ref<TabData | null>(null);
const form = createBrowseForm();
provide(BrowseFormKey, form);

const browseCatalog = createBrowseCatalog();
const browseCatalogState = browseCatalog.state;
const availableServices = computed(() => (props.availableServices.length > 0 ? props.availableServices : browseCatalogState.availableServices.value));
const availableSources = browseCatalogState.availableSources;
const localService = browseCatalogState.localService;
const itemPreview = useItemPreview(items, computed(() => tab.value ?? undefined));
const downloadedReactionPrompt = useDownloadedReactionPrompt();

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
        resetPreloadedItems: () => {},
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
const masonryRenderKey = browseState.masonryRenderKey;
const vibeFeedMode = computed(() => (form.data.feed === 'local' ? 'static' : 'dynamic'));
const isFilterSheetOpen = ref(false);
const vibeInitialCursor = computed(() => normalizeCursor(browseState.startPageToken.value));
const hydratedInitialState = ref<VibeInitialState | undefined>(undefined);
const vibeInitialState = computed(() => hydratedInitialState.value);

const currentNavigation = reactive({ currentItemIndex: 0 as number | null });
const fullscreenOverlayState = reactive({
    fillComplete: true,
    isClosing: false,
    mediaType: 'image' as OverlayMediaType,
});
const fileViewerSheet = useFileViewerSheetState({ overlay: fullscreenOverlayState });
const fileSheetState = fileViewerSheet.sheetState;
const fileViewerData = useFileViewerData({
    items: computed(() => items.value.filter((item) => !removedIds.value.has(item.id))),
    navigation: currentNavigation,
    overlay: fullscreenOverlayState,
    sheet: fileSheetState,
});
const notFoundReconciliation = useTabContentNotFoundReconciliation({
    items,
    tab,
    masonry: ref(null),
    hoveredItemId: ref(null),
    cancelAutoDislikeCountdown: () => {},
    clearHover: () => {},
});
const emptyStatus = createTabContentV2EmptyStatus();
const vibeStatus = computed(() => vibeRef.value?.status ?? emptyStatus);
const visibleItems = computed(() => items.value.filter((item) => !removedIds.value.has(item.id)));
const currentVisibleItem = computed(() => visibleItems.value[vibeStatus.value.activeIndex] ?? null);
const headerMasonry = computed<MasonryInstance | null>(() => ({
    hasReachedEnd: !vibeStatus.value.hasNextPage,
    isLoading: vibeStatus.value.phase === 'loading'
        || vibeStatus.value.phase === 'filling'
        || vibeStatus.value.phase === 'reloading',
} as unknown as MasonryInstance));

function setTabDataLoading(isLoading: boolean): void {
    props.onTabDataLoadingChange?.(isLoading);
}

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
    resetLocalFeedState();

    const restored = extractRestoredBrowseSession(tab.value);
    if (!restored || restored.items.length === 0) {
        hydratedInitialState.value = undefined;
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
    updateTabLabel(restored.cursor);
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
    updateActiveTab: props.updateActiveTab,
    updateTabLabel,
    items,
    itemsBuckets,
    availableServices,
    localService,
    toast,
});

type ReactionMasonryAdapter = {
    remove: (item: FeedItem) => Promise<void> | void;
    restore: (item: FeedItem) => Promise<void> | void;
};

const reactionMasonryRef = computed<ReactionMasonryAdapter | null>(() => {
    if (!vibeRef.value) {
        return null;
    }

    return {
        remove: async (item: FeedItem) => {
            const result = vibeRef.value?.remove(String(item.id));
            syncRemovedIds(result?.ids ?? [String(item.id)], 'remove');
            return result;
        },
        restore: async (item: FeedItem) => {
            const result = vibeRef.value?.restore(String(item.id));
            syncRemovedIds(result?.ids ?? [String(item.id)], 'restore');
            return result;
        },
    };
});

const reactionHandlers = useMasonryReactionHandler({
    items,
    masonry: reactionMasonryRef as unknown as Ref<MasonryInstance | null>,
    tab: computed(() => tab.value ?? undefined),
    isLocal: form.isLocal,
    matchesActiveLocalFilters: (item) => !item.reaction?.type,
    isPositiveOnlyLocalView: () => false,
    onReaction: props.onReaction,
    promptDownloadedReaction: downloadedReactionPrompt.prompt,
});

function handleAssetLoads(loads: VibeAssetLoadEvent[]): void {
    for (const load of loads) {
        const feedItem = load.item.feedItem as FeedItem | undefined;
        if (feedItem) {
            void itemPreview.incrementPreviewCount(feedItem.id);
        }
    }
}

function handleAssetErrors(errors: VibeAssetErrorEvent[]): void {
    notFoundReconciliation.onBatchFailures(errors.map((error) => ({
        item: error.item.feedItem as FeedItem,
        error,
    })));
}

async function handleReaction(item: VibeViewerItem, type: ReactionType): Promise<void> {
    const feedItem = item.feedItem as FeedItem | undefined;
    if (feedItem) {
        await reactionHandlers.handleMasonryReaction(feedItem, type);
    }
}

function openFileSheet(): void {
    fileViewerSheet.setSheetOpen(true);
}

function closeFileSheet(): void {
    fileViewerSheet.setSheetOpen(false);
}

function handleLoadedItemsAction(): void {
    return;
}
const mouseShortcuts = createBrowseV2MouseShortcutHandlers({
    getCurrentItem: () => currentVisibleItem.value,
    getVisibleItems: () => visibleItems.value,
    getSurfaceMode: () => vibeStatus.value.surfaceMode,
    onReaction: async (item, type) => {
        await reactionHandlers.handleMasonryReaction(item, type);
    },
});

async function applyFilters(): Promise<void> {
    hydratedInitialState.value = undefined;
    await browseActions.applyFilters();
}

async function applyService(): Promise<void> {
    hydratedInitialState.value = undefined;
    await browseActions.applyService();
}

async function goToFirstPage(): Promise<void> {
    hydratedInitialState.value = undefined;
    await browseActions.goToFirstPage();
}

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
    () => visibleItems.value,
    (nextItems) => {
        props.updateActiveTab(nextItems);
    },
    { deep: false, immediate: true },
);

watchEffect(() => {
    currentNavigation.currentItemIndex = vibeStatus.value.activeIndex;
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
    <div v-if="tab" class="flex h-full min-h-0 flex-col">
        <TabContentServiceHeader
            v-if="!shouldShowForm"
            :form="form"
            :available-services="availableServices"
            :available-sources="availableSources"
            :local-service="localService ?? null"
            :masonry="headerMasonry"
            :filter-sheet-open="isFilterSheetOpen"
            :update-filter-sheet-open="(value) => isFilterSheetOpen = value"
            :update-feed="(value) => form.data.feed = value"
            :update-service="browseActions.updateService"
            :update-source="(value) => form.data.source = value"
            :apply-service="applyService"
            :apply-filters="applyFilters"
            :reset-filters="form.reset"
            :loaded-items-count="0"
            :active-loaded-items-action="null"
            :on-run-loaded-items-action="handleLoadedItemsAction"
            :cancel-masonry-load="() => vibeRef?.cancel()"
            :go-to-first-page="goToFirstPage"
            :load-next-page="() => vibeRef?.loadNext()"
        >
            <div class="flex items-center gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    class="h-10 px-3 gap-2"
                    :disabled="headerMasonry?.isLoading || !vibeStatus.hasPreviousPage"
                    @click="vibeRef?.loadPrevious()"
                >
                    <ArrowLeft :size="14" />
                    <span>Previous</span>
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    class="h-10 px-3 gap-2"
                    :disabled="headerMasonry?.isLoading || vibeStatus.loadState !== 'failed'"
                    @click="vibeRef?.retry()"
                >
                    Retry
                </Button>
            </div>
        </TabContentServiceHeader>
        <TabContentStartForm
            v-if="shouldShowForm"
            :form="form"
            :available-services="availableServices"
            :available-sources="availableSources"
            :is-loading="vibeStatus.phase === 'loading' || vibeStatus.phase === 'filling' || vibeStatus.phase === 'reloading'"
            :set-local-mode="(value) => form.isLocalMode.value = value"
            :update-service="browseActions.updateService"
            :update-source="(value) => form.data.source = value"
            :apply-service="applyService"
        />
        <div v-else class="flex min-h-0 flex-1 flex-col gap-3 overflow-hidden px-4 py-4">
            <div
                class="flex min-h-0 flex-1 overflow-hidden border border-white/10 bg-black/20 shadow-[0_40px_120px_-70px_rgba(0,0,0,0.9)]"
                @click.capture="mouseShortcuts.handleClickCapture"
                @contextmenu.capture="mouseShortcuts.handleContextMenuCapture"
                @mousedown.capture="mouseShortcuts.handleMouseDownCapture"
                @auxclick.capture="mouseShortcuts.handleAuxClickCapture"
            >
                <VibeLayout
                    :key="`${tab.id}-${masonryRenderKey}`"
                    ref="vibeRef"
                    class="h-full min-h-0 w-full"
                    :resolve="resolve"
                    :mode="vibeFeedMode"
                    :page-size="Number(form.data.limit)"
                    :initial-cursor="vibeInitialCursor"
                    :initial-state="vibeInitialState"
                    :fill-delay-ms="1000"
                    :fill-delay-step-ms="250"
                    @asset-loads="handleAssetLoads"
                    @asset-errors="handleAssetErrors"
                    @update:active-index="() => undefined"
                >
                    <template #grid-item-overlay="{ item, hovered, active, index }">
                        <div v-if="hovered || active" class="pointer-events-none absolute inset-x-0 bottom-0 z-[5] flex justify-center px-3 pb-3">
                            <div class="pointer-events-auto">
                                <FileReactions
                                    :file-id="(item as Record<string, unknown>).fileId as number"
                                    :reaction="((item as Record<string, unknown>).feedItem as FeedItem | undefined)?.reaction ?? null"
                                    :previewed-count="((item as Record<string, unknown>).feedItem as FeedItem | undefined)?.previewed_count ?? 0"
                                    :viewed-count="((item as Record<string, unknown>).feedItem as FeedItem | undefined)?.seen_count ?? 0"
                                    :current-index="index"
                                    :total-items="vibeStatus.itemCount"
                                    variant="small"
                                    @reaction="(type) => handleReaction(item as VibeViewerItem, type)"
                                />
                            </div>
                        </div>
                    </template>
                    <template #grid-footer>
                        <div class="pointer-events-none flex justify-center px-4 pb-4 pt-2">
                            <BrowseV2StatusBar :status="vibeStatus" />
                        </div>
                    </template>
                    <template #grid-status="{ kind, message }">
                        <div
                            class="inline-flex items-center gap-2 border px-4 py-3 text-[0.72rem] font-semibold uppercase tracking-[0.18em] backdrop-blur-[18px]"
                            :class="kind === 'end'
                                ? 'border-amber-300/35 bg-black/55 text-amber-200'
                                : 'border-smart-blue-500/70 bg-prussian-blue-800/88 text-smart-blue-100'"
                        >
                            <Loader2 v-if="kind === 'loading-more'" :size="14" class="animate-spin" />
                            <span>{{ message }}</span>
                        </div>
                    </template>
                    <template #fullscreen-overlay="{ item, index, total }">
                        <div class="pointer-events-none absolute inset-0 z-[5]">
                            <div class="pointer-events-auto absolute bottom-4 left-1/2 -translate-x-1/2">
                                <FileReactions
                                    :file-id="(item as Record<string, unknown>).fileId as number"
                                    :reaction="((item as Record<string, unknown>).feedItem as FeedItem | undefined)?.reaction ?? null"
                                    :previewed-count="((item as Record<string, unknown>).feedItem as FeedItem | undefined)?.previewed_count ?? 0"
                                    :viewed-count="((item as Record<string, unknown>).feedItem as FeedItem | undefined)?.seen_count ?? 0"
                                    :current-index="index"
                                    :total-items="total"
                                    variant="default"
                                    @reaction="(type) => handleReaction(item as VibeViewerItem, type)"
                                />
                            </div>
                        </div>
                    </template>
                    <template #fullscreen-header-actions>
                        <button
                            type="button"
                            class="inline-flex h-11 w-11 items-center justify-center border border-white/12 bg-black/50 text-[#f7f1ea]/82 backdrop-blur-[18px] transition hover:border-white/24 hover:bg-black/65"
                            :aria-label="fileSheetState.isOpen ? 'Hide file sheet' : 'Show file sheet'"
                            @click="fileSheetState.isOpen ? closeFileSheet() : openFileSheet()"
                        >
                            <PanelRightOpen :size="16" />
                        </button>
                    </template>
                    <template #fullscreen-status="{ kind, message }">
                        <div
                            class="inline-flex items-center gap-2 border px-4 py-3 text-[0.72rem] font-semibold uppercase tracking-[0.18em] backdrop-blur-[18px]"
                            :class="kind === 'end'
                                ? 'border-amber-300/35 bg-black/55 text-amber-200'
                                : 'border-smart-blue-500/70 bg-prussian-blue-800/88 text-smart-blue-100'"
                        >
                            <Loader2 v-if="kind === 'loading-more'" :size="14" class="animate-spin" />
                            <span>{{ message }}</span>
                        </div>
                    </template>
                    <template #fullscreen-aside>
                        <FileViewerSheet
                            v-if="fileSheetState.isOpen"
                            embedded
                            :is-open="fileSheetState.isOpen"
                            :file-id="currentVisibleItem?.id ?? null"
                            :file-data="fileViewerData.fileData.value"
                            :is-loading="fileViewerData.isLoadingFileData.value"
                            @close="closeFileSheet"
                        />
                    </template>
                </VibeLayout>
            </div>
        </div>
    </div>
</template>
