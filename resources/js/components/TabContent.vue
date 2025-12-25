<script setup lang="ts">
import {computed, nextTick, onMounted, onUnmounted, ref, shallowRef, watch} from 'vue';
import type {TabData, MasonryItem} from '@/composables/useTabs';
import {Masonry, MasonryItem as VibeMasonryItem} from '@wyxos/vibe';
import {
    ChevronDown,
    ChevronsLeft,
    Copy,
    Image,
    Info,
    Loader2,
    Play,
    TestTube,
    Video,
    X
} from 'lucide-vue-next';
import FileViewer from './FileViewer.vue';
import BrowseStatusBar from './BrowseStatusBar.vue';
import FileReactions from './FileReactions.vue';
import DislikeProgressBar from './DislikeProgressBar.vue';
import {Button} from '@/components/ui/button';
import {Select, SelectContent, SelectItem, SelectTrigger, SelectValue} from './ui/select';
import {Switch} from '@/components/ui/switch';
import Pill from './ui/Pill.vue';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from './ui/dialog';
import {useBackfill} from '@/composables/useBackfill';
import {useBrowseService} from '@/composables/useBrowseService';
import {useContainerBadges} from '@/composables/useContainerBadges';
import {useContainerPillInteractions} from '@/composables/useContainerPillInteractions';
import {usePromptData} from '@/composables/usePromptData';
import { createMasonryInteractions } from '@/utils/masonryInteractions';
import {useItemPreview} from '@/composables/useItemPreview';
import {useMasonryRestore} from '@/composables/useMasonryRestore';
import {useResetDialog} from '@/composables/useResetDialog';
import {useMasonryReactionHandler} from '@/composables/useMasonryReactionHandler';
import {useAutoDislikeQueue} from '@/composables/useAutoDislikeQueue';
import {useBrowseForm} from '@/composables/useBrowseForm';
import BrowseFiltersSheet from './TabFilter.vue';
import ModerationRulesManager from './moderation/ModerationRulesManager.vue';
import ContainerBlacklistManager from './container-blacklist/ContainerBlacklistManager.vue';
import BatchModerationToast from './toasts/BatchModerationToast.vue';
import {useToast} from 'vue-toastification';
// Diagnostic utilities (dev-only, tree-shaken in production)
import {analyzeItemSizes, logItemSizeDiagnostics} from '@/utils/itemSizeDiagnostics';
import type {ReactionType} from '@/types/reaction';
import {items as tabsItems} from '@/actions/App/Http/Controllers/TabController';

interface Props {
    tabId: number | null;
    availableServices: Array<{ key: string; label: string }>;
    onReaction: (fileId: number, type: ReactionType) => void;
    onLoadingChange?: (isLoading: boolean) => void;
    onTabDataLoadingChange?: (isLoading: boolean) => void;
    updateActiveTab: (itemsData: MasonryItem[]) => void;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:loading': [isLoading: boolean];
}>();

// Local state for this tab
// Use shallowRef to reduce Vue reactivity overhead with large arrays (3k+ items)
// This prevents deep reactivity tracking on each item, significantly improving performance
const items = shallowRef<MasonryItem[]>([]);
// Map-based lookup for O(1) item access instead of O(n) find() operations
const itemsMap = ref<Map<number, MasonryItem>>(new Map());

// Sync itemsMap whenever items array changes
watch(
    () => items.value,
    (newItems) => {
        const newMap = new Map<number, MasonryItem>();
        for (const item of newItems) {
            newMap.set(item.id, item);
        }
        itemsMap.value = newMap;

        // Keep parent tab state in sync with the currently rendered items.
        // This is used for tab restoration and for Browse page tests.
        props.updateActiveTab(newItems);

        // Diagnostic: Log item size analysis when items change (only in dev mode)
        if (import.meta.env.DEV && newItems.length > 0) {
            // Only log when we have a significant number of items to avoid spam
            if (newItems.length >= 100 && newItems.length % 500 === 0) {
                const diagnostics = analyzeItemSizes(newItems);
                logItemSizeDiagnostics(diagnostics);
            }
        }
    },
    {immediate: true, deep: false}
);

const masonry = ref<InstanceType<typeof Masonry> | null>(null);
const hoveredItemIndex = ref<number | null>(null);
const hoveredItemId = ref<number | null>(null);
const isFilterSheetOpen = ref(false);

// Internal tab data - loaded from API
const tab = ref<TabData | null>(null);

// Initialize browse form - will be synced when tab loads
const form = useBrowseForm();

// Container refs for FileViewer
const masonryContainer = ref<HTMLElement | null>(null);
const tabContentContainer = ref<HTMLElement | null>(null);
const fileViewer = ref<InstanceType<typeof FileViewer> | null>(null);

// Item preview composable (needs to be initialized early)
// Pass itemsMap for O(1) item existence checks (important for 10k+ items)
const itemPreview = useItemPreview(items, computed(() => tab.value ?? undefined), itemsMap);

// Track if component is mounted to prevent accessing state after unmount
const isMounted = ref(false);

// Track if tab is initializing (loading metadata, restoring state)
const isInitializing = ref(true);

// Browse service composable - fetch services if not provided via prop
const {availableServices: localServices, fetchServices, getNextPage: getNextPageFromService} = useBrowseService();

// Use prop services if available, otherwise use local services
const availableServices = computed(() => {
    return props.availableServices.length > 0 ? props.availableServices : localServices.value;
});


// Masonry restore composable
const {restoreToMasonry, restoreManyToMasonry} = useMasonryRestore(items, masonry);

// Reset dialog composable
const resetDialog = useResetDialog(
    items,
    masonry,
    computed(() => tab.value ?? undefined),
    props.updateActiveTab
);

// Backfill state and handlers
const {
    backfill,
    onBackfillStart,
    onBackfillTick,
    onBackfillStop: onBackfillStopOriginal,
    onBackfillRetryStart,
    onBackfillRetryTick,
    onBackfillRetryStop,
} = useBackfill();

// Wrap onBackfillStop to call original handler (backfill still needs its handler)
function onBackfillStop(payload: { fetched?: number; calls?: number }): void {
    onBackfillStopOriginal(payload);
}

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
            timeout: false, // Don't auto-dismiss
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

// Computed property to display page value
const displayPage = computed(() => masonry.value?.currentPage ?? tab.value?.queryParams?.page ?? 1);

// Compatibility fields used by existing tests and some parent-level orchestration
const currentPage = computed(() => tab.value?.queryParams?.page ?? 1);
const nextCursor = computed(() => {
    if (masonry.value?.paginationHistory && masonry.value.paginationHistory.length > 0) {
        return masonry.value.paginationHistory[masonry.value.paginationHistory.length - 1];
    }
    return tab.value?.queryParams?.next ?? null;
});

const selectedService = computed({
    get: () => form.data.service,
    set: (value: string) => {
        form.data.service = value;
    },
});

const currentTabService = computed(() => {
    const fromTab = tab.value?.queryParams?.service;
    return (typeof fromTab === 'string' && fromTab.length > 0) ? fromTab : (form.data.service || null);
});

const hasServiceSelected = computed(() => {
    // In online mode, a service must be selected.
    if (form.data.sourceType === 'online') {
        return Boolean(form.data.service);
    }

    // In local mode, service selection is not required.
    return true;
});

// Tracks the page we intend to load (used by some tests).
const loadAtPage = ref<number | string | null>(null);

// Back-compat flag referenced by some tests.
const isTabRestored = ref(false);

// Check if we should show the form (new tab with no items)
const shouldShowForm = ref(true);


// Get pageSize from limit filter, defaulting to 20
const pageSize = computed(() => {
    const limit = tab.value?.queryParams?.limit;
    return limit ? Number(limit) : 20;
});

const layout = {
    gutterX: 12,
    gutterY: 12,
    sizes: {base: 1, sm: 2, md: 3, lg: 4, '2xl': 10},
};

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
        // Match by key (provided by backend) - iterate items array for key lookup (key not in Map)
        // This is less common than id lookup, so O(n) is acceptable here
        for (const item of items.value) {
            if (item.key === itemKeyAttr) {
                masonryInteractions.handleAltClickReaction(e, item.id);
                return;
            }
        }
    }

    // Fallback: try to find by image src - iterate items array for src lookup
    // This is less common than id lookup, so O(n) is acceptable here
    const imgEl = itemEl.querySelector('img') as HTMLImageElement | null;
    if (imgEl) {
        const src = imgEl.currentSrc || imgEl.getAttribute('src') || '';
        for (const item of items.value) {
            const itemSrc = (item.src || item.thumbnail || '').split('?')[0].split('#')[0];
            const baseSrc = src.split('?')[0].split('#')[0];
            if (baseSrc === itemSrc || baseSrc.includes(itemSrc) || itemSrc.includes(baseSrc)) {
                masonryInteractions.handleAltClickReaction(e, item.id);
                break;
            }
        }
    }
}


// Initialize composables
// Container badges composable
const containerBadges = useContainerBadges(items);

// Container blacklist manager ref
const containerBlacklistManager = ref<InstanceType<typeof ContainerBlacklistManager> | null>(null);

// Check if a container type is blacklistable for the current service
function isContainerBlacklistable(container: { type: string; source?: string }): boolean {
    // Hardcoded mapping for now - in the future, this could come from service metadata
    // CivitAI: only 'User' type is blacklistable (case-sensitive match)
    if (container.source === 'CivitAI') {
        return container.type === 'User';
    }
    // Wallhaven: no containers are blacklistable yet
    // Add other services as needed
    return false;
}

// Handle container ban button click
function handleContainerBan(container: {
    id: number;
    type: string;
    source?: string;
    source_id?: string;
    referrer?: string | null
}): void {
    if (containerBlacklistManager.value && container.source && container.source_id) {
        containerBlacklistManager.value.openBlacklistDialog({
            id: container.id,
            type: container.type,
            source: container.source,
            source_id: container.source_id,
            referrer: container.referrer ?? null,
        });
    }
}

// Container pill interactions composable
// tabId will be set when tab loads, using computed to reactively get the current value
const containerPillInteractions = useContainerPillInteractions(
    items,
    masonry,
    computed(() => tab.value?.id ?? undefined),
    (fileId: number, type: 'love' | 'like' | 'dislike' | 'funny') => {
        props.onReaction(fileId, type);
    },
    restoreManyToMasonry
);

// Prompt data composable
const promptData = usePromptData(items);

// Masonry reaction handler composable (needs restoreToMasonry)
const {handleMasonryReaction} = useMasonryReactionHandler(
    items,
    itemsMap,
    masonry,
    computed(() => tab.value ?? undefined),
    (fileId: number, type: ReactionType) => {
        props.onReaction(fileId, type);
    },
    restoreToMasonry
);

// Masonry interactions composable (needs handleMasonryReaction)
const masonryInteractions = createMasonryInteractions(
    items,
    masonry,
    handleMasonryReaction
);

// Internal function to load tab items and metadata
async function loadTabItems(tabId: number): Promise<{ items: MasonryItem[]; tab: TabData }> {
    try {
        const response = await window.axios.get(tabsItems.url(tabId));
        const data: unknown = response.data;

        // Be defensive here: some tests mock this endpoint loosely.
        const dataObj = (data && typeof data === 'object') ? (data as Record<string, unknown>) : null;
        const tabFromResponse: unknown = dataObj && 'tab' in dataObj ? dataObj.tab : undefined;
        const tabObj = (tabFromResponse && typeof tabFromResponse === 'object')
            ? (tabFromResponse as Record<string, unknown>)
            : null;

        const rawQueryParams: unknown = tabObj
            ? ('queryParams' in tabObj ? tabObj.queryParams : ('query_params' in tabObj ? tabObj.query_params : undefined))
            : undefined;

        const queryParams = (rawQueryParams && typeof rawQueryParams === 'object')
            ? (rawQueryParams as Record<string, unknown>)
            : {};

        const resolvedTabIdRaw = tabObj && typeof tabObj.id === 'number' ? tabObj.id : tabId;
        const resolvedTabId = typeof resolvedTabIdRaw === 'number' ? resolvedTabIdRaw : tabId;
        const resolvedLabel = tabObj && typeof tabObj.label === 'string' ? tabObj.label : `Tab ${resolvedTabId}`;

        const itemsFromResponse: unknown = dataObj && 'items' in dataObj ? dataObj.items : undefined;
        const resolvedItems = Array.isArray(itemsFromResponse)
            ? (itemsFromResponse as MasonryItem[])
            : (Array.isArray(data) ? (data as MasonryItem[]) : []);

        const tabData: TabData = {
            id: resolvedTabId,
            label: resolvedLabel,
            queryParams,
            itemsData: [],
            position: 0,
            isActive: false,
            sourceType: (queryParams.sourceType === 'local' ? 'local' : 'online') as 'online' | 'local',
        };
        return {
            items: resolvedItems || [],
            tab: tabData,
        };
    } catch (error) {
        console.error('Failed to load tab items:', error);
        throw error;
    }
}


// Handle apply event from TabFilter
function handleApplyFilters(): void {
    applyService();
}

// Handle reset event from TabFilter
function handleResetFilters(): void {
    form.reset();
}

// Handle moderation rules changed
function handleModerationRulesChanged(): void {
    // TODO: Implement moderation rules changed logic
}

// Apply selected service to current tab (play button for new tabs)
async function applyService(): Promise<void> {
    if (!tab.value?.id) {
        return;
    }

    shouldShowForm.value = false;
    loadAtPage.value = 1;

    // In production, Masonry exposes loadPage(). In tests, it may be mocked without it.
    if (masonry.value?.loadPage) {
        await masonry.value.loadPage(1);
        return;
    }

    await getPage(1);
}


// Cancel masonry loading
function cancelMasonryLoad(): void {
    if (masonry.value?.isLoading && typeof masonry.value.cancelLoad === 'function') {
        masonry.value.cancelLoad();
    }
}

// Load next page manually (used by both button click and carousel load more)
async function loadNextPage(): Promise<void> {
    if (masonry.value && !masonry.value.isLoading && !masonry.value.hasReachedEnd) {
        if (typeof masonry.value.loadNext === 'function') {
            await masonry.value.loadNext();
        }
    }
}

// Remove item from masonry using Map lookup
function removeItemFromMasonry(item: MasonryItem): void {
    if (masonry.value?.remove) {
        const masonryItem = itemsMap.value.get(item.id);
        if (masonryItem) {
            masonry.value.remove(masonryItem);
        }
    }
}

// Auto-dislike queue composable
const autoDislikeQueue = useAutoDislikeQueue(items, masonry);

/**
 * Wrapper function for Masonry's getPage callback
 * This function calls the browse service with form data and tab ID
 */
async function getPage(page: number | string): Promise<{ items: MasonryItem[]; nextPage: string | number | null }> {
    if (!tab.value?.id) {
        throw new Error('Tab ID is required for getPage');
    }

    const result = await getNextPageFromService(page, form.getData(), tab.value.id);

    // Some callers (notably unit tests) call getPage() directly without Masonry
    // pushing the returned page into the v-model:items array. If we're empty,
    // hydrate items so the rest of the tab state stays consistent.
    if (items.value.length === 0 && Array.isArray(result.items) && result.items.length > 0) {
        items.value = [...result.items];
    }

    // Keep local tab state in sync for UI/status bar + tests.
    // Backend may persist query_params when `tab_id` is provided, but we still reflect the
    // latest requested page/cursor immediately.
    if (tab.value) {
        if (!tab.value.queryParams || typeof tab.value.queryParams !== 'object') {
            tab.value.queryParams = {};
        }
        tab.value.queryParams['page'] = page;
        tab.value.queryParams['next'] = result.nextPage ?? null;
    }

    return result;
}

// Event handlers for masonry items
function handleMasonryItemMouseEnter(index: number, itemId: number): void {
    hoveredItemIndex.value = index;
    hoveredItemId.value = itemId;

    // Freeze auto-dislike queue only if hovering over an item with an active countdown
    if (autoDislikeQueue.hasActiveCountdown(itemId)) {
        autoDislikeQueue.freezeAll();
    }
}

function handleMasonryItemMouseLeave(): void {
    const itemId = hoveredItemId.value;
    const wasHoveringItemWithCountdown = itemId !== null && autoDislikeQueue.hasActiveCountdown(itemId);

    hoveredItemIndex.value = null;
    hoveredItemId.value = null;
    containerBadges.setHoveredContainerId(null);

    // Unfreeze auto-dislike queue when mouse leaves an item with active countdown
    // (The queue will be frozen again if mouse enters another item with countdown)
    if (wasHoveringItemWithCountdown) {
        autoDislikeQueue.unfreezeAll();
    }
}

function handleFileViewerOpen(): void {
    // Freeze only auto-dislike countdowns when FileViewer opens
    // Other countdowns (e.g., reaction queue) continue normally
    autoDislikeQueue.freezeAutoDislikeOnly();
}

function handleFileViewerClose(): void {
    // Resume only auto-dislike countdowns when FileViewer closes (after 2 second delay)
    autoDislikeQueue.unfreezeAutoDislikeOnly();
}

async function handleItemInView(payload: {
    item: { id?: number };
    type: 'image' | 'video'
}, item: MasonryItem): Promise<void> {
    void payload;
    void item;
    // This event is kept for backward compatibility, but we now use in-view-and-loaded
    // for triggering preview count increment
}

/**
 * Handle when item is both fully in view AND media is loaded.
 * This is when we should increment preview count and check for auto-dislike.
 */
async function handleItemInViewAndLoaded(payload: {
    item: { id?: number };
    type: 'image' | 'video';
    src: string
}, item: MasonryItem): Promise<void> {
    // payload.item is the item passed to MasonryItem, which should have the id
    const itemId = payload.item?.id ?? item?.id;
    if (itemId) {
        // Increment preview count when item is fully in view AND media is loaded
        const result = await itemPreview.incrementPreviewCount(itemId);

        // Get fresh item reference from items array after update (item might have been updated)
        // This ensures we have the latest will_auto_dislike and previewed_count values
        await nextTick(); // Wait for item update to complete
        const updatedItem = items.value.find((i) => i.id === itemId) || item;

        // Check if item is already flagged for auto-dislike (from moderation rules)
        const isModerationFlagged = updatedItem.will_auto_dislike === true;

        // Start countdown if:
        // 1. Item was already flagged for auto-dislike (from moderation rules) OR
        // 2. Preview count increment indicates it should be auto-disliked (from preview count threshold)
        const shouldAutoDislike = isModerationFlagged || result?.will_auto_dislike === true;

        if (shouldAutoDislike) {
            autoDislikeQueue.startAutoDislikeCountdown(itemId, updatedItem);
        }
    }
}

async function handleItemPreloadSuccess(payload: {
    item: { id?: number };
    type: 'image' | 'video';
    src: string
}, item: MasonryItem): Promise<void> {
    void payload;
    void item;
    // Item preload successful - handler kept for potential future use
}

function handleMasonryItemAuxClick(e: MouseEvent, item: MasonryItem): void {
    masonryInteractions.handleMasonryItemAuxClick(e, item);
}

// Event handlers for container pills
function handleContainerPillMouseEnter(containerId: number): void {
    containerBadges.setHoveredContainerId(containerId);
}

function handleContainerPillMouseLeave(): void {
    containerBadges.setHoveredContainerId(null);
}

function handleContainerPillClick(containerId: number, e: MouseEvent): void {
    containerPillInteractions.handlePillClick(containerId, e);
}

function handleContainerPillDblClick(containerId: number, e: MouseEvent): void {
    containerPillInteractions.handlePillClick(containerId, e, true);
}

function handleContainerPillContextMenu(containerId: number, e: MouseEvent): void {
    containerPillInteractions.handlePillClick(containerId, e);
}

function handleContainerPillAuxClick(containerId: number, e: MouseEvent): void {
    containerPillInteractions.handlePillAuxClick(containerId, e);
}

function handleContainerPillMouseDown(e: MouseEvent): void {
    if (e.button === 1) {
        e.preventDefault();
    }
}

function handlePillDismiss(container: {
    id: number;
    type: string;
    source?: string;
    source_id?: string;
    referrer?: string | null
}): void {
    handleContainerBan(container);
}

function handlePromptDialogClick(item: MasonryItem): void {
    promptData.openPromptDialog(item);
}

function handleRemoveItem(remove: (item: MasonryItem) => void, item: MasonryItem): void {
    remove(item);
}

function handleFileReaction(itemId: number, type: ReactionType, remove: (item: MasonryItem) => void): void {
    void remove;
    // Cancel auto-dislike countdown if user reacts manually
    autoDislikeQueue.cancelAutoDislikeCountdown(itemId);
    // Note: remove parameter is kept for FileReactions component compatibility but not used here
    handleMasonryReaction(itemId, type);
}

function handleCopyPromptClick(): void {
    if (promptData.currentPromptData.value) {
        promptData.copyPromptToClipboard(promptData.currentPromptData.value);
    }
}

function handleTestPromptClick(): void {
    if (promptData.currentPromptData.value) {
        const params = new URLSearchParams();
        params.set('text', promptData.currentPromptData.value);
        const url = `/moderation/test?${params.toString()}`;
        window.open(url, '_blank', 'noopener,noreferrer');
    }
}

function handlePromptDialogUpdate(val: boolean): void {
    if (!val) {
        promptData.closePromptDialog();
    }
}

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

// Initialize tab state on mount - this will run every time the component is created (tab switch)
onMounted(async () => {
    isMounted.value = true;
    isInitializing.value = true;

    try {
        // Fetch services if not provided via prop (fallback for when tab mounts before parent fetches)
        if (props.availableServices.length === 0) {
            await fetchServices();
        }

        if (props.tabId) {
            // Load tab items and metadata from database
            const {items: loadedItems, tab: tabData} = await loadTabItems(props.tabId);

            // Store tab data locally
            tab.value = tabData;

            isTabRestored.value = true;

            // Restore items into masonry state
            // Use a new array reference so shallowRef + watchers update efficiently
            items.value = [...(loadedItems ?? [])];

            // Assign query params to the form to restore any previous values
            form.syncFromTab(tabData);

            // If tab has items, hide the form and show masonry
            if (loadedItems.length > 0) {
                shouldShowForm.value = false;
            }
        }
    } finally {
        // Mark initialization as complete
        // Note: Items restoration happens via watcher above
        isInitializing.value = false;
    }
});

// Cleanup on unmount
onUnmounted(() => {
    // Mark component as unmounted to prevent callbacks from accessing state
    isMounted.value = false;

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

// Expose getPage for testing
defineExpose({
    getPage,
    // Expose compatibility fields used by some Browse tests
    currentPage,
    nextCursor,
    selectedService,
    currentTabService,
    hasServiceSelected,
    loadAtPage,
    isTabRestored,
});
</script>

<template>
    <div v-if="tab" ref="tabContentContainer" class="flex-1 min-h-0 flex flex-col relative">
        <!-- Service Selection Header (only show when not showing form) -->
        <div v-if="!shouldShowForm" class="px-4 py-3 border-b border-twilight-indigo-500/50 bg-prussian-blue-700/50"
             data-test="service-selection-header">
            <div class="flex items-center gap-3">
                <!-- Source Type Toggle (Online/Local) -->
                <div>
                    <Select :model-value="form.data.sourceType"
                            @update:model-value="(val: string) => { form.data.sourceType = val as 'online' | 'local'; }"
                            :disabled="masonry?.isLoading ?? false">
                        <SelectTrigger class="w-[120px]" data-test="source-type-select-trigger">
                            <SelectValue placeholder="Online"/>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="online" data-test="source-type-online">Online</SelectItem>
                            <SelectItem value="local" data-test="source-type-local">Local</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <!-- Service Dropdown -->
                <div class="flex-1">
                    <Select v-model="form.data.service" :disabled="masonry?.isLoading ?? false">
                        <SelectTrigger class="w-[200px]" data-test="service-select-trigger">
                            <SelectValue placeholder="Select a service..."/>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="service in availableServices.filter(s => s.key !== 'local')"
                                        :key="service.key" :value="service.key" data-test="service-select-item">
                                {{ service.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <!-- Filters Button (Primary) -->
                <BrowseFiltersSheet v-model:open="isFilterSheetOpen" :available-services="availableServices" :tab="tab"
                                    :masonry="masonry" :is-masonry-loading="masonry?.isLoading ?? false"
                                    @apply="handleApplyFilters"
                                    @reset="handleResetFilters"/>

                <!-- Moderation Rules Button (Info) -->
                <ModerationRulesManager :disabled="masonry?.isLoading ?? false"
                                        @rules-changed="handleModerationRulesChanged"/>

                <!-- Container Blacklists Button (Warning) -->
                <ContainerBlacklistManager ref="containerBlacklistManager" :disabled="masonry?.isLoading ?? false"
                                           @blacklists-changed="handleModerationRulesChanged"/>

                <!-- Cancel Loading Button -->
                <Button @click="cancelMasonryLoad" size="sm" variant="ghost" class="h-10 w-10" color="danger"
                        data-test="cancel-loading-button" title="Cancel loading" :disabled="!masonry?.isLoading">
                    <X :size="14"/>
                </Button>

                <!-- Load Next Page Button -->
                <Button @click="loadNextPage" size="sm" variant="ghost" class="h-10 w-10"
                        data-test="load-next-page-button" title="Load next page"
                        :disabled="masonry?.isLoading || masonry?.hasReachedEnd">
                    <ChevronDown :size="14"/>
                </Button>

                <!-- Reset to First Page Button -->
                <Button @click="resetDialog.openResetDialog" size="sm" variant="ghost" class="h-10 w-10" color="danger"
                        data-test="reset-to-first-page-button" title="Reset to first page">
                    <ChevronsLeft :size="14"></ChevronsLeft>
                </Button>

                <!-- Apply Service Button -->
                <Button @click="applyService" size="sm" class="h-10 w-10" data-test="apply-service-button"
                        :loading="masonry?.isLoading" title="Apply selected service">
                    <Play :size="14"/>
                </Button>
            </div>
        </div>

        <!-- Masonry Content -->
        <div class="flex-1 min-h-0">
            <!-- Initializing state (for restored tabs) -->
            <div v-if="isInitializing && tab && items.length > 0" class="flex items-center justify-center h-full"
                 data-test="initializing-tab-message">
                <div class="flex flex-col items-center gap-3">
                    <Loader2 :size="24" class="animate-spin text-smart-blue-400"/>
                    <p class="text-twilight-indigo-300 text-lg">Initializing tab...</p>
                </div>
            </div>
            <!-- Masonry -->
            <div v-if="tab" class="relative h-full masonry-container" ref="masonryContainer" @click="onMasonryClick"
                 @contextmenu.prevent="onMasonryClick" @mousedown="onMasonryMouseDown">
                <Masonry :key="tab?.id" ref="masonry" v-model:items="items" :layout="layout" layout-mode="auto"
                         :mobile-breakpoint="768" init="manual" mode="backfill" :backfill-delay-ms="2000"
                         :backfill-max-calls="Infinity" :page-size="pageSize" :get-page="getPage"
                         @loading:start="handleLoadingStart" @backfill:start="onBackfillStart"
                         @backfill:tick="onBackfillTick" @backfill:stop="onBackfillStop"
                         @backfill:retry-start="onBackfillRetryStart" @backfill:retry-tick="onBackfillRetryTick"
                         @backfill:retry-stop="onBackfillRetryStop" @loading:stop="handleLoadingStop"
                         data-test="masonry-component">
                    <!-- Loading message slot - show form for new tabs -->
                    <template #loading-message>
                        <div v-if="shouldShowForm" class="flex items-center justify-center h-full"
                             data-test="new-tab-form">
                            <div
                                class="flex flex-col items-center gap-4 p-8 bg-prussian-blue-700/50 rounded-lg border border-twilight-indigo-500/30 max-w-md w-full">
                                <h2 class="text-xl font-semibold text-twilight-indigo-100 mb-2">Start Browsing</h2>
                                <p class="text-sm text-twilight-indigo-300 mb-6 text-center">Select a service and click
                                    play to
                                    begin</p>

                                <!-- Online/Local Switch -->
                                <div class="w-full flex items-center justify-between">
                                    <label class="block text-sm font-medium text-twilight-indigo-200">Source</label>
                                    <div class="flex items-center gap-3">
                                        <span class="text-sm text-twilight-indigo-300"
                                              :class="{ 'text-twilight-indigo-100 font-medium': !form.isLocalMode.value }">Online</span>
                                        <Switch :model-value="form.isLocalMode.value"
                                                @update:model-value="(val: boolean) => form.isLocalMode.value = val"
                                                data-test="source-type-switch"/>
                                        <span class="text-sm text-twilight-indigo-300"
                                              :class="{ 'text-twilight-indigo-100 font-medium': form.isLocalMode.value }">Local</span>
                                    </div>
                                </div>

                                <!-- Service Dropdown (only show when Online) -->
                                <div v-if="form.data.sourceType === 'online'" class="w-full">
                                    <label
                                        class="block text-sm font-medium text-twilight-indigo-200 mb-2">Service</label>
                                    <Select v-model="form.data.service" :disabled="masonry?.isLoading ?? false">
                                        <SelectTrigger class="w-full" data-test="service-select-trigger">
                                            <SelectValue placeholder="Select a service..."/>
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem
                                                v-for="service in availableServices.filter(s => s.key !== 'local')"
                                                :key="service.key" :value="service.key" data-test="service-select-item">
                                                {{ service.label }}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <!-- Action Buttons -->
                                <div class="flex gap-3 w-full mt-2 items-center">
                                    <!-- Play Button -->
                                    <Button @click="applyService" size="sm" class="flex-1" data-test="play-button">
                                        <Play :size="16"/>
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </template>
                    <template #default="{ item, index, remove }">
                        <!-- Get fresh item reference from itemsMap to ensure reactivity with shallowRef -->
                        <!-- The item from Masonry slot may be stale, so we look it up fresh from itemsMap -->
                        <!-- In templates, refs are auto-unwrapped, so use itemsMap not itemsMap.value -->
                        <VibeMasonryItem :item="itemsMap.get(item.id) || item" :remove="remove" :preload-threshold="0.5"
                                         @mouseenter="handleMasonryItemMouseEnter(index, item.id)"
                                         @mouseleave="handleMasonryItemMouseLeave"
                                         @in-view="(payload: { item: { id?: number }; type: 'image' | 'video' }) => handleItemInView(payload, item)"
                                         @in-view-and-loaded="(payload: { item: { id?: number }; type: 'image' | 'video'; src: string }) => handleItemInViewAndLoaded(payload, item)"
                                         @preload:success="(payload: { item: { id?: number }; type: 'image' | 'video'; src: string }) => handleItemPreloadSuccess(payload, item)">
                            <template
                                #default="{ item: slotItem, imageLoaded, imageError, isLoading, showMedia, imageSrc, mediaType }">
                                <!-- Use item from slot props (reactive) instead of outer scope item (may be stale) -->
                                <div
                                    class="relative w-full h-full overflow-hidden rounded-lg group masonry-item bg-prussian-blue-500 transition-[opacity,border-color] duration-300 ease-in-out"
                                    :data-key="slotItem.key" :data-masonry-item-id="slotItem.id"
                                    :class="containerBadges.getMasonryItemClasses.value(slotItem)"
                                    @mousedown="(e: MouseEvent) => masonryInteractions.handleMasonryItemMouseDown(e)"
                                    @auxclick="(e: MouseEvent) => handleMasonryItemAuxClick(e, slotItem)">
                                    <!-- Placeholder background - icon by default (before preloading starts) -->
                                    <!-- Note: Vibe's MasonryItem provides this, but we override for dark theme -->
                                    <div v-if="!imageLoaded && !imageError" :class="[
                                        'absolute inset-0 flex items-center justify-center transition-opacity duration-500',
                                        showMedia ? 'opacity-0 pointer-events-none' : 'opacity-100'
                                    ]">
                                        <!-- Media type indicator badge - shown BEFORE preloading starts -->
                                        <div
                                            class="w-12 h-12 rounded-full bg-prussian-blue-700/80 backdrop-blur-sm flex items-center justify-center shadow-sm border border-twilight-indigo-500/30">
                                            <Image v-if="mediaType === 'image'" :size="20"
                                                   class="text-twilight-indigo-300"/>
                                            <Video v-else-if="mediaType === 'video'" :size="20"
                                                   class="text-twilight-indigo-300"/>
                                        </div>
                                    </div>

                                    <!-- Spinner (only shown when loading/preloading) - centered vertically -->
                                    <div v-if="isLoading"
                                         class="absolute inset-0 flex items-center justify-center z-10">
                                        <div
                                            class="bg-prussian-blue-700/90 backdrop-blur-sm rounded-full px-3 py-1.5 shadow-sm border border-twilight-indigo-500/30">
                                            <Loader2 class="w-4 h-4 text-smart-blue-400 animate-spin"/>
                                        </div>
                                    </div>

                                    <!-- Error state -->
                                    <div v-if="imageError"
                                         class="absolute inset-0 flex flex-col items-center justify-center bg-prussian-blue-800/50 text-twilight-indigo-300 text-sm p-4 text-center border border-twilight-indigo-500/30 rounded-lg">
                                        <Image :size="32" class="mb-2 opacity-50"/>
                                        <span>Failed to load image</span>
                                    </div>

                                    <!-- Image (only render when imageSrc is available from Vibe's preloading) -->
                                    <img v-if="imageSrc && !imageError" :src="imageSrc" :alt="`Item ${slotItem.id}`"
                                         :class="[
                                            'w-full h-full object-cover transition-opacity duration-700 ease-in-out',
                                            imageLoaded && showMedia ? 'opacity-100' : 'opacity-0'
                                        ]"/>

                                    <!-- Container badges (shows on hover with type and count) -->
                                    <Transition name="fade">
                                        <div
                                            v-if="hoveredItemIndex === index && imageLoaded && containerBadges.getContainersForItem(slotItem).length > 0"
                                            class="absolute top-2 left-2 z-50 pointer-events-auto flex flex-col gap-1">
                                            <div v-for="container in containerBadges.getContainersForItem(slotItem)"
                                                 :key="container.id" class="cursor-pointer"
                                                 @mouseenter="handleContainerPillMouseEnter(container.id)"
                                                 @mouseleave="handleContainerPillMouseLeave"
                                                 @click.stop="(e: MouseEvent) => handleContainerPillClick(container.id, e)"
                                                 @dblclick.stop="(e: MouseEvent) => handleContainerPillDblClick(container.id, e)"
                                                 @contextmenu.stop="(e: MouseEvent) => handleContainerPillContextMenu(container.id, e)"
                                                 @auxclick.stop="(e: MouseEvent) => handleContainerPillAuxClick(container.id, e)"
                                                 @mousedown.stop="handleContainerPillMouseDown">
                                                <Pill :label="container.type"
                                                      :value="containerBadges.getItemCountForContainerId(container.id)"
                                                      :variant="containerBadges.getVariantForContainerType(container.type)"
                                                      :dismissible="isContainerBlacklistable(container) ? 'danger' : false"
                                                      @dismiss="() => handlePillDismiss(container)"/>
                                            </div>
                                        </div>
                                    </Transition>

                                    <!-- Info badge (shows on hover, opens dialog on click) -->
                                    <Transition name="fade">
                                        <div v-if="hoveredItemIndex === index && imageLoaded"
                                             class="absolute top-2 right-2 z-50 pointer-events-auto">
                                            <Button variant="ghost" size="sm"
                                                    class="h-7 w-7 p-0 bg-black/50 hover:bg-black/70 text-white"
                                                    @click.stop="handlePromptDialogClick(slotItem)"
                                                    aria-label="Show prompt">
                                                <Info :size="14"/>
                                            </Button>
                                        </div>
                                    </Transition>

                                    <!-- Hover reactions overlay -->
                                    <Transition name="fade">
                                        <div v-if="hoveredItemIndex === index && imageLoaded"
                                             class="absolute bottom-0 left-0 right-0 flex justify-center pb-2 z-50 pointer-events-auto">
                                            <FileReactions :file-id="slotItem.id"
                                                           :previewed-count="(slotItem.previewed_count as number) ?? 0"
                                                           :viewed-count="(slotItem.seen_count as number) ?? 0"
                                                           :current-index="index" :total-items="items.length"
                                                           variant="small"
                                                           :remove-item="() => handleRemoveItem(remove, slotItem)"
                                                           @reaction="(type) => handleFileReaction(slotItem.id, type, remove)"/>
                                        </div>
                                    </Transition>

                                    <!-- Progress Bar Overlay (only show if will_auto_dislike is true and countdown is active) -->
                                    <DislikeProgressBar
                                        v-if="slotItem.will_auto_dislike && autoDislikeQueue.hasActiveCountdown(slotItem.id)"
                                        :progress="autoDislikeQueue.getCountdownProgress(slotItem.id)"
                                        :countdown="autoDislikeQueue.formatCountdown(autoDislikeQueue.getCountdownRemainingTime(slotItem.id))"
                                        :is-frozen="autoDislikeQueue.isFrozen.value"
                                        :is-hovered="hoveredItemId === slotItem.id && autoDislikeQueue.hasActiveCountdown(slotItem.id)"/>
                                </div>
                            </template>
                        </VibeMasonryItem>
                    </template>
                </Masonry>
            </div>
        </div>

        <!-- File Viewer -->
        <FileViewer ref="fileViewer" :container-ref="tabContentContainer" :masonry-container-ref="masonryContainer"
                    :items="items" :has-more="!masonry?.hasReachedEnd" :is-loading="masonry?.isLoading ?? false"
                    :on-load-more="loadNextPage" :on-reaction="props.onReaction"
                    :remove-from-masonry="removeItemFromMasonry"
                    :restore-to-masonry="restoreToMasonry" :tab-id="tab?.id ?? null" :masonry-instance="masonry"
                    @open="handleFileViewerOpen" @close="handleFileViewerClose"/>

        <!-- Status/Pagination Info at Bottom (only show when masonry is visible, not when showing form) -->
        <BrowseStatusBar :items="items" :display-page="displayPage"
                         :next-cursor="(masonry?.paginationHistory && masonry.paginationHistory.length > 0) ? masonry.paginationHistory[masonry.paginationHistory.length - 1] : (tab?.queryParams?.next ?? null)"
                         :is-loading="masonry?.isLoading ?? false" :backfill="backfill"
                         :visible="tab !== null && tab !== undefined && !shouldShowForm"/>

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
        <Dialog v-model="promptData.promptDialogOpen.value" @update:model-value="handlePromptDialogUpdate">
            <DialogContent class="sm:max-w-[600px] bg-prussian-blue-600">
                <DialogHeader>
                    <DialogTitle class="text-twilight-indigo-100">Prompt</DialogTitle>
                </DialogHeader>
                <div class="space-y-4 mt-4">
                    <div
                        v-if="promptData.promptDialogItemId.value !== null && promptData.promptDataLoading.value.get(promptData.promptDialogItemId.value)"
                        class="flex items-center gap-2 text-sm text-twilight-indigo-100">
                        <Loader2 :size="16" class="animate-spin"/>
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
                    <Button variant="outline" size="sm" @click="handleCopyPromptClick" aria-label="Copy prompt">
                        <Copy :size="16" class="mr-2"/>
                        Copy
                    </Button>
                    <Button v-if="promptData.currentPromptData.value" variant="outline" size="sm"
                            @click="handleTestPromptClick" aria-label="Test prompt against moderation rules">
                        <TestTube :size="16" class="mr-2"/>
                        Test
                    </Button>
                    <DialogClose as-child>
                        <Button variant="outline" size="sm" @click="promptData.closePromptDialog()">
                            Close
                        </Button>
                    </DialogClose>
                </DialogFooter>
            </DialogContent>
        </Dialog>

    </div>
    <div v-else class="flex items-center justify-center h-full" data-test="no-tab-message">
        <p class="text-twilight-indigo-300 text-lg">No tab selected</p>
    </div>
</template>

<style scoped>
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
