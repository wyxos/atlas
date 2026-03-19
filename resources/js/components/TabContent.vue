<script setup lang="ts">
import { computed, provide, reactive, ref, shallowRef, toRef, watch } from 'vue';
import type { PageToken } from '@wyxos/vibe';
import type { TabData, FeedItem } from '@/composables/useTabs';
import { Masonry, MasonryItem } from '@wyxos/vibe';
import type { MasonryInstance } from '@wyxos/vibe';
import { Loader2 } from 'lucide-vue-next';
import FileViewer from './FileViewer.vue';
import BrowseStatusBar from './BrowseStatusBar.vue';
import { useItemPreview } from '@/composables/useItemPreview';
import { BrowseFormKey, createBrowseForm } from '@/composables/useBrowseForm';
import type { BrowseFormData } from '@/composables/useBrowseForm';
import { createBrowseCatalog, type ServiceOption } from '@/lib/browseCatalog';
import { useTabContentBrowseState } from '@/composables/useTabContentBrowseState';
import { useTabContentContainerInteractions } from '@/composables/useTabContentContainerInteractions';
import { useTabContentItemInteractions } from '@/composables/useTabContentItemInteractions';
import { useDownloadedReactionPrompt } from '@/composables/useDownloadedReactionPrompt';
import { useTabContentPromptDialog } from '@/composables/useTabContentPromptDialog';
import DownloadedReactionDialog from './DownloadedReactionDialog.vue';
import TabContentPromptDialog from './TabContentPromptDialog.vue';
import TabContentStartForm from './TabContentStartForm.vue';
import TabContentServiceHeader from './TabContentServiceHeader.vue';
import TabContentMasonryItemOverlay from './TabContentMasonryItemOverlay.vue';
import ContainerBlacklistManager from './container-blacklist/ContainerBlacklistManager.vue';
import BatchModerationToast from './toasts/BatchModerationToast.vue';
import StatusToast from './toasts/StatusToast.vue';
import { useToast } from 'vue-toastification';
// Diagnostic utilities (dev-only, tree-shaken in production)
import { analyzeItemSizes, logItemSizeDiagnostics } from '@/utils/itemSizeDiagnostics';
import type { ReactionType } from '@/types/reaction';
import type { ContainerBlacklist } from '@/types/container-blacklist';
import { matchesLocalViewFilters } from '@/utils/localReactionState';

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

const browseCatalog = createBrowseCatalog();
const browseCatalogState = browseCatalog.state;

// Use prop services if available, otherwise use local services
const availableServices = computed(() => {
    return props.availableServices.length > 0 ? props.availableServices : browseCatalogState.availableServices.value;
});
const availableSources = browseCatalogState.availableSources;
const localService = browseCatalogState.localService;

const downloadedReactionPrompt = useDownloadedReactionPrompt();
const promptDialog = useTabContentPromptDialog(items);
const isResettingPreviewed = ref(false);

function matchesActiveLocalFilters(item: FeedItem): boolean {
    if (!form.isLocal.value) {
        return true;
    }

    return matchesLocalViewFilters(item, form.data.serviceFilters);
}

function resetItemPreloads(): void {
    itemInteractions.preload.reset();
}

const browse = useTabContentBrowseState({
    tabId: toRef(props, 'tabId'),
    form,
    data: {
        items,
        tab,
    },
    catalog: {
        availableServices,
        localService,
        loadServices: browseCatalog.actions.loadServices,
        loadSources: browseCatalog.actions.loadSources,
    },
    view: {
        clearPreviewedItems: itemPreview.clearPreviewedItems,
        resetPreloadedItems: resetItemPreloads,
    },
    events: {
        onPageLoadingChange: setBrowseLoading,
        onTabDataLoadingChange: setTabDataLoading,
        onUpdateTabLabel: props.onUpdateTabLabel,
    },
});
const browseState = reactive(browse.state);
const browseDerived = browse.derived;
const browseActions = browse.actions;
const selectedService = browseDerived.selectedService;
const currentTabService = browseDerived.currentTabService;
const hasServiceSelected = browseDerived.hasServiceSelected;
const loadBrowsePage = browseActions.getPage;
const applyService = browseActions.applyService;

const containerInteractions = useTabContentContainerInteractions({
    items,
    tab,
    form,
    masonry,
    matchesActiveLocalFilters,
    onReaction: props.onReaction,
    onOpenContainerTab: props.onOpenContainerTab,
});
const itemInteractions = useTabContentItemInteractions({
    items,
    tab,
    form,
    masonry,
    fileViewer,
    matchesActiveLocalFilters,
    itemPreview,
    onReaction: props.onReaction,
    promptDownloadedReaction: downloadedReactionPrompt.prompt,
    clearHoveredContainer: containerInteractions.clearHoveredContainer,
});

// Accumulate moderation data from each page load
const accumulatedModeration = ref<Array<{ id: number; action_type: string; thumbnail?: string }>>([]);
const activeContainerBlacklists = ref<ContainerBlacklist[]>([]);
type ContainerBlacklistChange = {
    action: 'created' | 'deleted';
    blacklist: ContainerBlacklist;
};

const toast = useToast();
const resetPreviewedToastId = 'reset-previewed-toast';

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

function showResetPreviewedToast(
    variant: 'success' | 'info' | 'error',
    title: string,
    description?: string,
): void {
    toast.dismiss(resetPreviewedToastId);
    toast(
        {
            component: StatusToast,
            props: {
                toastId: resetPreviewedToastId,
                variant,
                title,
                description,
            },
        },
        {
            id: resetPreviewedToastId,
            closeButton: false,
            closeOnClick: false,
        }
    );
}

async function handleResetPreviewedCounts(): Promise<void> {
    if (isResettingPreviewed.value) {
        return;
    }

    isResettingPreviewed.value = true;

    try {
        const resetCount = await itemInteractions.resetPreviewedState();
        if (resetCount === 0) {
            showResetPreviewedToast('info', 'No loaded items to reset.');
            return;
        }

        showResetPreviewedToast(
            'success',
            `Reset previewed counts for ${resetCount} item${resetCount === 1 ? '' : 's'}.`,
        );
    } catch (error) {
        console.error('Failed to reset previewed counts:', error);
        showResetPreviewedToast('error', 'Failed to reset previewed counts.');
    } finally {
        isResettingPreviewed.value = false;
    }
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

function setBrowseLoading(isLoading: boolean): void {
    emit('update:loading', isLoading);
    props.onLoadingChange?.(isLoading);

    if (!isLoading) {
        onLoadingStop();
    }
}

function setTabDataLoading(isLoading: boolean): void {
    props.onTabDataLoadingChange?.(isLoading);
}

function itemMatchesBlacklistedContainer(item: FeedItem, blacklist: ContainerBlacklist): boolean {
    const containers = Array.isArray(item.containers) ? item.containers : [];

    return containers.some((container) => {
        if (!container || typeof container !== 'object') {
            return false;
        }

        const candidate = container as {
            id?: number;
            source?: string;
            source_id?: string;
        };

        return candidate.id === blacklist.id
            || (
                candidate.source === blacklist.source
                && candidate.source_id === blacklist.source_id
            );
    });
}

function blacklistsMatch(left: ContainerBlacklist, right: ContainerBlacklist): boolean {
    return left.id === right.id
        || (
            left.source === right.source
            && left.source_id === right.source_id
        );
}

function upsertActiveContainerBlacklist(blacklist: ContainerBlacklist): void {
    activeContainerBlacklists.value = [
        blacklist,
        ...activeContainerBlacklists.value.filter((candidate) => !blacklistsMatch(candidate, blacklist)),
    ];
}

function removeActiveContainerBlacklist(blacklist: ContainerBlacklist): void {
    activeContainerBlacklists.value = activeContainerBlacklists.value.filter(
        (candidate) => !blacklistsMatch(candidate, blacklist)
    );
}

function getItemsInBlacklistedContainers(candidateItems: FeedItem[]): FeedItem[] {
    if (activeContainerBlacklists.value.length === 0) {
        return [];
    }

    return candidateItems.filter((item) => {
        return activeContainerBlacklists.value.some((blacklist) => {
            return itemMatchesBlacklistedContainer(item, blacklist);
        });
    });
}

function filterItemsByActiveContainerBlacklists(candidateItems: FeedItem[]): FeedItem[] {
    const itemsInBlacklistedContainers = getItemsInBlacklistedContainers(candidateItems);
    if (itemsInBlacklistedContainers.length === 0) {
        return candidateItems;
    }

    const blacklistedItemIds = new Set(itemsInBlacklistedContainers.map((item) => item.id));

    return candidateItems.filter((item) => {
        return !blacklistedItemIds.has(item.id);
    });
}

function applyActiveContainerBlacklistFilter(): void {
    const itemsInBlacklistedContainers = getItemsInBlacklistedContainers(items.value);
    if (itemsInBlacklistedContainers.length === 0) {
        return;
    }

    if (masonry.value) {
        void masonry.value.remove(itemsInBlacklistedContainers).catch((error) => {
            console.error('Failed to remove blacklisted container items from masonry:', error);
        });
        return;
    }

    items.value = filterItemsByActiveContainerBlacklists(items.value);
}

watch(
    () => items.value,
    () => {
        applyActiveContainerBlacklistFilter();
    },
    { deep: false },
);

function handleContainerBlacklistChange(change: ContainerBlacklistChange): void {
    if (change.action === 'created' && change.blacklist.action_type === 'blacklist') {
        upsertActiveContainerBlacklist(change.blacklist);
        applyActiveContainerBlacklistFilter();

        return;
    }

    removeActiveContainerBlacklist(change.blacklist);
}

async function getPage(page: PageToken, context?: BrowseFormData) {
    const result = await loadBrowsePage(page, context);
    const pageItems = Array.isArray(result?.items) ? result.items as FeedItem[] : [];
    const filteredItems = filterItemsByActiveContainerBlacklists(pageItems);

    if (filteredItems.length === pageItems.length) {
        return result;
    }

    return {
        ...result,
        items: filteredItems,
    };
}

defineExpose({
    selectedService,
    currentTabService,
    hasServiceSelected,
    getPage,
    applyService,
    hoveredItemIndex: itemInteractions.state.hoveredItemIndex,
    hoveredItemId: itemInteractions.state.hoveredItemId,
    containerBadges: containerInteractions.badges,
    containerPillInteractions: containerInteractions.pillInteractions,
    // Expose the per-tab form for tests/debugging
    browseForm: form,
    masonry,
    resetPreviewedState: itemInteractions.resetPreviewedState,
});
</script>

<template>
    <div v-if="tab" ref="tabContentContainer" class="flex-1 min-h-0 flex flex-col relative">
        <TabContentServiceHeader v-if="!browseState.shouldShowForm" :form="form" :available-services="availableServices"
            :available-sources="availableSources" :local-service="localService ?? null" :masonry="masonry"
            :filter-sheet-open="isFilterSheetOpen"
            :update-filter-sheet-open="(value) => isFilterSheetOpen = value"
            :update-feed="(value) => form.data.feed = value" :update-service="browseActions.updateService"
            :update-source="(value) => form.data.source = value" :apply-service="browseActions.applyService"
            :apply-filters="browseActions.applyFilters" :reset-filters="form.reset"
            :on-reset-previewed="handleResetPreviewedCounts" :is-resetting-previewed="isResettingPreviewed"
            :cancel-masonry-load="itemInteractions.masonry.cancelLoad"
            :load-next-page="itemInteractions.masonry.loadNextPage">
            <ContainerBlacklistManager :ref="containerInteractions.managerRef" :disabled="masonry?.isLoading"
                @blacklists-changed="handleContainerBlacklistChange"
            />
        </TabContentServiceHeader>

        <!-- Masonry Content -->
        <div class="flex-1 min-h-0 overflow-hidden">
            <!-- Masonry -->
            <div class="relative flex h-full min-h-0 flex-col overflow-hidden masonry-container" ref="masonryContainer"
                @click="itemInteractions.masonry.onClick" @contextmenu.prevent="itemInteractions.masonry.onClick"
                @mousedown="itemInteractions.masonry.onMouseDown">

                <TabContentStartForm v-if="browseState.shouldShowForm" :form="form" :available-services="availableServices"
                    :available-sources="availableSources" :is-loading="masonry?.isLoading ?? false"
                    :set-local-mode="(value) => form.isLocalMode.value = value"
                    :update-service="browseActions.updateService" :update-source="(value) => form.data.source = value"
                    :apply-service="browseActions.applyService" />

                <Masonry v-else :key="`${tab.id}-${browseState.masonryRenderKey}`" ref="masonry" v-model:items="items"
                    class="min-h-0 flex-1 !mt-0 !py-0 !border-0"
                     :mode="form.isLocalMode.value ? 'default' : 'backfill'"
                    :get-content="getPage" :page="browseState.startPageToken"
                    :page-size="Number(form.data.limit)"
                    :gap-x="layout.gutterX" :gap-y="layout.gutterY"
                    @preloaded="itemInteractions.preload.onBatchPreloaded" @failures="itemInteractions.preload.onBatchFailures"
                    @removed="itemInteractions.masonry.onRemoved" data-test="masonry-component">
                    <MasonryItem @preloaded="itemInteractions.preload.onItemPreloaded">
                        <template #loader>
                            <div class="flex h-full w-full items-center justify-center text-twilight-indigo-200">
                                <Loader2 :size="20" class="animate-spin" />
                            </div>
                        </template>

                        <template #error="{ error }">
                            <p class="text-center text-xs font-medium text-danger-100">Failed to load {{ error }}</p>
                        </template>

                        <template #overlay="{ item }">
                            <TabContentMasonryItemOverlay :item="item as FeedItem" :items-length="items.length"
                                :containers="containerInteractions"
                                :item-interactions="itemInteractions" :prompt-dialog="promptDialog" />
                        </template>
                    </MasonryItem>
                </Masonry>
            </div>
        </div>

        <!-- File Viewer -->
        <FileViewer ref="fileViewer" :container-ref="tabContentContainer" :masonry-container-ref="masonryContainer"
            :items="items" :masonry="masonry"
            @open="itemInteractions.viewer.onOpen" @close="itemInteractions.viewer.onClose"
            @reaction="itemInteractions.viewer.onReaction" />

        <!-- Status/Pagination Info at Bottom (only show when masonry is visible, not when showing form) -->
        <BrowseStatusBar :items="items" :masonry="masonry" :tab="tab" :is-loading="masonry?.isLoading"
            :visible="tab !== null && tab !== undefined && !browseState.shouldShowForm" :total="browseState.totalAvailable"
            @first-page="browseActions.goToFirstPage" />

        <TabContentPromptDialog :open="promptDialog.data.promptDialogOpen.value"
            :item-id="promptDialog.data.promptDialogItemId.value" :loading="promptDialog.data.promptDataLoading.value"
            :prompt="promptDialog.data.currentPromptData.value" :update-open="promptDialog.setOpen"
            :copy-prompt="promptDialog.copy" :test-prompt="promptDialog.openTestPage"
            :close-prompt="promptDialog.close" />

        <DownloadedReactionDialog :open="downloadedReactionPrompt.data.open.value"
            :update-open="downloadedReactionPrompt.setOpen" :choose-react="downloadedReactionPrompt.chooseReact"
            :choose-redownload="downloadedReactionPrompt.chooseRedownload"
            :close-dialog="downloadedReactionPrompt.close" />

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
