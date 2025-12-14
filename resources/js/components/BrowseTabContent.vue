<script setup lang="ts">
import { ref, computed, reactive, onMounted, onUnmounted, nextTick, watch } from 'vue';
import { Masonry, MasonryItem as VibeMasonryItem } from '@wyxos/vibe';
import { Loader2, AlertTriangle, Info, Copy, RefreshCcw, ChevronsLeft, X, ChevronDown, RotateCw, Play, ThumbsDown } from 'lucide-vue-next';
import FileViewer from './FileViewer.vue';
import BrowseStatusBar from './BrowseStatusBar.vue';
import FileReactions from './FileReactions.vue';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select';
import Pill from './ui/Pill.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogClose,
} from './ui/dialog';
import type { MasonryItem, BrowseTabData } from '@/composables/useBrowseTabs';
import { useBackfill } from '@/composables/useBackfill';
import { useBrowseService } from '@/composables/useBrowseService';
import { useReactionQueue } from '@/composables/useReactionQueue';
import { createReactionCallback } from '@/utils/reactions';
import { useContainerBadges } from '@/composables/useContainerBadges';
import { useContainerPillInteractions } from '@/composables/useContainerPillInteractions';
import { usePromptData } from '@/composables/usePromptData';
import { useMasonryInteractions } from '@/composables/useMasonryInteractions';
import { useItemPreview } from '@/composables/useItemPreview';
import { useMasonryRestore } from '@/composables/useMasonryRestore';
import { useResetDialog } from '@/composables/useResetDialog';
import { useRefreshDialog } from '@/composables/useRefreshDialog';
import { useMasonryReactionHandler } from '@/composables/useMasonryReactionHandler';
import { useTabInitialization } from '@/composables/useTabInitialization';
import { useAutoDislikeQueue } from '@/composables/useAutoDislikeQueue';
import BrowseFiltersSheet from './BrowseFiltersSheet.vue';
import ModerationRulesManager from './moderation/ModerationRulesManager.vue';

type GetPageResult = {
    items: MasonryItem[];
    nextPage: string | number | null;
};

interface Props {
    tab?: BrowseTabData;
    availableServices: Array<{ key: string; label: string }>;
    onReaction: (fileId: number, type: 'love' | 'like' | 'dislike' | 'funny') => void;
    onLoadingChange?: (isLoading: boolean) => void;
    onTabDataLoadingChange?: (isLoading: boolean) => void;
    updateActiveTab: (itemsData: MasonryItem[], fileIds: number[], queryParams: Record<string, string | number | null>) => void;
    loadTabItems: (tabId: number) => Promise<MasonryItem[]>;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:loading': [isLoading: boolean];
}>();

// Local state for this tab
const items = ref<MasonryItem[]>([]);
const masonry = ref<InstanceType<typeof Masonry> | null>(null);
const currentPage = ref<string | number | null>(1);
const nextCursor = ref<string | number | null>(null);
const loadAtPage = ref<string | number | null>(null);
const isTabRestored = ref(false);
const pendingRestoreNextCursor = ref<string | number | null>(null);
const selectedService = ref<string>('');
const hoveredItemIndex = ref<number | null>(null);
const isFilterSheetOpen = ref(false);

// Container refs for FileViewer
const masonryContainer = ref<HTMLElement | null>(null);
const tabContentContainer = ref<HTMLElement | null>(null);
const fileViewer = ref<InstanceType<typeof FileViewer> | null>(null);

// Reaction queue
const { queuedReactions, queueReaction, cancelReaction } = useReactionQueue();

// Item preview composable (needs to be initialized early)
const itemPreview = useItemPreview(items, computed(() => props.tab));

// Auto-dislike queue composable with expiration handler
const autoDislikeQueue = useAutoDislikeQueue(handleAutoDislikeExpire);

// Handle auto-dislike queue expiration - perform auto-dislike in batch
async function handleAutoDislikeExpire(expiredIds: number[]): Promise<void> {
    if (expiredIds.length === 0) {
        return;
    }

    // Batch perform auto-dislike (backend handles de-association from tabs)
    try {
        const response = await window.axios.post<{
            message: string;
            auto_disliked_count: number;
            file_ids: number[];
        }>('/api/files/auto-dislike/batch', {
            file_ids: expiredIds,
        });

        const autoDislikedIds = response.data.file_ids;

        // Remove from masonry
        const itemsToRemove: MasonryItem[] = [];
        for (const fileId of autoDislikedIds) {
            const item = items.value.find((i) => i.id === fileId);
            if (item) {
                itemsToRemove.push(item);
            }
        }

        // Remove from masonry using removeMany for batch removal
        // Masonry component manages items array through v-model, so no manual array manipulation needed
        if (masonry.value?.removeMany) {
            await masonry.value.removeMany(itemsToRemove);
        } else if (masonry.value?.remove) {
            // Fallback to individual removal if removeMany is not available
            for (const item of itemsToRemove) {
                masonry.value.remove(item);
            }
        }

        // Update tab (remove from fileIds and itemsData)
        // Note: Backend already de-associated from tabs, we just update local state
        if (props.tab && autoDislikedIds.length > 0) {
            const updatedFileIds = props.tab.fileIds.filter((id) => !autoDislikedIds.includes(id));
            const updatedItemsData = props.tab.itemsData.filter((item) => !autoDislikedIds.includes(item.id));
            props.updateActiveTab(updatedItemsData, updatedFileIds, props.tab.queryParams);
        }
    } catch (error) {
        console.error('Failed to batch perform auto-dislike:', error);
    }
}

// Masonry restore composable
const { restoreToMasonry, restoreManyToMasonry } = useMasonryRestore(items, masonry);

// Reset dialog composable
const resetDialog = useResetDialog(
    items,
    masonry,
    currentPage,
    nextCursor,
    loadAtPage,
    computed(() => props.tab),
    props.updateActiveTab
);

// Backfill state and handlers
const {
    backfill,
    onBackfillStart,
    onBackfillTick,
    onBackfillStop,
    onBackfillRetryStart,
    onBackfillRetryTick,
    onBackfillRetryStop,
} = useBackfill();

// Computed property to display page value
const displayPage = computed(() => currentPage.value ?? 1);

// Get current tab's service
const currentTabService = computed(() => {
    return props.tab?.queryParams?.service as string | null;
});

// Handle filter apply from BrowseFiltersSheet
async function handleApplyFilters(filters: {
    service: string;
    nsfw: boolean;
    type: string;
    limit: string;
    sort: string;
}): Promise<void> {
    if (!props.tab) {
        return;
    }

    if (!filters.service) {
        // Service is required
        return;
    }

    const updatedQueryParams: Record<string, string | number | null> = {
        ...props.tab.queryParams,
        service: filters.service || null,
        nsfw: filters.nsfw ? 1 : null,
        type: filters.type !== 'all' ? filters.type : null,
        limit: filters.limit ? Number(filters.limit) : null,
        sort: filters.sort !== 'Newest' ? filters.sort : null,
        page: 1, // Reset to page 1 when applying filters
        next: null,
    };

    // Clear existing items and reset pagination
    items.value = [];
    currentPage.value = 1;
    nextCursor.value = null;
    loadAtPage.value = 1;
    selectedService.value = filters.service;

    // Update tab with all filter params
    props.updateActiveTab([], [], updatedQueryParams);

    // Use the same approach as reset button: reset() and loadPage(1)
    if (masonry.value) {
        if (masonry.value.isLoading) {
            masonry.value.cancelLoad();
        }

        // Use Masonry's built-in reset() method which properly handles animations
        if (typeof masonry.value.reset === 'function') {
            masonry.value.reset();

            // After reset, load page 1 to populate content with new filters
            await nextTick();

            if (typeof masonry.value.loadPage === 'function') {
                await masonry.value.loadPage(1);
            }
        } else {
            // Fallback: destroy and reinitialize
            masonry.value.destroy();
            await nextTick();
            await initializeTab(props.tab);
        }
    } else {
        // If masonry doesn't exist yet, just initialize the tab
        await initializeTab(props.tab);
    }
}

// Handle moderation rules changes (e.g., reload to apply new rules)
function handleModerationRulesChanged(): void {
    // For now, just log the change. Future: could reload items with new moderation filters
    console.log('Moderation rules changed');
}

// Check if current tab has a service selected
const hasServiceSelected = computed(() => {
    const service = currentTabService.value;
    return typeof service === 'string' && service.length > 0;
});

// Computed property for apply button disabled state
// Button should only be disabled when:
// - No service is selected
// - Service is currently being applied
// - Selected service is already the current service
// It should NOT be disabled when masonry is loading
const isApplyButtonDisabled = computed(() => {
    return !selectedService.value || isApplyingService.value || selectedService.value === currentTabService.value;
});

// Browse service composable
const {
    isApplyingService,
    getNextPage: getNextPageFromComposable,
    applyService: applyServiceFromComposable,
} = useBrowseService({
    hasServiceSelected,
    isTabRestored,
    items,
    nextCursor,
    currentPage,
    pendingRestoreNextCursor,
    currentTabService,
    activeTabId: computed(() => props.tab?.id ?? null),
    getActiveTab: () => props.tab,
    updateActiveTab: props.updateActiveTab,
});

const layout = {
    gutterX: 12,
    gutterY: 12,
    sizes: { base: 1, sm: 2, md: 3, lg: 4, '2xl': 10 },
};

async function getNextPage(page: number | string): Promise<GetPageResult> {
    return await getNextPageFromComposable(page);
}

function onMasonryClick(e: MouseEvent): void {
    // Check for ALT + mouse button combinations for quick reactions
    if (e.altKey) {
        handleAltClickOnMasonry(e);
        return;
    }

    // Normal click behavior - open overlay (only for left click)
    if (e.button === 0 || (e.type === 'click' && !e.button)) {
        fileViewer.value?.openFromClick(e);
    }
}

function onMasonryMouseDown(e: MouseEvent): void {
    // Handle ALT + Middle Click (mousedown event needed for middle button)
    if (e.altKey && e.button === 1) {
        handleAltClickOnMasonry(e);
    }
    // Prevent browser scroll for middle click (without ALT) - actual opening happens on auxclick
    if (!e.altKey && e.button === 1) {
        e.preventDefault();
        e.stopPropagation();
    }
}

// Wrapper for handleAltClickOnMasonry that finds the item from the DOM
function handleAltClickOnMasonry(e: MouseEvent): void {
    const container = masonryContainer.value;
    if (!container) return;

    const target = e.target as HTMLElement | null;
    if (!target) return;

    // Find the nearest masonry item element
    const itemEl = target.closest('.masonry-item') as HTMLElement | null;
    if (!itemEl || !container.contains(itemEl)) return;

    // Find the masonry item data using the key
    const itemKeyAttr = itemEl.getAttribute('data-key');
    if (itemKeyAttr) {
        // Match by key (provided by backend)
        const item = items.value.find(i => i.key === itemKeyAttr);
        if (item) {
            masonryInteractions.handleAltClickReaction(e, item.id);
            return;
        }
    }

    // Fallback: try to find by image src
    const imgEl = itemEl.querySelector('img') as HTMLImageElement | null;
    if (imgEl) {
        const src = imgEl.currentSrc || imgEl.getAttribute('src') || '';
        const item = items.value.find(i => {
            const itemSrc = (i.src || i.thumbnail || '').split('?')[0].split('#')[0];
            const baseSrc = src.split('?')[0].split('#')[0];
            return baseSrc === itemSrc || baseSrc.includes(itemSrc) || itemSrc.includes(baseSrc);
        });
        if (item) {
            masonryInteractions.handleAltClickReaction(e, item.id);
        }
    }
}




// Initialize composables
// Container badges composable
const containerBadges = useContainerBadges(items);

// Container pill interactions composable
const containerPillInteractions = useContainerPillInteractions(
    items,
    masonry,
    props.tab?.id,
    (fileId: number, type: 'love' | 'like' | 'dislike' | 'funny') => {
        // Remove from auto-dislike queue if user reacts (any reaction cancels auto-dislike)
        if (autoDislikeQueue.isQueued(fileId)) {
            autoDislikeQueue.removeFromQueue(fileId);
            const itemIndex = items.value.findIndex((i) => i.id === fileId);
            if (itemIndex !== -1) {
                items.value[itemIndex].will_auto_dislike = false;
            }
        }
        props.onReaction(fileId, type);
    },
    restoreManyToMasonry
);

// Prompt data composable
const promptData = usePromptData(items);

// Masonry reaction handler composable (needs restoreToMasonry)
const { handleMasonryReaction } = useMasonryReactionHandler(
    items,
    masonry,
    computed(() => props.tab),
    (fileId: number, type: 'love' | 'like' | 'dislike' | 'funny') => {
        // Remove from auto-dislike queue if user reacts (any reaction cancels auto-dislike)
        if (autoDislikeQueue.isQueued(fileId)) {
            autoDislikeQueue.removeFromQueue(fileId);
            const itemIndex = items.value.findIndex((i) => i.id === fileId);
            if (itemIndex !== -1) {
                items.value[itemIndex].will_auto_dislike = false;
            }
        }
        props.onReaction(fileId, type);
    },
    restoreToMasonry
);

// Masonry interactions composable (needs handleMasonryReaction)
const masonryInteractions = useMasonryInteractions(
    items,
    masonry,
    handleMasonryReaction
);

// Tab initialization composable
const { initializeTab } = useTabInitialization({
    fileViewer,
    masonry,
    items,
    currentPage,
    nextCursor,
    loadAtPage,
    isTabRestored,
    pendingRestoreNextCursor,
    selectedService,
    clearPreviewedItems: itemPreview.clearPreviewedItems,
    onTabDataLoadingChange: props.onTabDataLoadingChange,
    loadTabItems: props.loadTabItems,
});

// Refresh dialog composable
const refreshDialog = useRefreshDialog(
    items,
    masonry,
    currentPage,
    nextCursor,
    loadAtPage,
    computed(() => props.tab),
    props.updateActiveTab,
    initializeTab
);


// Apply selected service to current tab
async function applyService(): Promise<void> {
    if (!props.tab) {
        return;
    }
    await applyServiceFromComposable(
        selectedService,
        ref(props.tab.id),
        items,
        currentPage,
        nextCursor,
        loadAtPage,
        masonry as unknown as import('vue').Ref<{ isLoading: boolean; cancelLoad: () => void; destroy: () => void } | null>,
        () => props.tab,
        props.updateActiveTab,
        nextTick
    );
}


async function handleCarouselLoadMore(): Promise<void> {
    if (nextCursor.value !== null && masonry.value && !masonry.value.isLoading) {
        if (typeof masonry.value.loadNext === 'function') {
            await masonry.value.loadNext();
        }
    }
}

// Cancel masonry loading
function cancelMasonryLoad(): void {
    if (masonry.value?.isLoading && typeof masonry.value.cancelLoad === 'function') {
        masonry.value.cancelLoad();
    }
}

// Load next page manually
async function loadNextPage(): Promise<void> {
    if (nextCursor.value !== null && masonry.value && !masonry.value.isLoading) {
        if (typeof masonry.value.loadNext === 'function') {
            await masonry.value.loadNext();
        }
    }
}

// Refresh/reload the tab
async function refreshTab(): Promise<void> {
    if (!props.tab) {
        return;
    }

    // Cancel any ongoing load
    if (masonry.value?.isLoading) {
        cancelMasonryLoad();
    }

    // Reset to page 1 and reload
    currentPage.value = 1;
    nextCursor.value = null;
    loadAtPage.value = 1;
    items.value = [];

    // Update tab query params
    const updatedQueryParams: Record<string, string | number | null> = {
        ...props.tab.queryParams,
        page: 1,
        next: null,
    };

    props.updateActiveTab([], [], updatedQueryParams);

    // Reset masonry and reload
    if (masonry.value) {
        if (typeof masonry.value.reset === 'function') {
            masonry.value.reset();
            await nextTick();
            if (typeof masonry.value.loadPage === 'function') {
                await masonry.value.loadPage(1);
            }
        } else {
            masonry.value.destroy();
            await nextTick();
            await initializeTab(props.tab);
        }
    } else {
        await initializeTab(props.tab);
    }
}

// Watch masonry loading state and emit to parent
watch(
    () => masonry.value?.isLoading ?? false,
    (isLoading) => {
        emit('update:loading', isLoading);
        if (props.onLoadingChange) {
            props.onLoadingChange(isLoading);
        }
    }
);

// Initialize tab state on mount - this will run every time the component is created (tab switch)
onMounted(async () => {
    if (props.tab) {
        await initializeTab(props.tab);
    }
});

// Watch for tab ID changes to ensure re-initialization when switching to a different tab
// This is a safety measure in case the component doesn't get destroyed/recreated
watch(
    () => props.tab?.id,
    async (newId, oldId) => {
        // Only re-initialize if tab ID actually changed and tab exists
        if (newId && newId !== oldId && props.tab) {
            await initializeTab(props.tab);
        }
    }
);

// Track loaded item IDs to handle timing between preload:success and watch
const loadedItemIds = ref<Set<number>>(new Set());

// Watch for will_auto_dislike flag and add to queue
watch(
    () => items.value.map((item) => ({ id: item.id, will_auto_dislike: item.will_auto_dislike })),
    (newItems, oldItems) => {
        const oldMap = new Map(oldItems?.map((i) => [i.id, i.will_auto_dislike]) ?? []);
        newItems.forEach((item) => {
            // Add to queue if will_auto_dislike is true and wasn't before
            if (item.will_auto_dislike && !oldMap.get(item.id)) {
                // Start active if preview already loaded, otherwise inactive (frozen until loads)
                const isAlreadyLoaded = loadedItemIds.value.has(item.id);
                autoDislikeQueue.addToQueue(item.id, isAlreadyLoaded);
            }
            // Remove from queue if will_auto_dislike is false and was true before
            else if (!item.will_auto_dislike && oldMap.get(item.id)) {
                autoDislikeQueue.removeFromQueue(item.id);
            }
        });
    },
    { deep: true }
);

// Cleanup on unmount
onUnmounted(() => {
    // Clear loading state when component is destroyed
    emit('update:loading', false);
    if (props.onLoadingChange) {
        props.onLoadingChange(false);
    }

    // Destroy masonry if it exists
    if (masonry.value) {
        if (masonry.value.isLoading) {
            masonry.value.cancelLoad();
        }
        masonry.value.destroy();
    }
});
</script>

<template>
    <div v-if="tab" ref="tabContentContainer" class="flex-1 min-h-0 transition-all duration-300 flex flex-col relative">
        <!-- Service Selection Header -->
        <div class="px-4 py-3 border-b border-twilight-indigo-500/50 bg-prussian-blue-700/50"
            data-test="service-selection-header">
            <div class="flex items-center gap-3">
                <div class="flex-1">
                    <Select v-model="selectedService" :disabled="isApplyingService">
                        <SelectTrigger class="w-[200px]" data-test="service-select-trigger">
                            <SelectValue
                                :placeholder="hasServiceSelected ? (availableServices.find(s => s.key === currentTabService)?.label || currentTabService || undefined) : 'Select a service...'" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="service in availableServices" :key="service.key" :value="service.key"
                                data-test="service-select-item">
                                {{ service.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <BrowseFiltersSheet v-model:open="isFilterSheetOpen" :available-services="availableServices" :tab="tab"
                    :masonry="masonry" :is-masonry-loading="masonry?.isLoading ?? false" @apply="handleApplyFilters" />

                <!-- Moderation Rules -->
                <ModerationRulesManager :disabled="masonry?.isLoading ?? false"
                    @rules-changed="handleModerationRulesChanged" />

                <!-- Cancel Loading Button -->
                <Button @click="cancelMasonryLoad" size="sm" variant="ghost" class="h-10 w-10" color="danger"
                    data-test="cancel-loading-button" title="Cancel loading" :disabled="!masonry?.isLoading">
                    <X :size="14" />
                </Button>

                <!-- Load Next Page Button -->
                <Button @click="loadNextPage" size="sm" variant="ghost" class="h-10 w-10"
                    data-test="load-next-page-button" title="Load next page"
                    :disabled="masonry?.isLoading || nextCursor === null || !hasServiceSelected">
                    <ChevronDown :size="14" />
                </Button>

                <!-- Refresh Tab Button -->
                <Button @click="refreshDialog.openRefreshDialog" size="sm" variant="ghost" class="h-10 w-10"
                    color="danger" data-test="refresh-tab-button" title="Refresh tab"
                    :disabled="masonry?.isLoading ?? false">
                    <RefreshCcw :size="14" />
                </Button>

                <Button :disabled="(!hasServiceSelected && !resetDialog.isOnFirstPage)"
                    @click="resetDialog.openResetDialog" size="sm" variant="ghost" class="h-10 w-10" color="danger"
                    data-test="reset-to-first-page-button">
                    <ChevronsLeft :size="14"></ChevronsLeft>
                </Button>
                <Button @click="applyService" :disabled="isApplyButtonDisabled" size="sm" class="h-10 w-10"
                    data-test="apply-service-button">
                    <Loader2 v-if="isApplyingService" :size="14" class="mr-2 animate-spin" />
                    <Play :size="14" v-else />
                </Button>
            </div>
        </div>

        <!-- Masonry Content -->
        <div class="flex-1 min-h-0">
            <div v-if="tab && hasServiceSelected" class="relative h-full masonry-container" ref="masonryContainer"
                @click="onMasonryClick" @contextmenu.prevent="onMasonryClick" @mousedown="onMasonryMouseDown">
                <Masonry :key="tab?.id" ref="masonry" v-model:items="items" :get-next-page="getNextPage"
                    :load-at-page="loadAtPage" :layout="layout" layout-mode="auto" :mobile-breakpoint="768"
                    :skip-initial-load="items.length > 0" :backfill-enabled="true" :backfill-delay-ms="2000"
                    :backfill-max-calls="Infinity" @backfill:start="onBackfillStart" @backfill:tick="onBackfillTick"
                    @backfill:stop="onBackfillStop" @backfill:retry-start="onBackfillRetryStart"
                    @backfill:retry-tick="onBackfillRetryTick" @backfill:retry-stop="onBackfillRetryStop"
                    data-test="masonry-component">
                    <template #default="{ item, index, remove }">
                        <VibeMasonryItem :item="item" :remove="remove"
                            @mouseenter="() => { hoveredItemIndex = index; if (autoDislikeQueue.hasQueuedItems.value) { autoDislikeQueue.freeze(); } }"
                            @mouseleave="() => { hoveredItemIndex = null; containerBadges.hoveredContainerId.value = null; if (autoDislikeQueue.hasQueuedItems.value) { autoDislikeQueue.unfreeze(); } }"
                            @preload:success="(payload: { item: any; type: 'image' | 'video'; src: string }) => {
                                // payload.item is the item passed to MasonryItem, which should have the id
                                const itemId = payload.item?.id ?? item?.id;
                                if (itemId) {
                                    itemPreview.handleItemPreload(itemId);
                                    // Track that this item has loaded (refs are auto-unwrapped in templates)
                                    loadedItemIds.add(itemId);
                                    // Activate auto-dislike countdown when preview loads
                                    if (autoDislikeQueue.isQueued(itemId)) {
                                        autoDislikeQueue.activateItem(itemId);
                                    }
                                }
                            }">
                            <template
                                #default="{ imageLoaded, imageError, videoLoaded, videoError, isLoading, showMedia, imageSrc, videoSrc }">
                                <div class="relative w-full h-full overflow-hidden rounded-lg group masonry-item transition-all duration-300"
                                    :data-key="item.key" :class="containerBadges.getMasonryItemClasses.value(item)"
                                    @mousedown="(e: MouseEvent) => masonryInteractions.handleMasonryItemMouseDown(e, item)"
                                    @auxclick="(e: MouseEvent) => masonryInteractions.handleMasonryItemAuxClick(e, item)">
                                    <!-- Auto-disliked indicator overlay with smooth animation -->
                                    <Transition name="ring-fade">
                                        <div v-if="items.find(i => i.id === item.id)?.auto_disliked"
                                            class="absolute inset-0 border-2 border-red-500 pointer-events-none z-10 rounded-lg ring-fade-enter-active">
                                        </div>
                                    </Transition>
                                    <!-- Per-item auto-dislike countdown pill (bottom center): icon | progress with timer overlay -->
                                    <Transition name="countdown-fade">
                                        <div v-if="autoDislikeQueue.isQueued(item.id)"
                                            class="absolute inset-x-0 bottom-2 flex justify-center z-20 pointer-events-none">
                                            <span
                                                class="inline-flex items-stretch rounded overflow-hidden border border-danger-500 shadow-lg">
                                                <!-- Dislike icon -->
                                                <span
                                                    class="px-2 py-1 text-xs font-medium bg-danger-600 text-white flex items-center justify-center">
                                                    <ThumbsDown :size="12" />
                                                </span>
                                                <!-- Progress bar with timer overlay (shows paused state if not active) -->
                                                <span
                                                    class="bg-prussian-blue-700 border-l border-twilight-indigo-500 flex items-center justify-center relative overflow-hidden min-w-12">
                                                    <!-- Progress fill (only shows when active) -->
                                                    <div v-if="autoDislikeQueue.isActive(item.id)"
                                                        class="absolute left-0 top-0 bottom-0 bg-danger-500 transition-all duration-100"
                                                        :style="{ width: `${autoDislikeQueue.getProgress(item.id) * 100}%` }">
                                                    </div>
                                                    <!-- Timer text overlay or paused indicator -->
                                                    <span
                                                        class="relative z-10 px-2 py-1 text-xs font-semibold text-white tabular-nums drop-shadow-sm">
                                                        <template v-if="autoDislikeQueue.isActive(item.id)">
                                                            {{ Math.floor(autoDislikeQueue.getRemaining(item.id) / 1000)
                                                            }}:{{
                                                                String(Math.floor((autoDislikeQueue.getRemaining(item.id) %
                                                                    1000) / 10)).padStart(2, '0') }}
                                                        </template>
                                                        <template v-else>
                                                            <i class="fas fa-pause text-[10px]"></i>
                                                        </template>
                                                    </span>
                                                </span>
                                            </span>
                                        </div>
                                    </Transition>
                                    <!-- Placeholder background - icon by default (before preloading starts) -->
                                    <div v-if="!imageLoaded && !imageError" :class="[
                                        'absolute inset-0 bg-slate-100 flex items-center justify-center transition-opacity duration-500',
                                        showMedia ? 'opacity-0 pointer-events-none' : 'opacity-100'
                                    ]">
                                        <!-- Media type indicator badge - shown BEFORE preloading starts -->
                                        <div
                                            class="w-12 h-12 rounded-full bg-white/80 backdrop-blur-sm flex items-center justify-center shadow-sm">
                                            <i class="fas fa-image text-xl text-slate-400"></i>
                                        </div>
                                    </div>

                                    <!-- Spinner (only shown when loading/preloading) -->
                                    <div v-if="isLoading"
                                        class="absolute bottom-2 left-1/2 transform -translate-x-1/2 flex items-center justify-center z-10">
                                        <div class="bg-white/90 backdrop-blur-sm rounded-full px-3 py-1.5 shadow-sm">
                                            <Loader2 class="w-4 h-4 text-smart-blue-500 animate-spin" />
                                        </div>
                                    </div>

                                    <!-- Error state -->
                                    <div v-if="imageError"
                                        class="absolute inset-0 flex flex-col items-center justify-center bg-slate-50 text-slate-400 text-sm p-4 text-center">
                                        <i class="fas fa-image text-2xl mb-2 opacity-50"></i>
                                        <span>Failed to load image</span>
                                    </div>

                                    <!-- Image (only render when imageSrc is available from Vibe's preloading) -->
                                    <img v-if="imageSrc && !imageError" :src="imageSrc" :alt="`Item ${item.id}`" :class="[
                                        'w-full h-full object-cover transition-opacity duration-700 ease-in-out',
                                        imageLoaded && showMedia ? 'opacity-100' : 'opacity-0'
                                    ]" />

                                    <!-- Container badges (shows on hover with type and count) -->
                                    <div v-if="hoveredItemIndex === index && imageLoaded && containerBadges.getContainersForItem(item).length > 0"
                                        class="absolute top-2 left-2 z-50 pointer-events-auto flex flex-col gap-1">
                                        <div v-for="container in containerBadges.getContainersForItem(item)"
                                            :key="container.id" class="cursor-pointer"
                                            @mouseenter="() => { containerBadges.hoveredContainerId.value = container.id; }"
                                            @mouseleave="() => { containerBadges.hoveredContainerId.value = null; }"
                                            @click.stop="(e: MouseEvent) => containerPillInteractions.handlePillClick(container.id, e)"
                                            @dblclick.stop="(e: MouseEvent) => containerPillInteractions.handlePillClick(container.id, e, true)"
                                            @contextmenu.stop="(e: MouseEvent) => containerPillInteractions.handlePillClick(container.id, e)"
                                            @auxclick.stop="(e: MouseEvent) => containerPillInteractions.handlePillAuxClick(container.id, e)"
                                            @mousedown.stop="(e: MouseEvent) => { if (e.button === 1) e.preventDefault(); }">
                                            <Pill :label="container.type"
                                                :value="containerBadges.getItemCountForContainerId(container.id)"
                                                :variant="containerBadges.getVariantForContainerType(container.type)" />
                                        </div>
                                    </div>

                                    <!-- Info badge (shows on hover, opens dialog on click) -->
                                    <div v-if="hoveredItemIndex === index && imageLoaded"
                                        class="absolute top-2 right-2 z-50 pointer-events-auto">
                                        <Button variant="ghost" size="sm"
                                            class="h-7 w-7 p-0 bg-black/50 hover:bg-black/70 text-white"
                                            @click.stop="() => promptData.openPromptDialog(item)"
                                            aria-label="Show prompt">
                                            <Info :size="14" />
                                        </Button>
                                    </div>

                                    <!-- Hover reactions overlay -->
                                    <div v-show="hoveredItemIndex === index && imageLoaded"
                                        class="absolute bottom-0 left-0 right-0 flex justify-center pb-2 z-50 pointer-events-auto">
                                        <FileReactions :file-id="item.id"
                                            :previewed-count="(item.previewed_count as number) ?? 0"
                                            :viewed-count="(item.seen_count as number) ?? 0" :current-index="index"
                                            :total-items="items.length" variant="small"
                                            :remove-item="() => remove(item)"
                                            @reaction="(type) => handleMasonryReaction(item.id, type, remove)" />
                                    </div>
                                </div>
                            </template>
                        </VibeMasonryItem>
                    </template>
                </Masonry>
            </div>
            <div v-else-if="tab && !hasServiceSelected" class="flex items-center justify-center h-full"
                data-test="no-service-message">
                <p class="text-twilight-indigo-300 text-lg">Select a service to start browsing</p>
            </div>
            <div v-else class="flex items-center justify-center h-full" data-test="no-tabs-message">
                <p class="text-twilight-indigo-300 text-lg">Create a tab to start browsing</p>
            </div>
        </div>

        <!-- File Viewer -->
        <FileViewer ref="fileViewer" :container-ref="tabContentContainer" :masonry-container-ref="masonryContainer"
            :items="items" :has-more="nextCursor !== null" :is-loading="masonry?.isLoading ?? false"
            :on-load-more="handleCarouselLoadMore" :on-reaction="props.onReaction" :remove-from-masonry="(item) => {
                // Use masonry's remove method directly
                if (masonry.value?.remove) {
                    const masonryItem = items.find((i) => i.id === item.id);
                    if (masonryItem) {
                        masonry.value.remove(masonryItem);
                    }
                }
            }" :restore-to-masonry="restoreToMasonry" :tab-id="props.tab?.id" :masonry-instance="masonry"
            @close="() => { }" />

        <!-- Status/Pagination Info at Bottom -->
        <BrowseStatusBar :items="items" :display-page="displayPage" :next-cursor="nextCursor"
            :is-loading="masonry?.isLoading ?? false" :backfill="backfill"
            :queued-reactions-count="queuedReactions.length"
            :visible="tab !== null && tab !== undefined && hasServiceSelected" />

        <!-- Reset to First Page Warning Dialog -->
        <Dialog v-model="resetDialog.resetDialogOpen.value">
            <DialogContent class="sm:max-w-[425px] bg-prussian-blue-600 border-danger-500/30">
                <DialogHeader>
                    <DialogTitle class="text-danger-400">Reset to First Page</DialogTitle>
                    <DialogDescription class="text-base mt-2 text-twilight-indigo-100">
                        Are you sure you want to go back to the first page? This will clear all currently loaded items
                        and start
                        from the beginning.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button variant="outline" @click="resetDialog.closeResetDialog">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button @click="resetDialog.resetToFirstPage" variant="destructive">
                        Reset to First Page
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Prompt Dialog -->
        <Dialog v-model="promptData.promptDialogOpen.value"
            @update:model-value="(val) => { if (!val) promptData.closePromptDialog(); }">
            <DialogContent class="sm:max-w-[600px] bg-prussian-blue-600">
                <DialogHeader>
                    <DialogTitle class="text-twilight-indigo-100">Prompt</DialogTitle>
                </DialogHeader>
                <div class="space-y-4 mt-4">
                    <div v-if="promptData.promptDialogItemId.value !== null && promptData.promptDataLoading.value.get(promptData.promptDialogItemId.value)"
                        class="flex items-center gap-2 text-sm text-twilight-indigo-100">
                        <Loader2 :size="16" class="animate-spin" />
                        <span>Loading prompt...</span>
                    </div>
                    <div v-else-if="promptData.currentPromptData.value" class="space-y-2">
                        <div
                            class="flex-1 whitespace-pre-wrap wrap-break-word text-sm text-twilight-indigo-100 max-h-[60vh] overflow-y-auto">
                            {{ promptData.currentPromptData.value }}
                        </div>
                    </div>
                    <div v-else class="text-sm text-twilight-indigo-300">
                        No prompt data available
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" size="sm"
                        @click="() => { if (promptData.currentPromptData.value) promptData.copyPromptToClipboard(promptData.currentPromptData.value); }"
                        aria-label="Copy prompt">
                        <Copy :size="16" class="mr-2" />
                        Copy
                    </Button>
                    <DialogClose as-child>
                        <Button variant="outline" size="sm" @click="promptData.closePromptDialog()">
                            Close
                        </Button>
                    </DialogClose>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Refresh Tab Warning Dialog -->
        <Dialog v-model="refreshDialog.refreshDialogOpen.value">
            <DialogContent class="sm:max-w-[425px] bg-prussian-blue-600 border-danger-500/30">
                <DialogHeader>
                    <DialogTitle class="text-danger-400">Refresh Tab</DialogTitle>
                    <DialogDescription class="text-base mt-2 text-twilight-indigo-100">
                        Are you sure you want to refresh this tab? This will clear all currently loaded items and reload
                        from
                        the beginning.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button variant="outline" @click="refreshDialog.closeRefreshDialog">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button @click="refreshDialog.confirmRefreshTab" variant="destructive">
                        Refresh Tab
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
    <div v-else class="flex items-center justify-center h-full" data-test="no-tab-message">
        <p class="text-twilight-indigo-300 text-lg">No tab selected</p>
    </div>
</template>

<style scoped>
.ring-fade-enter-active {
    animation: ringAppear 0.6s ease-out;
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

.countdown-fade-enter-active,
.countdown-fade-leave-active {
    transition: opacity 0.3s ease;
}

.countdown-fade-enter-from,
.countdown-fade-leave-to {
    opacity: 0;
}
</style>
