<script setup lang="ts">
import { computed } from 'vue';
import { useEventListener } from '@vueuse/core';
import { VibeLayout, type VibeAssetErrorEvent, type VibeAssetLoadEvent, type VibeHandle, type VibeInitialState, type VibeResolveResult, type VibeViewerItem, type VibeStatus } from '@wyxos/vibe';
import { PanelRightOpen } from 'lucide-vue-next';
import type { BrowseFormInstance } from '@/composables/useBrowseForm';
import { useBrowseGlobalStartPanel } from '@/composables/useBrowseGlobalStartPanel';
import type { LocalFileDeletion } from '@/composables/useLocalFileDeletion';
import { useSourceWatchRefresh } from '@/composables/useSourceWatchRefresh';
import type { TabContentContainerInteractions } from '@/composables/useTabContentContainerInteractions';
import type { TabContentItemInteractions } from '@/composables/useTabContentItemInteractions';
import type { TabContentPromptDialog as TabContentPromptDialogHandle } from '@/composables/useTabContentPromptDialog';
import { Sheet, SheetContent } from '@/components/ui/sheet';
import type { FeedItem, TabData } from '@/composables/useTabs';
import type { ServiceOption } from '@/lib/browseCatalog';
import type { BrowseFeedHandle } from '@/types/browse';
import type { File } from '@/types/file';
import type { ReactionType } from '@/types/reaction';
import type { LocalSourceSelection } from '@/utils/localSourceSelection';
import { createTabContentV2KeydownHandler } from '@/lib/tabContentV2Keyboard';
import BrowseGlobalStartPanel from './BrowseGlobalStartPanel.vue';
import BrowseV2StatusBar from './BrowseV2StatusBar.vue';
import ContainerBlacklistManager from './container-blacklist/ContainerBlacklistManager.vue';
import DownloadedReactionDialog from './DownloadedReactionDialog.vue';
import FileReactions from './FileReactions.vue';
import FileViewerSheet from './FileViewerSheet.vue';
import LocalFileDeleteDialog from './LocalFileDeleteDialog.vue';
import Pill from './ui/Pill.vue';
import TabContentContainerDrawer from './TabContentContainerDrawer.vue';
import TabContentContainerSheet from './TabContentContainerSheet.vue';
import TabContentServiceHeader from './TabContentServiceHeader.vue';
import TabContentStartForm from './TabContentStartForm.vue';
import TabContentV2FullscreenPageLoadingLock from './TabContentV2FullscreenPageLoadingLock.vue';
import TabContentV2GridOverlay from './TabContentV2GridOverlay.vue';

type FileSheetState = {
    isOpen: boolean;
};

type FileSheetPresentation = 'inline' | 'overlay';

type FileViewerDataShape = {
    fileData: { value: File | null };
    isLoadingFileData: { value: boolean };
    setFileData: (file: File) => void;
};

type DownloadedReactionPromptShape = {
    data: {
        open: { value: boolean };
    };
    chooseReact: () => void;
    chooseRedownload: () => void;
    close: () => void;
    setOpen: (value: boolean) => void;
};

type MouseShortcutHandlers = ReturnType<typeof import('@/lib/tabContentV2MouseShortcuts').createBrowseV2MouseShortcutHandlers>;

const props = defineProps<{
    activeIndex: number;
    autoScrollActive?: boolean;
    autoScrollMax?: number;
    autoScrollMin?: number;
    autoScrollSpeed?: number;
    availableServices: ServiceOption[];
    availableSources: string[];
    applyFilters: () => Promise<void>;
    applyService: () => Promise<void>;
    cancelFill: () => void;
    closeFileSheet: () => void;
    containerInteractions: TabContentContainerInteractions;
    currentVisibleItem: FeedItem | null;
    downloadedReactionPrompt: DownloadedReactionPromptShape;
    fileSheetState: FileSheetState;
    fileSheetItem: FeedItem | null;
    fileViewerData: FileViewerDataShape;
    form: BrowseFormInstance;
    fillActionsDisabled?: boolean;
    fillCallCount?: number;
    fillCallCountMax?: number;
    fillCallCountMin?: number;
    fillUntilCount?: () => void;
    fillUntilEnd?: () => void;
    goToFirstPage: () => Promise<void>;
    handleAssetErrors: (errors: VibeAssetErrorEvent[]) => void;
    handleAssetLoads: (loads: VibeAssetLoadEvent[]) => void;
    handleContainerBlacklistChange: (change: { action: 'created' | 'deleted'; blacklist: import('@/types/container-blacklist').ContainerBlacklist }) => void;
    handleItemsChange: (items: VibeViewerItem[]) => void;
    handleReaction: (item: VibeViewerItem, type: ReactionType) => void | Promise<void>;
    headerMasonry: BrowseFeedHandle | null;
    isFilterSheetOpen: boolean;
    itemInteractions: TabContentItemInteractions;
    localFileDeletion: LocalFileDeletion;
    localService: ServiceOption | null | undefined;
    loadNext: () => void | Promise<void>;
    masonryRenderKey: number;
    mouseShortcuts: MouseShortcutHandlers;
    openFileSheet: () => void;
    openFileSheetForItem: (item: FeedItem, index: number) => void;
    promptDialog: TabContentPromptDialogHandle;
    fileSheetPresentation: FileSheetPresentation;
    resolve: (params: { cursor: string | null; pageSize: number; signal?: AbortSignal }) => Promise<VibeResolveResult>;
    setAutoScrollSpeed?: (value: number) => void;
    setFillCallCount?: (value: number) => void;
    setFilterSheetOpen: (value: boolean) => void;
    setLocalMode: (value: boolean) => void;
    setVibeHandle: (value: VibeHandle | null) => void;
    shouldShowForm: boolean;
    tab: TabData | null;
    totalAvailable: number | null;
    updateFeed: (value: 'local' | 'online') => void;
    updateActiveIndex: (value: number) => void;
    updateSource: (value: LocalSourceSelection) => void;
    updateSurfaceMode: (value: 'fullscreen' | 'list') => void;
    updateService: (value: string) => void | Promise<void>;
    surfaceMode: 'fullscreen' | 'list';
    toggleAutoScroll?: () => void;
    vibeInitialCursor: string | null;
    vibeInitialState: VibeInitialState | undefined;
    vibeStatus: VibeStatus;
    viewerKey: string;
}>();

const globalStartPanel = useBrowseGlobalStartPanel();
const showGlobalStartPanel = computed(() => Boolean(globalStartPanel?.isOpen.value) && !props.shouldShowForm);
const sourceWatchRefresh = useSourceWatchRefresh({
    setFileData: props.fileViewerData.setFileData,
});
const sheetPromptItemId = computed(() => props.promptDialog.data.promptDialogItemId.value);
const isSheetPromptLoading = computed(() => {
    const itemId = sheetPromptItemId.value;
    const promptLoading = props.promptDialog.data.promptDataLoading.value as unknown;

    if (promptLoading instanceof Map) {
        return itemId !== null && promptLoading.get(itemId) === true;
    }

    return Boolean(promptLoading);
});
const showSheetPrompt = computed(() => sheetPromptItemId.value !== null);
const isFileSheetOverlay = computed(() => props.surfaceMode === 'list' && props.fileSheetState.isOpen);
const shouldReserveFileSheetSpace = computed(() => props.fileSheetState.isOpen && !isFileSheetOverlay.value);
const fileSheetFileId = computed(() => props.fileSheetItem?.id ?? null);
const canTogglePageLoadingLock = computed(() => Boolean(props.headerMasonry?.lockPageLoading && props.headerMasonry?.unlockPageLoading));
const pageLoadingLocked = computed(() => Boolean(props.vibeStatus.pageLoadingLocked || props.headerMasonry?.pageLoadingLocked));
const showFullscreenPageLoadingLock = computed(() => (
    props.surfaceMode === 'fullscreen'
    && pageLoadingLocked.value
    && props.vibeStatus.hasNextPage
    && props.vibeStatus.activeIndex >= props.vibeStatus.itemCount
));

function closeGlobalStartPanel(): void {
    globalStartPanel?.close();
}

function handleGlobalStartPanelOpenChange(value: boolean): void {
    if (!value) {
        closeGlobalStartPanel();
    }
}

function handleVibeRef(instance: unknown): void {
    props.setVibeHandle((instance as VibeHandle | null) ?? null);
}

function getFeedItemFromVibeItem(item: VibeViewerItem): FeedItem | null {
    return (item.feedItem as FeedItem | undefined) ?? null;
}

function getContainerPillTargets(item: VibeViewerItem) {
    const feedItem = getFeedItemFromVibeItem(item);

    return feedItem
        ? props.containerInteractions.badges?.getContainersForItem(feedItem) ?? []
        : [];
}

function shouldDimGridItemForContainerDrawer(item: VibeViewerItem): boolean {
    const feedItem = getFeedItemFromVibeItem(item);
    if (!feedItem) {
        return false;
    }

    const highlightedItemIds = props.containerInteractions.drawer.derived.highlightedItemIds.value;

    return highlightedItemIds.size > 0 && !highlightedItemIds.has(feedItem.id);
}

async function handleBlacklist(item: VibeViewerItem): Promise<void> {
    const feedItem = getFeedItemFromVibeItem(item);

    if (feedItem) {
        await props.itemInteractions.reactions.onFileBlacklist(feedItem);
    }
}

function togglePageLoadingLock(): void {
    if (!canTogglePageLoadingLock.value) {
        return;
    }

    if (isPageLoadingCurrentlyLocked()) {
        unlockPageLoading();
        return;
    }

    props.headerMasonry?.lockPageLoading?.();
}

function isPageLoadingCurrentlyLocked(): boolean {
    return Boolean(props.vibeStatus.pageLoadingLocked || props.headerMasonry?.pageLoadingLocked);
}

function unlockPageLoading(): void {
    props.headerMasonry?.unlockPageLoading?.();
}

const handleRootKeydown = createTabContentV2KeydownHandler({
    closeContainerSheet: props.containerInteractions.sheet.actions.close,
    closeFileSheet: props.closeFileSheet,
    getContainerSheetOpen: () => props.containerInteractions.sheet.state.isOpen.value,
    getFileSheetOpen: () => props.fileSheetState.isOpen,
    getSurfaceMode: () => props.surfaceMode,
    updateSurfaceMode: props.updateSurfaceMode,
});

const vibeLayoutBindings = computed(() => ({
    activeIndex: props.activeIndex,
    emptyStateMode: 'hidden' as const,
    fillDelayMaxMs: props.form.data.feed === 'local' ? 0 : 15000,
    fillDelayMs: props.form.data.feed === 'local' ? 0 : 2000,
    fillDelayStepMs: props.form.data.feed === 'local' ? 0 : 1000,
    initialCursor: props.vibeInitialCursor,
    initialState: props.vibeInitialState,
    loopFullscreenVideo: true,
    pageSize: Number(props.form.data.limit),
    resolve: props.resolve,
    showEndBadge: false,
    showStatusBadges: false,
    surfaceMode: props.surfaceMode,
}));

useEventListener(document, 'pointermove', (event) => {
    props.containerInteractions.drawer.actions.syncHoverTarget?.(event.target);
});

useEventListener(document, 'keydown', handleRootKeydown, { capture: true });

</script>

<template>
    <div
        v-if="tab"
        class="relative flex h-full min-h-0 flex-col overflow-hidden"
        data-test="tab-content-v2-view"
        @keydown.capture="handleRootKeydown"
    >
        <TabContentServiceHeader
            v-if="!shouldShowForm"
            :form="form"
            :available-services="availableServices"
            :available-sources="availableSources"
            :local-service="localService ?? null"
            :masonry="headerMasonry"
            :filter-sheet-open="isFilterSheetOpen"
            :update-filter-sheet-open="setFilterSheetOpen"
            :update-feed="updateFeed"
            :update-service="updateService"
            :update-source="updateSource"
            :apply-service="applyService"
            :apply-filters="applyFilters"
            :reset-filters="form.reset"
            :go-to-first-page="goToFirstPage"
            :load-next-page="loadNext"
        >
            <ContainerBlacklistManager
                :ref="containerInteractions.managerRef"
                :disabled="Boolean(headerMasonry?.isLoading)"
                @blacklists-changed="handleContainerBlacklistChange"
            />
        </TabContentServiceHeader>
        <TabContentStartForm
            v-if="shouldShowForm"
            :available-services="availableServices"
            :local-service="localService"
            :is-loading="vibeStatus.phase === 'loading' || vibeStatus.phase === 'filling' || vibeStatus.phase === 'refreshing'"
            :set-local-mode="setLocalMode"
            :update-service="updateService"
            :update-source="updateSource"
            :apply-service="applyService"
        />
        <div v-else class="flex min-h-0 flex-1 flex-col gap-3 overflow-hidden">
            <div
                class="relative flex min-h-0 flex-1 overflow-hidden border border-white/10 bg-black/20 shadow-[0_40px_120px_-70px_rgba(0,0,0,0.9)]"
                @click.capture="mouseShortcuts.handleClickCapture"
                @contextmenu.capture="mouseShortcuts.handleContextMenuCapture"
                @mousedown.capture="mouseShortcuts.handleMouseDownCapture"
                @auxclick.capture="mouseShortcuts.handleAuxClickCapture"
            >
                <VibeLayout
                    :key="viewerKey"
                    :ref="handleVibeRef"
                    class="h-full min-h-0 min-w-0 flex-1"
                    :class="{ 'atlas-file-viewer-wide-aside': shouldReserveFileSheetSpace }"
                    :style="shouldReserveFileSheetSpace ? { '--vibe-fullscreen-aside-width': '33rem' } : undefined"
                    v-bind="vibeLayoutBindings"
                    @update:active-index="props.updateActiveIndex"
                    @update:surface-mode="props.updateSurfaceMode"
                    @asset-errors="props.handleAssetErrors"
                    @asset-loads="props.handleAssetLoads"
                    @items-change="props.handleItemsChange"
                >
                    <template #grid-item-overlay="{ item, hovered, active, index }">
                        <TabContentV2GridOverlay
                            v-if="getFeedItemFromVibeItem(item as VibeViewerItem)"
                            :active="active"
                            :hovered="hovered"
                            :index="index"
                            :dimmed="shouldDimGridItemForContainerDrawer(item as VibeViewerItem)"
                            :item="getFeedItemFromVibeItem(item as VibeViewerItem)!"
                            :total-items="vibeStatus.itemCount"
                            :vibe-item="item as VibeViewerItem"
                            :containers="containerInteractions"
                            :item-interactions="itemInteractions"
                            :local-file-deletion="localFileDeletion"
                            :open-file-sheet="openFileSheetForItem"
                            :source-watch-refresh="sourceWatchRefresh"
                            :on-reaction="handleReaction"
                        />
                    </template>
                    <template #grid-footer>
                        <div class="pointer-events-none flex justify-center px-4 pb-4 pt-2">
                            <BrowseV2StatusBar
                                :status="vibeStatus"
                                :total-available="totalAvailable"
                                :auto-scroll-active="autoScrollActive"
                                :auto-scroll-max="autoScrollMax"
                                :auto-scroll-min="autoScrollMin"
                                :auto-scroll-speed="autoScrollSpeed"
                                :cancel-fill="cancelFill"
                                :can-toggle-page-loading-lock="canTogglePageLoadingLock"
                                :fill-actions-disabled="fillActionsDisabled"
                                :fill-call-count="fillCallCount"
                                :fill-call-count-max="fillCallCountMax"
                                :fill-call-count-min="fillCallCountMin"
                                :fill-until-count="fillUntilCount"
                                :fill-until-end="fillUntilEnd"
                                :page-loading-locked="pageLoadingLocked"
                                :set-auto-scroll-speed="setAutoScrollSpeed"
                                :set-fill-call-count="setFillCallCount"
                                :toggle-auto-scroll="toggleAutoScroll"
                                :toggle-page-loading-lock="togglePageLoadingLock"
                            />
                        </div>
                    </template>
                    <template #fullscreen-header-actions="{ item }">
                        <div class="flex min-w-0 flex-wrap items-center justify-end gap-2">
                            <div
                                v-if="getContainerPillTargets(item as VibeViewerItem).length > 0"
                                class="flex max-w-[min(34rem,45vw)] flex-row flex-wrap justify-end gap-1"
                                data-testid="browse-fullscreen-container-pills"
                            >
                                <div
                                    v-for="container in getContainerPillTargets(item as VibeViewerItem)"
                                    :key="container.id"
                                    class="cursor-pointer"
                                    data-container-pill-trigger
                                    @mouseenter="() => containerInteractions.pillHandlers.onMouseEnter(container.id)"
                                    @mouseleave="() => containerInteractions.pillHandlers.onMouseLeave(container.id)"
                                    @click.stop="(event) => containerInteractions.pillHandlers.onClick(container.id, event)"
                                    @dblclick.prevent.stop="(event) => containerInteractions.pillHandlers.onDoubleClick(container.id, event)"
                                    @contextmenu.prevent.stop="(event) => containerInteractions.pillHandlers.onContextMenu(container.id, event)"
                                    @mousedown.stop="containerInteractions.pillHandlers.onMouseDown"
                                    @mouseup.stop="(event) => { if (event.button === 1) containerInteractions.pillHandlers.onAuxClick(container.id, event) }"
                                >
                                    <Pill
                                        :label="container.type"
                                        :value="containerInteractions.badges.getItemCountForContainerId(container.id)"
                                        :variant="containerInteractions.badges.getVariantForContainerType(container.type)"
                                        :dismissible="containerInteractions.isBlacklistable(container) ? 'danger' : false"
                                        @dismiss="() => containerInteractions.pillHandlers.onDismiss(container)"
                                    />
                                </div>
                            </div>
                            <button
                                type="button"
                                class="inline-flex h-8 w-8 items-center justify-center border border-twilight-indigo-500 bg-prussian-blue-900/55 text-twilight-indigo-100 transition hover:border-smart-blue-400 hover:bg-prussian-blue-800 hover:text-white"
                                :aria-label="fileSheetState.isOpen ? 'Hide file sheet' : 'Show file sheet'"
                                @click="fileSheetState.isOpen ? closeFileSheet() : openFileSheet()"
                            >
                                <PanelRightOpen :size="16" />
                            </button>
                        </div>
                    </template>
                    <template #fullscreen-footer="{ item, index, total }">
                        <div
                            data-testid="browse-fullscreen-reactions"
                            class="flex justify-center"
                        >
                            <FileReactions
                                :file-id="(item as Record<string, unknown>).fileId as number"
                                :reaction="((item as Record<string, unknown>).feedItem as FeedItem | undefined)?.reaction ?? null"
                                :blacklisted-at="((item as Record<string, unknown>).feedItem as FeedItem | undefined)?.blacklisted_at ?? null"
                                :previewed-count="((item as Record<string, unknown>).feedItem as FeedItem | undefined)?.previewed_count ?? 0"
                                :viewed-count="((item as Record<string, unknown>).feedItem as FeedItem | undefined)?.seen_count ?? 0"
                                :current-index="index"
                                :total-items="total"
                                :icon-size="16"
                                surface="none"
                                variant="small"
                                @reaction="(type) => handleReaction(item as VibeViewerItem, type)"
                                @blacklist="() => handleBlacklist(item as VibeViewerItem)"
                            />
                        </div>
                    </template>
                    <template #fullscreen-aside="{ nextPreviews, total }">
                        <FileViewerSheet
                            v-if="fileSheetState.isOpen"
                            embedded
                            :is-open="fileSheetState.isOpen"
                            :file-id="fileSheetFileId"
                            :file-data="fileViewerData.fileData.value"
                            :is-loading="fileViewerData.isLoadingFileData.value"
                            :is-prompt-loading="isSheetPromptLoading"
                            :next-previews="nextPreviews"
                            :prompt="promptDialog.data.currentPromptData.value"
                            :show-prompt="showSheetPrompt"
                            :total-items="total"
                            @close="closeFileSheet"
                            @select-preview="props.updateActiveIndex"
                        />
                    </template>
                </VibeLayout>

                <div
                    v-if="showFullscreenPageLoadingLock"
                    class="pointer-events-none absolute inset-0 z-10 grid place-items-center px-4"
                    data-testid="browse-fullscreen-page-loading-locked-overlay"
                >
                    <TabContentV2FullscreenPageLoadingLock
                        :can-unlock="canTogglePageLoadingLock"
                        :unlock-page-loading="unlockPageLoading"
                    />
                </div>

                <template v-if="surfaceMode === 'list'">
                    <Transition
                        enter-active-class="transform-gpu transition-all duration-500 ease-out"
                        enter-from-class="translate-x-full opacity-0"
                        enter-to-class="translate-x-0 opacity-100"
                        leave-active-class="transform-gpu transition-all duration-300 ease-in"
                        leave-from-class="translate-x-0 opacity-100"
                        leave-to-class="translate-x-full opacity-0"
                    >
                        <div
                            v-if="isFileSheetOverlay"
                            class="absolute inset-0 z-20 flex max-w-full justify-end"
                            data-test="file-viewer-sheet-overlay"
                            @click.self="closeFileSheet"
                        >
                            <FileViewerSheet
                                :is-open="fileSheetState.isOpen"
                                :file-id="fileSheetFileId"
                                :file-data="fileViewerData.fileData.value"
                                :is-loading="fileViewerData.isLoadingFileData.value"
                                :is-prompt-loading="isSheetPromptLoading"
                                :prompt="promptDialog.data.currentPromptData.value"
                                :show-prompt="showSheetPrompt"
                                data-test="file-viewer-sheet-panel"
                                @close="closeFileSheet"
                            />
                        </div>
                    </Transition>
                    <FileViewerSheet
                        v-if="!isFileSheetOverlay"
                        data-test="file-viewer-sheet-inline"
                        :is-open="fileSheetState.isOpen"
                        :file-id="fileSheetFileId"
                        :file-data="fileViewerData.fileData.value"
                        :is-loading="fileViewerData.isLoadingFileData.value"
                        :is-prompt-loading="isSheetPromptLoading"
                        :prompt="promptDialog.data.currentPromptData.value"
                        :show-prompt="showSheetPrompt"
                        @close="closeFileSheet"
                    />
                </template>

                <TabContentContainerDrawer
                    :open="containerInteractions.drawer.state.isOpen.value"
                    :container="containerInteractions.drawer.derived.container.value"
                    :items="containerInteractions.drawer.derived.items.value"
                    :close-on-mouse-leave="true"
                    @update:open="containerInteractions.drawer.actions.setOpen"
                />
            </div>
        </div>

        <TabContentContainerSheet
            :open="containerInteractions.sheet.state.isOpen.value"
            :container="containerInteractions.sheet.derived.container.value"
            :items="containerInteractions.sheet.derived.items.value"
            :item-interactions="itemInteractions"
            @close="containerInteractions.sheet.actions.close"
        />

        <Sheet :open="showGlobalStartPanel" @update:open="handleGlobalStartPanelOpenChange">
            <SheetContent
                id="browse-global-start-panel"
                side="right"
                :show-close="false"
                class="w-full max-w-none gap-0 border-l border-white/10 p-0 sm:max-w-[30rem]"
                data-test="browse-global-start-panel"
            >
                <BrowseGlobalStartPanel :open="showGlobalStartPanel" @close="closeGlobalStartPanel" />
            </SheetContent>
        </Sheet>

        <DownloadedReactionDialog
            :open="downloadedReactionPrompt.data.open.value"
            :update-open="downloadedReactionPrompt.setOpen"
            :choose-react="downloadedReactionPrompt.chooseReact"
            :choose-redownload="downloadedReactionPrompt.chooseRedownload"
            :close-dialog="downloadedReactionPrompt.close"
        />

        <LocalFileDeleteDialog
            :open="localFileDeletion.state.dialogOpen.value"
            :filename="localFileDeletion.state.itemToDelete.value?.filename ?? null"
            :deleting="localFileDeletion.state.deleting.value"
            :delete-error="localFileDeletion.state.deleteError.value"
            @update:open="(value) => { if (!value) localFileDeletion.actions.close(); }"
            @cancel="localFileDeletion.actions.close"
            @confirm="localFileDeletion.actions.confirm"
        />
    </div>
</template>
