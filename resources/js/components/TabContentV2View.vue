<script setup lang="ts">
import { computed } from 'vue';
import { useEventListener } from '@vueuse/core';
import { VibeLayout, type VibeAssetErrorEvent, type VibeAssetLoadEvent, type VibeHandle, type VibeInitialState, type VibeResolveResult, type VibeViewerItem, type VibeStatus } from '@wyxos/vibe';
import { PanelRightOpen } from 'lucide-vue-next';
import type { BrowseFormInstance } from '@/composables/useBrowseForm';
import type { LocalFileDeletion } from '@/composables/useLocalFileDeletion';
import type { TabContentContainerInteractions } from '@/composables/useTabContentContainerInteractions';
import type { TabContentItemInteractions } from '@/composables/useTabContentItemInteractions';
import type { TabContentPromptDialog as TabContentPromptDialogHandle } from '@/composables/useTabContentPromptDialog';
import type { FeedItem, TabData } from '@/composables/useTabs';
import type { ServiceOption } from '@/lib/browseCatalog';
import type { BrowseFeedHandle } from '@/types/browse';
import type { ReactionType } from '@/types/reaction';
import BrowseV2StatusBar from './BrowseV2StatusBar.vue';
import ContainerBlacklistManager from './container-blacklist/ContainerBlacklistManager.vue';
import DownloadedReactionDialog from './DownloadedReactionDialog.vue';
import FileReactions from './FileReactions.vue';
import FileViewerSheet from './FileViewerSheet.vue';
import LocalFileDeleteDialog from './LocalFileDeleteDialog.vue';
import TabContentContainerDrawer from './TabContentContainerDrawer.vue';
import TabContentPromptDialog from './TabContentPromptDialog.vue';
import TabContentServiceHeader from './TabContentServiceHeader.vue';
import TabContentStartForm from './TabContentStartForm.vue';
import TabContentV2GridOverlay from './TabContentV2GridOverlay.vue';

type FileSheetState = {
    isOpen: boolean;
};

type FileViewerDataShape = {
    fileData: { value: unknown };
    isLoadingFileData: { value: boolean };
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
    promptDialog: TabContentPromptDialogHandle;
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
    updateSource: (value: string | null) => void;
    updateSurfaceMode: (value: 'fullscreen' | 'list') => void;
    updateService: (value: string) => void | Promise<void>;
    surfaceMode: 'fullscreen' | 'list';
    toggleAutoScroll?: () => void;
    vibeInitialCursor: string | null;
    vibeInitialState: VibeInitialState | undefined;
    vibeStatus: VibeStatus;
    viewerKey: string;
}>();

function handleVibeRef(instance: unknown): void {
    props.setVibeHandle((instance as VibeHandle | null) ?? null);
}

function getFeedItemFromVibeItem(item: VibeViewerItem): FeedItem | null {
    return (item.feedItem as FeedItem | undefined) ?? null;
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
    if (!props.headerMasonry?.lockPageLoading || !props.headerMasonry?.unlockPageLoading) {
        return;
    }

    if (props.headerMasonry.pageLoadingLocked) {
        props.headerMasonry.unlockPageLoading();
        return;
    }

    props.headerMasonry.lockPageLoading();
}

function getFullscreenReactionPositionClasses(item: VibeViewerItem): string {
    if (item.type === 'audio' || item.type === 'video') {
        return 'bottom-[calc(env(safe-area-inset-bottom,0px)+6.5rem)] max-[720px]:bottom-[calc(env(safe-area-inset-bottom,0px)+8rem)]';
    }

    return 'bottom-[calc(env(safe-area-inset-bottom,0px)+1rem)]';
}

const vibeLayoutBindings = computed(() => ({
    activeIndex: props.activeIndex,
    emptyStateMode: 'hidden' as const,
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
                    class="h-full min-h-0 w-full"
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
                            :prompt-dialog="promptDialog"
                            :local-file-deletion="localFileDeletion"
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
                                :bulk-actions-disabled="Boolean(headerMasonry?.isLoading) || vibeStatus.itemCount === 0"
                                :cancel-fill="cancelFill"
                                :can-toggle-page-loading-lock="Boolean(headerMasonry?.lockPageLoading && headerMasonry?.unlockPageLoading)"
                                :fill-actions-disabled="fillActionsDisabled"
                                :fill-call-count="fillCallCount"
                                :fill-call-count-max="fillCallCountMax"
                                :fill-call-count-min="fillCallCountMin"
                                :fill-until-count="fillUntilCount"
                                :fill-until-end="fillUntilEnd"
                                :page-loading-locked="Boolean(headerMasonry?.pageLoadingLocked)"
                                :perform-loaded-items-bulk-action="itemInteractions.performLoadedItemsBulkAction"
                                :set-auto-scroll-speed="setAutoScrollSpeed"
                                :set-fill-call-count="setFillCallCount"
                                :toggle-auto-scroll="toggleAutoScroll"
                                :toggle-page-loading-lock="togglePageLoadingLock"
                            />
                        </div>
                    </template>
                    <template #fullscreen-overlay="{ item, index, total }">
                        <div class="pointer-events-none absolute inset-0 z-[5]">
                            <div
                                data-testid="browse-fullscreen-reactions"
                                class="pointer-events-auto absolute left-1/2 -translate-x-1/2"
                                :class="getFullscreenReactionPositionClasses(item as VibeViewerItem)"
                            >
                                <FileReactions
                                    :file-id="(item as Record<string, unknown>).fileId as number"
                                    :reaction="((item as Record<string, unknown>).feedItem as FeedItem | undefined)?.reaction ?? null"
                                    :blacklisted-at="((item as Record<string, unknown>).feedItem as FeedItem | undefined)?.blacklisted_at ?? null"
                                    :previewed-count="((item as Record<string, unknown>).feedItem as FeedItem | undefined)?.previewed_count ?? 0"
                                    :viewed-count="((item as Record<string, unknown>).feedItem as FeedItem | undefined)?.seen_count ?? 0"
                                    :current-index="index"
                                    :total-items="total"
                                    variant="default"
                                    @reaction="(type) => handleReaction(item as VibeViewerItem, type)"
                                    @blacklist="() => handleBlacklist(item as VibeViewerItem)"
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

                <TabContentContainerDrawer
                    :open="containerInteractions.drawer.state.isOpen.value"
                    :container="containerInteractions.drawer.derived.container.value"
                    :items="containerInteractions.drawer.derived.items.value"
                    :close-on-mouse-leave="containerInteractions.drawer.state.openReason?.value === 'hover'"
                    @update:open="containerInteractions.drawer.actions.setOpen"
                />
            </div>
        </div>

        <TabContentPromptDialog
            :open="promptDialog.data.promptDialogOpen.value"
            :item-id="promptDialog.data.promptDialogItemId.value"
            :loading="promptDialog.data.promptDataLoading.value"
            :prompt="promptDialog.data.currentPromptData.value"
            :update-open="promptDialog.setOpen"
            :copy-prompt="promptDialog.copy"
            :test-prompt="promptDialog.openTestPage"
            :close-prompt="promptDialog.close"
        />

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
