<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue';
import { Masonry } from '@wyxos/vibe';
import { Loader2 } from 'lucide-vue-next';
import FileViewer from './FileViewer.vue';
import BrowseStatusBar from './BrowseStatusBar.vue';
import FileReactions from './FileReactions.vue';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select';
import type { MasonryItem, BrowseTabData } from '../composables/useBrowseTabs';
import { useBackfill } from '../composables/useBackfill';
import { useBrowseService } from '../composables/useBrowseService';
import { useReactionQueue } from '../composables/useReactionQueue';
import { createReactionCallback } from '../utils/reactions';

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
// Store remove function from masonry slot to use in FileViewer
const masonryRemoveFn = ref<((item: MasonryItem) => void) | null>(null);

// Container refs for FileViewer
const masonryContainer = ref<HTMLElement | null>(null);
const tabContentContainer = ref<HTMLElement | null>(null);
const fileViewer = ref<InstanceType<typeof FileViewer> | null>(null);

// Reaction queue
const { queuedReactions, queueReaction, cancelReaction } = useReactionQueue();

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

// Check if current tab has a service selected
const hasServiceSelected = computed(() => {
    const service = currentTabService.value;
    return typeof service === 'string' && service.length > 0;
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
}

function handleAltClickOnMasonry(e: MouseEvent): void {
    const container = masonryContainer.value;
    if (!container) return;

    const target = e.target as HTMLElement | null;
    if (!target) return;

    // Find the nearest masonry item element
    const itemEl = target.closest('.masonry-item') as HTMLElement | null;
    if (!itemEl || !container.contains(itemEl)) return;

    // Find the masonry item data
    const itemId = itemEl.getAttribute('data-item-id');
    if (!itemId) {
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
                handleAltClickReaction(e, item.id);
            }
        }
        return;
    }

    const fileId = Number(itemId);
    if (!isNaN(fileId)) {
        handleAltClickReaction(e, fileId);
    }
}

function handleAltClickReaction(e: MouseEvent, fileId: number): void {
    // Prevent default behavior and stop propagation
    e.preventDefault();
    e.stopPropagation();

    let reactionType: 'love' | 'like' | 'dislike' | 'funny' | null = null;

    // ALT + Left Click = Like
    if (e.button === 0 || (e.type === 'click' && e.button === 0)) {
        reactionType = 'like';
    }
    // ALT + Right Click = Dislike
    else if (e.button === 2 || e.type === 'contextmenu') {
        reactionType = 'dislike';
    }
    // ALT + Middle Click = Favorite (Love)
    else if (e.button === 1) {
        reactionType = 'love';
    }

    if (reactionType) {
        const item = items.value.find((i) => i.id === fileId);
        if (item) {
            // Find the remove function from masonry slot
            const removeFn = masonryRemoveFn.value || ((itemToRemove: MasonryItem) => {
                if (masonry.value) {
                    const masonryItem = items.value.find((i) => i.id === itemToRemove.id);
                    if (masonryItem) {
                        masonry.value.remove(masonryItem);
                    }
                }
            });
            handleMasonryReaction(fileId, reactionType, removeFn);
        }
    }
}

// Function to capture remove function from masonry slot
function captureRemoveFn(remove: (item: MasonryItem) => void): void {
    if (!masonryRemoveFn.value) {
        masonryRemoveFn.value = remove;
    }
}

// Handle reaction with queue (wrapper for masonry removeItem callback)
async function handleMasonryReaction(
    fileId: number,
    type: 'love' | 'like' | 'dislike' | 'funny',
    removeItem: (item: MasonryItem) => void
): Promise<void> {
    const item = items.value.find((i) => i.id === fileId);
    const itemIndex = item ? items.value.findIndex((i) => i.id === fileId) : -1;
    const tabId = props.tab?.id;

    // Create restore callback to add item back to masonry at original index
    const restoreItem = item && tabId !== undefined && itemIndex !== -1 ? async (restoreTabId: number, isTabActive: (tabId: number) => boolean) => {
        // Only restore if the tab is active
        const tabActive = isTabActive(restoreTabId);
        if (!tabActive) {
            return;
        }

        // Check if item is already in the array (avoid duplicates)
        const existingIndex = items.value.findIndex((i) => i.id === item.id);
        if (existingIndex === -1) {
            // Try to use masonry's restore method if available, otherwise insert at original index
            if (masonry.value && typeof (masonry.value as any).restore === 'function') {
                (masonry.value as any).restore(item, itemIndex);
            } else if (masonry.value && typeof (masonry.value as any).add === 'function') {
                (masonry.value as any).add(item, itemIndex);
            } else if (masonry.value && typeof (masonry.value as any).insert === 'function') {
                (masonry.value as any).insert(item, itemIndex);
            } else {
                // Fallback: manually insert at original index and refresh layout
                const clampedIndex = Math.min(itemIndex, items.value.length);
                items.value.splice(clampedIndex, 0, item);
                // Trigger layout recalculation and animation
                if (masonry.value && typeof masonry.value.refreshLayout === 'function') {
                    // Use nextTick to ensure Vue has processed the array change
                    await nextTick();
                    masonry.value.refreshLayout(items.value);
                }
            }
        }
    } : undefined;

    if (item && removeItem) {
        removeItem(item);
    }

    // Queue the AJAX request with restore callback, tab ID, index, and item
    const previewUrl = item?.src;
    queueReaction(fileId, type, createReactionCallback(), previewUrl, restoreItem, tabId, itemIndex, item);

    // Emit to parent
    props.onReaction(fileId, type);
}

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
        await initializeTab();
    }
});

// Watch for tab ID changes to ensure re-initialization when switching to a different tab
// This is a safety measure in case the component doesn't get destroyed/recreated
watch(
    () => props.tab?.id,
    async (newId, oldId) => {
        // Only re-initialize if tab ID actually changed and tab exists
        if (newId && newId !== oldId && props.tab) {
            await initializeTab();
        }
    }
);

async function initializeTab(): Promise<void> {
    const tab = props.tab;
    if (!tab) return;

    // Close fileviewer
    if (fileViewer.value) {
        fileViewer.value.close();
    }

    // Destroy existing masonry instance
    if (masonry.value) {
        if (masonry.value.isLoading) {
            masonry.value.cancelLoad();
        }
        masonry.value.destroy();
    }

    const tabHasRestorableItems = (tab.fileIds?.length ?? 0) > 0 || (tab.itemsData?.length ?? 0) > 0;
    isTabRestored.value = tabHasRestorableItems;

    // Restore selected service for UI
    const serviceFromQuery = tab.queryParams?.service as string | null;
    selectedService.value = serviceFromQuery || '';

    // Restore both page and next from queryParams
    const pageFromQuery = tab.queryParams?.page;
    const nextFromQuery = tab.queryParams?.next;
    pendingRestoreNextCursor.value = tabHasRestorableItems ? (nextFromQuery ?? null) : null;

    // Always reload items from database when initializing
    if (tab.fileIds && tab.fileIds.length > 0) {
        try {
            // Notify parent that we're loading tab data
            if (props.onTabDataLoadingChange) {
                props.onTabDataLoadingChange(true);
            }
            const loadedItems = await props.loadTabItems(tab.id);
            tab.itemsData = loadedItems;
        } catch (error) {
            console.error('Failed to load tab items:', error);
            tab.itemsData = [];
        } finally {
            // Notify parent that tab data loading is complete
            if (props.onTabDataLoadingChange) {
                props.onTabDataLoadingChange(false);
            }
        }
    } else {
        tab.itemsData = [];
    }

    // Restore currentPage from saved queryParams
    if (pageFromQuery !== undefined && pageFromQuery !== null) {
        currentPage.value = pageFromQuery;
    } else {
        currentPage.value = 1;
    }

    // Restore nextCursor from saved queryParams
    if (nextFromQuery !== undefined && nextFromQuery !== null) {
        nextCursor.value = nextFromQuery;
    } else {
        nextCursor.value = null;
    }

    // Set loadAtPage and prepare for masonry initialization
    const serviceValue = tab.queryParams?.service;
    const hasService = typeof serviceValue === 'string' && serviceValue.length > 0;

    if (tab.itemsData && tab.itemsData.length > 0) {
        loadAtPage.value = null;
        items.value = [];
    } else if (hasService) {
        if (pageFromQuery !== undefined && pageFromQuery !== null) {
            loadAtPage.value = pageFromQuery;
        } else {
            loadAtPage.value = 1;
        }
        items.value = [];
    } else {
        loadAtPage.value = null;
        items.value = [];
    }

    await nextTick();

    // Wait for masonry component to be ready - it might not be available immediately
    // Only wait if we have items to initialize with
    if (tab.itemsData && tab.itemsData.length > 0) {
        let retries = 0;
        while (!masonry.value && retries < 20) {
            await nextTick();
            await new Promise(resolve => setTimeout(resolve, 50));
            retries++;
        }

        // If we have pre-loaded items, use masonry.init() to properly initialize
        if (masonry.value) {
            const pageValue = pageFromQuery !== undefined && pageFromQuery !== null ? pageFromQuery : 1;
            const nextValue = nextFromQuery !== undefined && nextFromQuery !== null ? nextFromQuery : null;

            if (pageValue !== undefined && pageValue !== null) {
                currentPage.value = pageValue;
            }
            if (nextValue !== undefined && nextValue !== null) {
                nextCursor.value = nextValue;
            }

            masonry.value.init(tab.itemsData, pageValue, nextValue);

            await nextTick();

            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    if (masonry.value && items.value.length > 0) {
                        masonry.value.refreshLayout(items.value);
                    }
                });
            });
        }
    }

    isTabRestored.value = false;
}

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
                <Button @click="applyService"
                    :disabled="!selectedService || isApplyingService || selectedService === currentTabService" size="sm"
                    data-test="apply-service-button">
                    <Loader2 v-if="isApplyingService" :size="14" class="mr-2 animate-spin" />
                    Apply
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
                        <!-- Capture remove function on first item render -->
                        <div v-if="index === 0" style="display: none;" :ref="() => captureRemoveFn(remove)" />
                        <div class="relative w-full h-full overflow-hidden group masonry-item" :data-item-id="item.id"
                            @mouseenter="hoveredItemIndex = index" @mouseleave="hoveredItemIndex = null">
                            <img :src="item.src || item.thumbnail || ''" :alt="`Item ${item.id}`"
                                class="w-full h-full object-cover" />
                            <div v-show="hoveredItemIndex === index"
                                class="absolute bottom-0 left-0 right-0 flex justify-center pb-2 z-50 pointer-events-auto">
                                <FileReactions :file-id="item.id" :previewed-count="0" :viewed-count="0"
                                    :current-index="index" :total-items="items.length" variant="small"
                                    :remove-item="() => remove(item)"
                                    @reaction="(type) => handleMasonryReaction(item.id, type, remove)" />
                            </div>
                        </div>
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
                // Use the remove function directly from masonry slot if available
                // In template, Vue auto-unwraps refs, so masonryRemoveFn is the value
                if (masonryRemoveFn) {
                    masonryRemoveFn(item);
                    // masonryRemoveFn handles removal via v-model, so items array is updated automatically
                } else if (masonry.value) {
                    // Fallback: find item and use masonry instance method
                    const masonryItem = items.find((i) => i.id === item.id);
                    if (masonryItem) {
                        masonry.value.remove(masonryItem);
                        // masonry.remove() handles removal via v-model, so items array is updated automatically
                    }
                }

                // Always ensure item is removed from items array as a backup
                // masonry.remove() should handle this via v-model, but this ensures it works
                // in test environments where masonry might not be fully initialized or v-model sync is delayed
                // Check if item still exists before removing to avoid double removal
                const itemIndex = items.findIndex((i) => i.id === item.id);
                if (itemIndex !== -1) {
                    items.splice(itemIndex, 1);
                }
            }" :restore-to-masonry="(item, index, masonryInstance) => {
                // Restore item to masonry at original index
                const existingIndex = items.findIndex((i) => i.id === item.id);
                if (existingIndex === -1) {
                    // Try to use masonry's restore method if available
                    if (masonryInstance && typeof masonryInstance.restore === 'function') {
                        masonryInstance.restore(item, index);
                    } else if (masonryInstance && typeof masonryInstance.add === 'function') {
                        masonryInstance.add(item, index);
                    } else if (masonryInstance && typeof masonryInstance.insert === 'function') {
                        masonryInstance.insert(item, index);
                    } else {
                        // Fallback: manually insert at original index
                        const clampedIndex = Math.min(index, items.length);
                        items.splice(clampedIndex, 0, item);
                    }
                }
            }" :tab-id="props.tab?.id" :masonry-instance="masonry" @close="() => { }" />

        <!-- Status/Pagination Info at Bottom -->
        <BrowseStatusBar :items="items" :display-page="displayPage" :next-cursor="nextCursor"
            :is-loading="masonry?.isLoading ?? false" :backfill="backfill"
            :queued-reactions-count="queuedReactions.length"
            :visible="tab !== null && tab !== undefined && hasServiceSelected" />
    </div>
    <div v-else class="flex items-center justify-center h-full" data-test="no-tab-message">
        <p class="text-twilight-indigo-300 text-lg">No tab selected</p>
    </div>
</template>
