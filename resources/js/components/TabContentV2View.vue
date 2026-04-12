<script setup lang="ts">
import { computed } from 'vue';
import { useEventListener } from '@vueuse/core';
import { VibeLayout, type VibeAssetErrorEvent, type VibeAssetLoadEvent, type VibeHandle, type VibeInitialState, type VibeResolveResult, type VibeViewerItem, type VibeStatus } from '@wyxos/vibe';
import { ArrowLeft, PanelRightOpen } from 'lucide-vue-next';
import type { BrowseFormInstance } from '@/composables/useBrowseForm';
import type { LocalFileDeletion } from '@/composables/useLocalFileDeletion';
import type { TabContentContainerInteractions } from '@/composables/useTabContentContainerInteractions';
import type { TabContentItemInteractions } from '@/composables/useTabContentItemInteractions';
import type { LoadedItemsAction } from '@/composables/useTabContentLoadedItemsActions';
import type { TabContentPromptDialog as TabContentPromptDialogHandle } from '@/composables/useTabContentPromptDialog';
import type { FeedItem, TabData } from '@/composables/useTabs';
import type { ServiceOption } from '@/lib/browseCatalog';
import type { BrowseFeedHandle } from '@/types/browse';
import type { ReactionType } from '@/types/reaction';
import { Button } from '@/components/ui/button';
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
    availableServices: ServiceOption[];
    availableSources: ServiceOption[];
    applyFilters: () => Promise<void>;
    applyService: () => Promise<void>;
    cancelLoad: () => void;
    closeFileSheet: () => void;
    containerInteractions: TabContentContainerInteractions;
    currentVisibleItem: FeedItem | null;
    downloadedReactionPrompt: DownloadedReactionPromptShape;
    fileSheetState: FileSheetState;
    fileViewerData: FileViewerDataShape;
    form: BrowseFormInstance;
    goToFirstPage: () => Promise<void>;
    handleAssetErrors: (errors: VibeAssetErrorEvent[]) => void;
    handleAssetLoads: (loads: VibeAssetLoadEvent[]) => void;
    handleContainerBlacklistChange: (change: { action: 'created' | 'deleted'; blacklist: import('@/types/container-blacklist').ContainerBlacklist }) => void;
    handleLoadedItemsAction: (action: LoadedItemsAction) => void | Promise<void>;
    handleReaction: (item: VibeViewerItem, type: ReactionType) => void | Promise<void>;
    headerMasonry: BrowseFeedHandle | null;
    isFilterSheetOpen: boolean;
    itemInteractions: TabContentItemInteractions;
    localFileDeletion: LocalFileDeletion;
    localService: ServiceOption | null | undefined;
    loadNext: () => void | Promise<void>;
    loadPrevious: () => void | Promise<void>;
    loadedItemsCount: number;
    masonryRenderKey: number;
    mouseShortcuts: MouseShortcutHandlers;
    openFileSheet: () => void;
    promptDialog: TabContentPromptDialogHandle;
    resolve: (params: { cursor: string | null; pageSize: number; signal?: AbortSignal }) => Promise<VibeResolveResult>;
    setFilterSheetOpen: (value: boolean) => void;
    setLocalMode: (value: boolean) => void;
    setVibeHandle: (value: VibeHandle | null) => void;
    shouldShowForm: boolean;
    tab: TabData | null;
    updateFeed: (value: 'local' | 'online') => void;
    updateActiveIndex: (value: number) => void;
    activeLoadedItemsAction: LoadedItemsAction | null;
    retryLoad: () => void | Promise<void>;
    updateSource: (value: string | null) => void;
    updateSurfaceMode: (value: 'fullscreen' | 'list') => void;
    updateService: (value: string) => void | Promise<void>;
    surfaceMode: 'fullscreen' | 'list';
    vibeFeedMode: 'dynamic' | 'static';
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

const vibeLayoutBindings = computed(() => ({
    activeIndex: props.activeIndex,
    fillDelayMs: 2000,
    fillDelayStepMs: 1000,
    initialCursor: props.vibeInitialCursor,
    initialState: props.vibeInitialState,
    mode: props.vibeFeedMode,
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
            :loaded-items-count="loadedItemsCount"
            :active-loaded-items-action="activeLoadedItemsAction"
            :on-run-loaded-items-action="handleLoadedItemsAction"
            :cancel-masonry-load="cancelLoad"
            :go-to-first-page="goToFirstPage"
            :load-next-page="loadNext"
        >
            <ContainerBlacklistManager
                :ref="containerInteractions.managerRef"
                :disabled="Boolean(headerMasonry?.isLoading)"
                @blacklists-changed="handleContainerBlacklistChange"
            />
            <div class="flex items-center gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    class="h-10 gap-2 px-3"
                    :disabled="headerMasonry?.isLoading || !vibeStatus.hasPreviousPage"
                    @click="loadPrevious"
                >
                    <ArrowLeft :size="14" />
                    <span>Previous</span>
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    class="h-10 gap-2 px-3"
                    :disabled="headerMasonry?.isLoading || vibeStatus.loadState !== 'failed'"
                    @click="retryLoad"
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
                >
                    <template #grid-item-overlay="{ item, hovered, active, index }">
                        <TabContentV2GridOverlay
                            v-if="getFeedItemFromVibeItem(item as VibeViewerItem)"
                            :active="active"
                            :hovered="hovered"
                            :index="index"
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
                            <BrowseV2StatusBar :status="vibeStatus" />
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
