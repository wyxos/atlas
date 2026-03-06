<script setup lang="ts">
import { computed, provide, ref, shallowRef, toRef, watch } from 'vue';
import type { TabData, FeedItem } from '@/composables/useTabs';
import { Masonry, MasonryItem } from '@wyxos/vibe';
import type { MasonryInstance } from '@wyxos/vibe';
import { Info, Loader2 } from 'lucide-vue-next';
import FileViewer from './FileViewer.vue';
import BrowseStatusBar from './BrowseStatusBar.vue';
import FileReactions from './FileReactions.vue';
import DislikeProgressBar from './DislikeProgressBar.vue';
import { Button } from '@/components/ui/button';
import Pill from './ui/Pill.vue';
import { useBrowseService } from '@/composables/useBrowseService';
import { useItemPreview } from '@/composables/useItemPreview';
import { BrowseFormKey, createBrowseForm } from '@/composables/useBrowseForm';
import type { ServiceOption } from '@/composables/useBrowseService';
import { useTabContentBrowseState } from '@/composables/useTabContentBrowseState';
import { useTabContentInteractions } from '@/composables/useTabContentInteractions';
import TabContentPromptDialog from './TabContentPromptDialog.vue';
import TabContentStartForm from './TabContentStartForm.vue';
import TabContentServiceHeader from './TabContentServiceHeader.vue';
import ContainerBlacklistManager from './container-blacklist/ContainerBlacklistManager.vue';
import BatchModerationToast from './toasts/BatchModerationToast.vue';
import { useToast } from 'vue-toastification';
// Diagnostic utilities (dev-only, tree-shaken in production)
import { analyzeItemSizes, logItemSizeDiagnostics } from '@/utils/itemSizeDiagnostics';
import type { ReactionType } from '@/types/reaction';

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

const emit = defineEmits<{
    'update:loading': [isLoading: boolean];
}>();

// Local state for this tab
// Use shallowRef to reduce Vue reactivity overhead with large arrays (3k+ items)
// This prevents deep reactivity tracking on each item, significantly improving performance
const items = shallowRef<FeedItem[]>([]);

// Diagnostic: Log item size analysis when items change (only in dev mode)
watch(
    () => items.value,
    (newItems) => {
        if (import.meta.env.DEV && newItems.length > 0) {
            // Only log when we have a significant number of items to avoid spam
            if (newItems.length >= 100 && newItems.length % 500 === 0) {
                const diagnostics = analyzeItemSizes(newItems);
                logItemSizeDiagnostics(diagnostics);
            }
        }
    },
    { immediate: true, deep: false }
);

const masonry = ref<MasonryInstance | null>(null);
const isFilterSheetOpen = ref(false);

// Internal tab data - loaded from API
const tab = ref<TabData | null>(null);

// Per-tab browse form (provided so all descendant composables/components share the same instance)
const form = createBrowseForm();
provide(BrowseFormKey, form);

// Container refs for FileViewer
const masonryContainer = ref<HTMLElement | null>(null);
const tabContentContainer = ref<HTMLElement | null>(null);
const fileViewer = ref<InstanceType<typeof FileViewer> | null>(null);

// Item preview composable (needs to be initialized early)
const itemPreview = useItemPreview(items, computed(() => tab.value ?? undefined));

// Browse service composable - fetch services if not provided via prop
const { availableServices: localServices, availableSources, localService, fetchServices, fetchSources } = useBrowseService();

// Use prop services if available, otherwise use local services
const availableServices = computed(() => {
    return props.availableServices.length > 0 ? props.availableServices : localServices.value;
});

const {
    totalAvailable,
    masonryRenderKey,
    startPageToken,
    restoredPages,
    loadAtPage,
    isTabRestored,
    shouldShowForm,
    selectedService,
    currentTabService,
    hasServiceSelected,
    updateService,
    formatTabLabel,
    getPage,
    applyFilters,
    goToFirstPage,
    applyService,
} = useTabContentBrowseState({
    tabId: toRef(props, 'tabId'),
    form,
    items,
    tab,
    availableServices,
    localService,
    fetchServices,
    fetchSources,
    clearPreviewedItems: itemPreview.clearPreviewedItems,
    resetPreloadedItems: clearInteractionPreloadedItems,
    onLoadingStart: handleLoadingStart,
    onLoadingStop: handleLoadingStop,
    onUpdateTabLabel: props.onUpdateTabLabel,
});

// Accumulate moderation data from each page load
const accumulatedModeration = ref<Array<{ id: number; action_type: string; thumbnail?: string }>>([]);

const toast = useToast();

/**
 * Show moderation toast with accumulated moderated files.
 */
function showModerationToast(moderatedFiles: Array<{ id: number; action_type: string; thumbnail?: string }>): void {
    if (moderatedFiles.length === 0) {
        return;
    }

    const toastId = `moderation-${Date.now()}`;

    // Prepare previews for the toast (up to 5)
    const previews = moderatedFiles.slice(0, 5).map(file => ({
        id: file.id,
        action_type: file.action_type,
        thumbnail: file.thumbnail,
    }));

    toast(
        {
            component: BatchModerationToast,
            props: {
                toastId,
                previews,
                totalCount: moderatedFiles.length,
                allFiles: moderatedFiles, // Pass all files for the modal
            },
        },
        {
            id: toastId,
            closeButton: false,
            closeOnClick: false,
            toastClassName: 'moderation-toast-wrapper',
            bodyClassName: 'moderation-toast-body',
        }
    );
}

// Handle loading:stop
function onLoadingStop(): void {
    // Show moderation toast if there are accumulated moderated files
    if (accumulatedModeration.value.length > 0) {
        showModerationToast(accumulatedModeration.value);
        // Clear accumulated moderation after showing toast
        accumulatedModeration.value = [];
    }
}

const layout = {
    gutterX: 12,
    gutterY: 12,
    sizes: { base: 1, sm: 2, md: 3, lg: 4, '2xl': 10 },
};

// Handle masonry loading state changes via events
function handleLoadingStart(): void {
    emit('update:loading', true);
    if (props.onLoadingChange) {
        props.onLoadingChange(true);
    }
}

function handleLoadingStop(): void {
    emit('update:loading', false);
    if (props.onLoadingChange) {
        props.onLoadingChange(false);
    }

    // Also call onLoadingStop for moderation toast handling
    onLoadingStop();
}

function clearInteractionPreloadedItems(): void {
    resetPreloadedItems();
}

const {
    hoveredItemId,
    containerBadges,
    containerBlacklistManager,
    containerPillInteractions,
    promptData,
    autoDislikeQueue,
    getItemIndex,
    isItemPreloaded,
    hasActiveReaction,
    resetPreloadedItems,
    onMasonryClick,
    onMasonryMouseDown,
    handleResetFilters,
    handleModerationRulesChanged,
    cancelMasonryLoad,
    loadNextPage,
    handleMasonryRemoved,
    handleMasonryItemClick,
    handleMasonryItemContextMenu,
    handleMasonryItemMouseDown,
    handleMasonryItemAuxClick,
    handleMasonryItemMouseEnter,
    handleMasonryItemMouseLeave,
    handleFileViewerOpen,
    handleFileViewerClose,
    handleBatchPreloaded,
    handleBatchFailures,
    handleItemPreloaded,
    handleContainerPillMouseEnter,
    handleContainerPillMouseLeave,
    handleContainerPillClick,
    handleContainerPillDblClick,
    handleContainerPillContextMenu,
    handleContainerPillAuxClick,
    handleContainerPillMouseDown,
    handlePillDismiss,
    handlePromptDialogClick,
    handleFileReaction,
    handleFileViewerReaction,
    handleCopyPromptClick,
    handleTestPromptClick,
    handlePromptDialogUpdate,
    isContainerBlacklistable,
} = useTabContentInteractions({
    items,
    tab,
    form,
    masonry,
    fileViewer,
    availableServices,
    itemPreview,
    formatTabLabel,
    onReaction: props.onReaction,
    onOpenContainerTab: props.onOpenContainerTab,
});

defineExpose({
    // Expose compatibility fields used by some Browse tests
    selectedService,
    currentTabService,
    hasServiceSelected,
    loadAtPage,
    isTabRestored,
    containerPillInteractions,
    // Expose the per-tab form for tests/debugging
    browseForm: form,
    masonry,
});
</script>

<template>
    <div v-if="tab" ref="tabContentContainer" class="flex-1 min-h-0 flex flex-col relative">
        <TabContentServiceHeader v-if="!shouldShowForm" :form="form" :available-services="availableServices"
            :available-sources="availableSources" :local-service="localService ?? null" :masonry="masonry"
            :filter-sheet-open="isFilterSheetOpen"
            :update-filter-sheet-open="(value) => isFilterSheetOpen = value"
            :update-feed="(value) => form.data.feed = value" :update-service="updateService"
            :update-source="(value) => form.data.source = value" :apply-service="applyService"
            :apply-filters="applyFilters" :reset-filters="handleResetFilters"
            :rules-changed="handleModerationRulesChanged" :cancel-masonry-load="cancelMasonryLoad" :load-next-page="loadNextPage">
            <ContainerBlacklistManager ref="containerBlacklistManager" :disabled="masonry?.isLoading"
                @blacklists-changed="handleModerationRulesChanged" />
        </TabContentServiceHeader>

        <!-- Masonry Content -->
        <div class="flex-1 min-h-0 overflow-hidden">
            <!-- Masonry -->
            <div class="relative flex h-full min-h-0 flex-col overflow-hidden masonry-container" ref="masonryContainer" @click="onMasonryClick"
                @contextmenu.prevent="onMasonryClick" @mousedown="onMasonryMouseDown">

                <TabContentStartForm v-if="shouldShowForm" :form="form" :available-services="availableServices"
                    :available-sources="availableSources" :is-loading="masonry?.isLoading ?? false"
                    :set-local-mode="(value) => form.isLocalMode.value = value"
                    :update-service="updateService" :update-source="(value) => form.data.source = value"
                    :apply-service="applyService" />

                <Masonry v-else :key="`${tab.id}-${masonryRenderKey}`" ref="masonry" v-model:items="items"
                    class="min-h-0 flex-1 !mt-0 !py-0 !border-0"
                     :mode="form.isLocalMode.value ? 'default' : 'backfill'"
                    :get-content="getPage" :page="startPageToken" :restored-pages="restoredPages ?? undefined"
                    :page-size="Number(form.data.limit)"
                    :gap-x="layout.gutterX" :gap-y="layout.gutterY"
                    @preloaded="handleBatchPreloaded" @failures="handleBatchFailures"
                    @removed="handleMasonryRemoved" data-test="masonry-component">
                    <MasonryItem @preloaded="handleItemPreloaded">
                        <template #loader>
                            <div class="flex h-full w-full items-center justify-center text-twilight-indigo-200">
                                <Loader2 :size="20" class="animate-spin" />
                            </div>
                        </template>

                        <template #error="{ error }">
                            <p class="text-center text-xs font-medium text-danger-100">Failed to load {{ error }}</p>
                        </template>

                        <template #overlay="{ item, remove }">
                            <div class="relative h-full w-full"
                                @mouseenter="(e: MouseEvent) => handleMasonryItemMouseEnter(e, item as FeedItem)"
                                @mouseleave="(e: MouseEvent) => handleMasonryItemMouseLeave(e, item as FeedItem)"
                                :data-file-id="(item as FeedItem).id"
                                :class="[
                                    'overflow-hidden rounded-xl transition-colors transition-opacity duration-200',
                                    containerBadges.getMasonryItemClasses.value(item as FeedItem),
                                ]"
                                @click="(e: MouseEvent) => handleMasonryItemClick(e, item as FeedItem)"
                                @contextmenu="(e: MouseEvent) => handleMasonryItemContextMenu(e, item as FeedItem)"
                                @mousedown="(e: MouseEvent) => handleMasonryItemMouseDown(e, item as FeedItem)"
                                @auxclick="(e: MouseEvent) => handleMasonryItemAuxClick(e, item as FeedItem)">
                                <!-- When hovering a container pill, dim non-siblings to focus context -->
                                <div
                                    class="absolute inset-0 bg-black/50 pointer-events-none transition-opacity duration-200"
                                    :class="(containerBadges.activeHoveredContainerId.value !== null && !containerBadges.isSiblingItem(item as FeedItem, containerBadges.activeHoveredContainerId.value)) ? 'opacity-100' : 'opacity-0'"
                                />
                                <!-- Container badges (shows on hover with type and count) -->
                                <Transition name="fade">
                                    <div v-if="hoveredItemId === ((item as FeedItem).id as number) && isItemPreloaded((item as FeedItem).id as number) && containerBadges.getContainersForItem(item as FeedItem).length > 0"
                                        class="absolute top-2 left-2 z-50 pointer-events-auto flex flex-col gap-1">
                                        <div v-for="container in containerBadges.getContainersForItem(item as FeedItem)"
                                            :key="container.id" class="cursor-pointer"
                                            @mouseenter="handleContainerPillMouseEnter(container.id)"
                                            @mouseleave="handleContainerPillMouseLeave"
                                            @click.stop="(e: MouseEvent) => handleContainerPillClick(container.id, e)"
                                            @dblclick.prevent.stop="(e: MouseEvent) => handleContainerPillDblClick(container.id, e)"
                                            @contextmenu.prevent.stop="(e: MouseEvent) => handleContainerPillContextMenu(container.id, e)"
                                            @auxclick.prevent.stop="(e: MouseEvent) => handleContainerPillAuxClick(container.id, e)"
                                            @mousedown.stop="handleContainerPillMouseDown">
                                            <Pill :label="container.type"
                                                :value="containerBadges.getItemCountForContainerId(container.id)"
                                                :variant="containerBadges.getVariantForContainerType(container.type)"
                                                :dismissible="isContainerBlacklistable(container) ? 'danger' : false"
                                                @dismiss="() => handlePillDismiss(container)" />
                                        </div>
                                    </div>
                                </Transition>

                                <!-- Info badge -->
                                <Transition name="fade">
                                    <div v-if="hoveredItemId === ((item as FeedItem).id as number) && isItemPreloaded((item as FeedItem).id as number)"
                                        class="absolute top-2 right-2 z-50 pointer-events-auto">
                                        <Button variant="ghost" size="sm"
                                            class="h-7 w-7 p-0 bg-black/50 hover:bg-black/70 text-white"
                                            @click.stop="handlePromptDialogClick(item as FeedItem)" aria-label="Show prompt">
                                            <Info :size="14" />
                                        </Button>
                                    </div>
                                </Transition>

                                <!-- Hover reactions overlay -->
                                <Transition name="fade">
                                    <div v-if="(hoveredItemId === ((item as FeedItem).id as number) || hasActiveReaction(item as FeedItem)) && isItemPreloaded((item as FeedItem).id as number)"
                                        class="absolute bottom-0 left-0 right-0 flex justify-center pb-2 z-50 pointer-events-auto">
                                        <FileReactions :file-id="(item as FeedItem).id as number" :reaction="(item as FeedItem).reaction as ({ type: string } | null | undefined)"
                                            :previewed-count="(item as FeedItem).previewed_count"
                                            :viewed-count="(item as FeedItem).seen_count"
                                            :current-index="getItemIndex((item as FeedItem).id as number)"
                                            :total-items="items.length" variant="small" :remove-item="remove"
                                            @reaction="(type) => handleFileReaction(item as FeedItem, type)" />
                                    </div>
                                </Transition>

                                <!-- Progress Bar Overlay (only show if will_auto_dislike is true and countdown is active) -->
                                <DislikeProgressBar
                                    v-if="(item as FeedItem).will_auto_dislike && autoDislikeQueue.hasActiveCountdown((item as FeedItem).id as number)"
                                    :progress="autoDislikeQueue.getCountdownProgress((item as FeedItem).id as number)"
                                    :countdown="autoDislikeQueue.formatCountdown(autoDislikeQueue.getCountdownRemainingTime((item as FeedItem).id as number))"
                                    :is-frozen="autoDislikeQueue.isFrozen.value"
                                    :is-hovered="hoveredItemId === ((item as FeedItem).id as number) && autoDislikeQueue.hasActiveCountdown((item as FeedItem).id as number)" />
                            </div>
                        </template>
                    </MasonryItem>
                </Masonry>
            </div>
        </div>

        <!-- File Viewer -->
        <FileViewer ref="fileViewer" :container-ref="tabContentContainer" :masonry-container-ref="masonryContainer"
            :items="items" :masonry="masonry"
            @open="handleFileViewerOpen" @close="handleFileViewerClose"
            @reaction="handleFileViewerReaction" />

        <!-- Status/Pagination Info at Bottom (only show when masonry is visible, not when showing form) -->
        <BrowseStatusBar :items="items" :masonry="masonry" :tab="tab" :is-loading="masonry?.isLoading"
            :visible="tab !== null && tab !== undefined && !shouldShowForm" :total="totalAvailable"
            @first-page="goToFirstPage" />

        <TabContentPromptDialog :open="promptData.promptDialogOpen.value"
            :item-id="promptData.promptDialogItemId.value" :loading="promptData.promptDataLoading.value"
            :prompt="promptData.currentPromptData.value" :update-open="handlePromptDialogUpdate"
            :copy-prompt="handleCopyPromptClick" :test-prompt="handleTestPromptClick"
            :close-prompt="promptData.closePromptDialog" />

    </div>
    <div v-else class="flex items-center justify-center h-full" data-test="no-tab-message">
        <p class="text-twilight-indigo-300 text-lg">No tab selected</p>
    </div>
</template>

<style scoped>
@reference "../../css/app.css";

/* Vibe's default card border/background is tuned for a light theme.
   Atlas uses a darker UI, so we neutralize the default ring and let the
   container-hover border highlight (on the overlay wrapper) be the only border.
*/
:deep([data-testid="item-card"]),
:deep([data-testid="item-card-leaving"]) {
    @apply border-white/10 bg-white/5;
}

/* Vibe loader visuals: ensure placeholder + spinner are visible on dark theme. */
:deep([data-testid="item-card"] .bg-slate-100) {
    @apply bg-white/5;
}

:deep([data-testid="masonry-loader-spinner"] svg) {
    @apply text-white/70;
}

/* Optimized transitions using only transform and opacity (compositor-friendly) */
.ring-fade-enter-active {
    animation: ringAppear 0.6s ease-out;
    will-change: transform, opacity;
}

.ring-fade-leave-active {
    transition: opacity 0.2s ease, transform 0.2s ease;
    will-change: transform, opacity;
}

.ring-fade-leave-to {
    opacity: 0;
    transform: scale(0.98);
}

@keyframes ringAppear {
    0% {
        opacity: 0;
        transform: scale(0.98);
    }

    50% {
        opacity: 0.5;
    }

    100% {
        opacity: 1;
        transform: scale(1);
    }
}

.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.3s ease-in-out;
    will-change: opacity;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
