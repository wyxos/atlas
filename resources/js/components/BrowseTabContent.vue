<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, nextTick, watch, shallowRef } from 'vue';
import type { MasonryItem, BrowseTabData } from '@/composables/useBrowseTabs';
import { Masonry, MasonryItem as VibeMasonryItem } from '@wyxos/vibe';
import { Loader2, Info, Copy, RefreshCcw, ChevronsLeft, X, ChevronDown, Play, ThumbsDown, Image, Video, TestTube } from 'lucide-vue-next';
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
import { useBackfill } from '@/composables/useBackfill';
import { useBrowseService, type GetPageResult } from '@/composables/useBrowseService';
import { useReactionQueue } from '@/composables/useReactionQueue';
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
import { useItemVirtualization } from '@/composables/useItemVirtualization';
import { useImmediateActionsToast } from '@/composables/useImmediateActionsToast';
import BrowseFiltersSheet from './BrowseFiltersSheet.vue';
import ModerationRulesManager from './moderation/ModerationRulesManager.vue';
import ContainerBlacklistManager from './container-blacklist/ContainerBlacklistManager.vue';
// Diagnostic utilities (dev-only, tree-shaken in production)
import { analyzeItemSizes, logItemSizeDiagnostics } from '@/utils/itemSizeDiagnostics';
import type { ReactionType } from '@/types/reaction';
import { batchPerformAutoDislike } from '@/actions/App/Http/Controllers/FilesController';

interface Props {
    tab?: BrowseTabData;
    availableServices: Array<{ key: string; label: string }>;
    onReaction: (fileId: number, type: ReactionType) => void;
    onLoadingChange?: (isLoading: boolean) => void;
    onTabDataLoadingChange?: (isLoading: boolean) => void;
    updateActiveTab: (itemsData: MasonryItem[]) => void;
    loadTabItems: (tabId: number) => Promise<MasonryItem[]>;
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
    (newItems, oldItems) => {
        const newMap = new Map<number, MasonryItem>();
        for (const item of newItems) {
            newMap.set(item.id, item);
        }
        itemsMap.value = newMap;

        // Diagnostic: Log item size analysis when items change (only in dev mode)
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

const masonry = ref<InstanceType<typeof Masonry> | null>(null);
const currentPage = ref<string | number | null>(1);
const nextCursor = ref<string | number | null>(null);
const loadAtPage = ref<string | number | null>(null);
const isTabRestored = ref(false);
const selectedService = ref<string>('');
const hoveredItemIndex = ref<number | null>(null);
const hoveredItemId = ref<number | null>(null);
const isFilterSheetOpen = ref(false);

// Container refs for FileViewer
const masonryContainer = ref<HTMLElement | null>(null);
const tabContentContainer = ref<HTMLElement | null>(null);
const fileViewer = ref<InstanceType<typeof FileViewer> | null>(null);

// Reaction queue
const { queuedReactions, queueReaction, cancelReaction } = useReactionQueue();

// Item preview composable (needs to be initialized early)
const itemPreview = useItemPreview(items, computed(() => props.tab));

// Track if component is mounted to prevent accessing state after unmount
const isMounted = ref(false);

// Auto-dislike queue composable with expiration handler
// This handles both moderation rules and container blacklists (both flag files for countdown)
const autoDislikeQueue = useAutoDislikeQueue(handleAutoDislikeExpire);

// Item virtualization composable - loads minimal items initially, then full data on-demand
const itemVirtualization = useItemVirtualization(items);

// Immediate actions toast composable - collects and displays immediately processed items
const immediateActionsToast = useImmediateActionsToast();

// Browse service composable - fetch services if not provided via prop
const { availableServices: localServices, fetchServices } = useBrowseService();

// Use prop services if available, otherwise use local services
const availableServices = computed(() => {
    return props.availableServices.length > 0 ? props.availableServices : localServices.value;
});

// Handle auto-dislike queue expiration - perform auto-dislike in batch
async function handleAutoDislikeExpire(expiredIds: number[]): Promise<void> {
    // Guard: Don't proceed if component is unmounted
    if (!isMounted.value) {
        return;
    }

    if (expiredIds.length === 0) {
        return;
    }

    // Batch perform auto-dislike (backend handles de-association from tabs)
    try {
        const response = await window.axios.post<{
            message: string;
            auto_disliked_count: number;
            file_ids: number[];
        }>(batchPerformAutoDislike.url(), {
            file_ids: expiredIds,
            tab_id: props.tab?.id,
        });

        // Guard: Check again after async operation
        if (!isMounted.value) {
            return;
        }

        const autoDislikedIds = response.data.file_ids;

        // Remove from masonry - get items directly from items array (not itemsMap) to ensure correct references
        // This matches the pattern used in container pill interactions for correct FLIP animations
        const itemsToRemove: MasonryItem[] = items.value.filter((item) => autoDislikedIds.includes(item.id));

        // Remove from masonry using removeMany for batch removal
        // Masonry component manages items array through v-model, so no manual array manipulation needed
        if (masonry.value?.removeMany && itemsToRemove.length > 0) {
            await masonry.value.removeMany(itemsToRemove);
        } else if (masonry.value?.remove) {
            // Fallback to individual removal if removeMany is not available
            for (const item of itemsToRemove) {
                masonry.value.remove(item);
            }
        }

        // Guard: Check again after masonry operations
        if (!isMounted.value) {
            return;
        }

        // Update tab (remove from itemsData)
        // Note: Backend already de-associated from tabs, we just update local state
        if (props.tab && autoDislikedIds.length > 0) {
            const updatedItemsData = props.tab.itemsData.filter((item) => !autoDislikedIds.includes(item.id));
            props.updateActiveTab(updatedItemsData);
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
    onBackfillStop: onBackfillStopOriginal,
    onBackfillRetryStart,
    onBackfillRetryTick,
    onBackfillRetryStop,
} = useBackfill();

// Wrap onBackfillStop to call original handler (backfill still needs its handler)
function onBackfillStop(payload: { fetched?: number; calls?: number }): void {
    onBackfillStopOriginal(payload);
}

// Handle loading:stop to show toast when loading completes (regardless of backfill)
function onLoadingStop(payload: { fetched?: number }): void {
    // Show toast with collected immediate actions when loading completes
    immediateActionsToast.showToast();
}

// Computed property to display page value
const displayPage = computed(() => currentPage.value ?? 1);

// Get current tab's service
const currentTabService = computed(() => {
    return props.tab?.queryParams?.service as string | null;
});

// Get pageSize from limit filter, defaulting to 20
const pageSize = computed(() => {
    const limit = props.tab?.queryParams?.limit;
    return limit ? Number(limit) : 20;
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

    // Clear existing items and reset pagination
    items.value = [];
    currentPage.value = 1;
    nextCursor.value = null;
    loadAtPage.value = 1;
    selectedService.value = filters.service;

    // Update tab - backend will update query_params when browse request is made
    props.updateActiveTab([]);

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
    // Rules are applied server-side on next API call, no action needed here
}

// Check if current tab has a service selected
const hasServiceSelected = computed(() => {
    // Check both tab's queryParams (from backend) and local selectedService (during selection)
    const service = currentTabService.value || selectedService.value;
    return typeof service === 'string' && service.length > 0;
});

// Always skip initial load - we control loading explicitly via loadAtPage
// Masonry will load when loadAtPage is set, and auto-initialize pagination state via initialPage/initialNextPage props
const shouldSkipInitialLoad = true;

// Computed property for apply button disabled state
// Button should only be disabled when:
// - No service is selected
// - Service is currently being applied (masonry is loading)
// - Selected service is already the current service
// It should NOT be disabled when masonry is loading
const isApplyButtonDisabled = computed(() => {
    return !selectedService.value || (masonry?.value?.isLoading ?? false) || selectedService.value === currentTabService.value;
});

// Browse service composable
const {
    getNextPage: getNextPageFromComposable,
    applyService: applyServiceFromComposable,
} = useBrowseService({
    hasServiceSelected,
    isTabRestored,
    items,
    nextCursor,
    currentPage,
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
    const result = await getNextPageFromComposable(page);

    // Collect immediate actions from the result
    if (result.immediateActions && result.immediateActions.length > 0) {
        immediateActionsToast.addActions(result.immediateActions);
    }

    return result;
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
    if (container.source === 'CivitAI' || currentTabService.value === 'civit-ai-images') {
        return container.type === 'User';
    }
    // Wallhaven: no containers are blacklistable yet
    // Add other services as needed
    return false;
}

// Handle container ban button click
function handleContainerBan(container: { id: number; type: string; source?: string; source_id?: string; referrer?: string | null }): void {
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
const containerPillInteractions = useContainerPillInteractions(
    items,
    masonry,
    props.tab?.id,
    (fileId: number, type: 'love' | 'like' | 'dislike' | 'funny') => {
        // Remove from auto-dislike queue if user reacts (any reaction cancels auto-dislike)
        // Use Map for O(1) lookup instead of O(n) findIndex
        if (autoDislikeQueue.isQueued(fileId)) {
            autoDislikeQueue.removeFromQueue(fileId);
            const item = itemsMap.value.get(fileId);
            if (item) {
                item.will_auto_dislike = false;
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
    itemsMap,
    masonry,
    computed(() => props.tab),
    (fileId: number, type: ReactionType) => {
        // Remove from auto-dislike queue if user reacts (any reaction cancels auto-dislike)
        // Use Map for O(1) lookup instead of O(n) findIndex
        if (autoDislikeQueue.isQueued(fileId)) {
            autoDislikeQueue.removeFromQueue(fileId);
            const item = itemsMap.value.get(fileId);
            if (item) {
                item.will_auto_dislike = false;
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


// Cancel masonry loading
function cancelMasonryLoad(): void {
    if (masonry.value?.isLoading && typeof masonry.value.cancelLoad === 'function') {
        masonry.value.cancelLoad();
    }
}

// Load next page manually (used by both button click and carousel load more)
async function loadNextPage(): Promise<void> {
    if (nextCursor.value !== null && masonry.value && !masonry.value.isLoading) {
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

// Event handlers for masonry items
function handleMasonryItemMouseEnter(index: number, itemId: number): void {
    hoveredItemIndex.value = index;
    hoveredItemId.value = itemId;
    if (autoDislikeQueue.isQueued(itemId)) {
        autoDislikeQueue.freezeItem(itemId);
    }
}

function handleMasonryItemMouseLeave(): void {
    const itemId = hoveredItemId.value;
    hoveredItemIndex.value = null;
    hoveredItemId.value = null;
    containerBadges.setHoveredContainerId(null);
    if (itemId && autoDislikeQueue.isQueued(itemId)) {
        autoDislikeQueue.unfreezeItem(itemId);
    }
}

async function handleItemInView(payload: { item: any; type: 'image' | 'video' }, item: MasonryItem): Promise<void> {
    // payload.item is the item passed to MasonryItem, which should have the id
    const itemId = payload.item?.id ?? item?.id;
    if (itemId) {
        // Handle preview increment when item is fully in view
        const result = await itemPreview.handleItemPreload(itemId);

        // If will_auto_dislike was newly set, add to queue
        if (result?.will_auto_dislike) {
            autoDislikeQueue.addToQueue(itemId, true); // Start active since item is in view
        }

        // Activate auto-dislike countdown when item is in view
        // This handles both moderation rules and container blacklists
        if (autoDislikeQueue.isQueued(itemId)) {
            autoDislikeQueue.activateItem(itemId);
        }
    }
}

async function handleItemPreloadSuccess(payload: { item: any; type: 'image' | 'video'; src: string }, item: MasonryItem): Promise<void> {
    // payload.item is the item passed to MasonryItem, which should have the id
    const itemId = payload.item?.id ?? item?.id;
    if (itemId) {
        // Track that this item has loaded (refs are auto-unwrapped in templates)
        loadedItemIds.value.add(itemId);
    }
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

function handlePillDismiss(container: { id: number; type: string; source?: string; source_id?: string; referrer?: string | null }): void {
    handleContainerBan(container);
}

function handlePromptDialogClick(item: MasonryItem): void {
    promptData.openPromptDialog(item);
}

function handleRemoveItem(remove: (item: MasonryItem) => void, item: MasonryItem): void {
    remove(item);
}

function handleFileReaction(itemId: number, type: ReactionType, remove: (item: MasonryItem) => void): void {
    handleMasonryReaction(itemId, type, remove);
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

// Computed property for progress bar style
function getProgressBarStyle(itemId: number): { transform: string; transformOrigin: string; width: string } {
    return {
        transform: `scaleX(${autoDislikeQueue.getProgress(itemId)})`,
        transformOrigin: 'left',
        width: '100%',
    };
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
    isMounted.value = true;

    // Fetch services if not provided via prop (fallback for when tab mounts before parent fetches)
    if (props.availableServices.length === 0) {
        await fetchServices();
    }

    if (props.tab) {
        await initializeTab(props.tab);
    }
    // Initialize virtualization after items are loaded
    await nextTick();
    itemVirtualization.initialize();
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

// Sync selectedService with currentTabService when tab changes
// This ensures the service selector shows the correct service when switching tabs
watch(
    () => currentTabService.value,
    (newService) => {
        if (newService) {
            selectedService.value = newService;
        }
    },
    { immediate: true }
);

// Track loaded item IDs to handle timing between preload:success and watch
const loadedItemIds = ref<Set<number>>(new Set());

// Computed to track queue changes for reactivity (forces re-render when queue updates)
// Accessing queuedItems ensures Vue tracks Map mutations
const queueUpdateTrigger = computed(() => {
    // Access queuedItems to ensure Vue tracks queue changes
    const items = autoDislikeQueue.queuedItems.value;
    // Return a value that changes when queue updates
    return items.length > 0 ? items.map(i => `${i.id}-${i.remaining}`).join(',') : '';
});

// Watch for will_auto_dislike flag and add to queue
watch(
    () => items.value.map((item) => ({ id: item.id, will_auto_dislike: item.will_auto_dislike })),
    (newItems, oldItems) => {
        const oldMap = new Map(oldItems?.map((i) => [i.id, i.will_auto_dislike]) ?? []);
        newItems.forEach((item) => {
            // Add to queue if will_auto_dislike is true and wasn't before
            if (item.will_auto_dislike && !oldMap.get(item.id)) {
                // Start countdown immediately for items flagged from backend
                // These items matched rules/containers with ui_countdown, so countdown should start right away
                autoDislikeQueue.addToQueue(item.id, true);

                // If this item is currently being hovered, freeze it
                if (hoveredItemId.value === item.id) {
                    autoDislikeQueue.freezeItem(item.id);
                }
            }
            // Remove from queue if will_auto_dislike is false and was true before
            else if (!item.will_auto_dislike && oldMap.get(item.id)) {
                autoDislikeQueue.removeFromQueue(item.id);
            }
        });
    },
    { deep: true, immediate: true }
);


// Cleanup on unmount
onUnmounted(() => {
    // Mark component as unmounted to prevent callbacks from accessing state
    isMounted.value = false;

    // Clear auto-dislike queue to stop any pending countdowns
    autoDislikeQueue.clearQueue();

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
    <div v-if="tab" ref="tabContentContainer" class="flex-1 min-h-0 flex flex-col relative">
        <!-- Service Selection Header -->
        <div class="px-4 py-3 border-b border-twilight-indigo-500/50 bg-prussian-blue-700/50"
            data-test="service-selection-header">
            <div class="flex items-center gap-3">
                <div class="flex-1">
                    <Select v-model="selectedService" :disabled="masonry?.isLoading ?? false">
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
                <!-- Filters Button (Primary) -->
                <BrowseFiltersSheet v-model:open="isFilterSheetOpen" v-model="selectedService"
                    :available-services="availableServices" :tab="tab" :masonry="masonry"
                    :is-masonry-loading="masonry?.isLoading ?? false" @apply="handleApplyFilters" />

                <!-- Moderation Rules Button (Info) -->
                <ModerationRulesManager :disabled="masonry?.isLoading ?? false"
                    @rules-changed="handleModerationRulesChanged" />

                <!-- Container Blacklists Button (Warning) -->
                <ContainerBlacklistManager ref="containerBlacklistManager" :disabled="masonry?.isLoading ?? false"
                    @blacklists-changed="handleModerationRulesChanged" />

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

                <!-- Reset to First Page Button -->
                <Button :disabled="(!hasServiceSelected && !resetDialog.isOnFirstPage)"
                    @click="resetDialog.openResetDialog" size="sm" variant="ghost" class="h-10 w-10" color="danger"
                    data-test="reset-to-first-page-button" title="Reset to first page">
                    <ChevronsLeft :size="14"></ChevronsLeft>
                </Button>

                <!-- Apply Service Button -->
                <Button @click="applyService" :disabled="isApplyButtonDisabled" size="sm" class="h-10 w-10"
                    data-test="apply-service-button" title="Apply selected service">
                    <Loader2 v-if="masonry?.isLoading" :size="14" class="mr-2 animate-spin" />
                    <Play :size="14" v-else />
                </Button>
            </div>
        </div>

        <!-- Masonry Content -->
        <div class="flex-1 min-h-0">
            <div v-if="tab && hasServiceSelected" class="relative h-full masonry-container" ref="masonryContainer"
                @click="onMasonryClick" @contextmenu.prevent="onMasonryClick" @mousedown="onMasonryMouseDown">
                <Masonry :key="tab?.id" ref="masonry" v-model:items="items" :get-next-page="getNextPage"
                    :initial-page="currentPage" :initial-next-page="nextCursor" :layout="layout" layout-mode="auto"
                    :mobile-breakpoint="768" :skip-initial-load="true" :backfill-enabled="true"
                    :backfill-delay-ms="2000" :backfill-max-calls="Infinity" :page-size="pageSize"
                    @backfill:start="onBackfillStart" @backfill:tick="onBackfillTick" @backfill:stop="onBackfillStop"
                    @backfill:retry-start="onBackfillRetryStart" @backfill:retry-tick="onBackfillRetryTick"
                    @backfill:retry-stop="onBackfillRetryStop" @loading:stop="onLoadingStop"
                    data-test="masonry-component">
                    <template #default="{ item, index, remove }">
                        <VibeMasonryItem :item="item" :remove="remove" :preload-threshold="0.5"
                            @mouseenter="handleMasonryItemMouseEnter(index, item.id)"
                            @mouseleave="handleMasonryItemMouseLeave"
                            @in-view="(payload: { item: any; type: 'image' | 'video' }) => handleItemInView(payload, item)"
                            @preload:success="(payload: { item: any; type: 'image' | 'video'; src: string }) => handleItemPreloadSuccess(payload, item)">
                            <template
                                #default="{ imageLoaded, imageError, videoLoaded, videoError, isLoading, showMedia, imageSrc, videoSrc, mediaType }">
                                <div class="relative w-full h-full overflow-hidden rounded-lg group masonry-item bg-prussian-blue-500"
                                    :data-key="item.key" :data-masonry-item-id="item.id"
                                    :class="containerBadges.getMasonryItemClasses.value(item)"
                                    @mousedown="(e: MouseEvent) => masonryInteractions.handleMasonryItemMouseDown(e, item)"
                                    @auxclick="(e: MouseEvent) => handleMasonryItemAuxClick(e, item)">
                                    <!-- Auto-disliked indicator overlay with smooth animation -->
                                    <Transition name="ring-fade">
                                        <div v-if="items.find(i => i.id === item.id)?.auto_disliked"
                                            class="absolute inset-0 border-2 border-red-500 pointer-events-none z-10 rounded-lg ring-fade-enter-active"
                                            style="will-change: transform, opacity;">
                                        </div>
                                    </Transition>
                                    <!-- Will auto-dislike indicator overlay (red ring for flagged items) -->
                                    <Transition name="ring-fade">
                                        <div v-if="items.find(i => i.id === item.id)?.will_auto_dislike && !items.find(i => i.id === item.id)?.auto_disliked"
                                            class="absolute inset-0 border-2 border-red-500 pointer-events-none z-10 rounded-lg ring-fade-enter-active"
                                            style="will-change: transform, opacity;">
                                        </div>
                                    </Transition>
                                    <!-- Per-item auto-dislike countdown pill (bottom center): icon | progress with timer overlay -->
                                    <Transition name="countdown-fade">
                                        <div v-if="autoDislikeQueue.isQueued(item.id)"
                                            class="absolute inset-x-0 bottom-2 flex justify-center z-20 pointer-events-none"
                                            style="will-change: opacity;">
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
                                                    <!-- Progress fill (only shows when active) - use transform for width animation -->
                                                    <!-- Access queueUpdateTrigger to ensure reactivity to queue changes -->
                                                    <div v-if="autoDislikeQueue.isActive(item.id)"
                                                        class="absolute left-0 top-0 bottom-0 bg-danger-500 transition-transform duration-100"
                                                        :style="getProgressBarStyle(item.id)"
                                                        :key="`progress-${item.id}-${queueUpdateTrigger}`">
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
                                    <!-- Note: Vibe's MasonryItem provides this, but we override for dark theme -->
                                    <div v-if="!imageLoaded && !imageError" :class="[
                                        'absolute inset-0 flex items-center justify-center transition-opacity duration-500',
                                        showMedia ? 'opacity-0 pointer-events-none' : 'opacity-100'
                                    ]">
                                        <!-- Media type indicator badge - shown BEFORE preloading starts -->
                                        <div
                                            class="w-12 h-12 rounded-full bg-prussian-blue-700/80 backdrop-blur-sm flex items-center justify-center shadow-sm border border-twilight-indigo-500/30">
                                            <Image v-if="mediaType === 'image'" :size="20"
                                                class="text-twilight-indigo-300" />
                                            <Video v-else-if="mediaType === 'video'" :size="20"
                                                class="text-twilight-indigo-300" />
                                        </div>
                                    </div>

                                    <!-- Spinner (only shown when loading/preloading) - centered vertically -->
                                    <div v-if="isLoading"
                                        class="absolute inset-0 flex items-center justify-center z-10">
                                        <div
                                            class="bg-prussian-blue-700/90 backdrop-blur-sm rounded-full px-3 py-1.5 shadow-sm border border-twilight-indigo-500/30">
                                            <Loader2 class="w-4 h-4 text-smart-blue-400 animate-spin" />
                                        </div>
                                    </div>

                                    <!-- Error state -->
                                    <div v-if="imageError"
                                        class="absolute inset-0 flex flex-col items-center justify-center bg-prussian-blue-800/50 text-twilight-indigo-300 text-sm p-4 text-center border border-twilight-indigo-500/30 rounded-lg">
                                        <Image :size="32" class="mb-2 opacity-50" />
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
                                                @dismiss="() => handlePillDismiss(container)" />
                                        </div>
                                    </div>

                                    <!-- Info badge (shows on hover, opens dialog on click) -->
                                    <div v-if="hoveredItemIndex === index && imageLoaded"
                                        class="absolute top-2 right-2 z-50 pointer-events-auto">
                                        <Button variant="ghost" size="sm"
                                            class="h-7 w-7 p-0 bg-black/50 hover:bg-black/70 text-white"
                                            @click.stop="handlePromptDialogClick(item)" aria-label="Show prompt">
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
                                            :remove-item="() => handleRemoveItem(remove, item)"
                                            @reaction="(type) => handleFileReaction(item.id, type, remove)" />
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
        </div>

        <!-- File Viewer -->
        <FileViewer ref="fileViewer" :container-ref="tabContentContainer" :masonry-container-ref="masonryContainer"
            :items="items" :has-more="nextCursor !== null" :is-loading="masonry?.isLoading ?? false"
            :on-load-more="loadNextPage" :on-reaction="props.onReaction" :remove-from-masonry="removeItemFromMasonry"
            :restore-to-masonry="restoreToMasonry" :tab-id="props.tab?.id" :masonry-instance="masonry"
            @close="() => { }" />

        <!-- Status/Pagination Info at Bottom -->
        <BrowseStatusBar :items="items" :display-page="displayPage" :next-cursor="nextCursor"
            :is-loading="masonry?.isLoading ?? false" :backfill="backfill"
            :queued-reactions-count="queuedReactions.length"
            :queued-auto-dislike-count="autoDislikeQueue.queueSize.value"
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
        <Dialog v-model="promptData.promptDialogOpen.value" @update:model-value="handlePromptDialogUpdate">
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
                    <Button variant="outline" size="sm" @click="handleCopyPromptClick" aria-label="Copy prompt">
                        <Copy :size="16" class="mr-2" />
                        Copy
                    </Button>
                    <Button v-if="promptData.currentPromptData.value" variant="outline" size="sm"
                        @click="handleTestPromptClick" aria-label="Test prompt against moderation rules">
                        <TestTube :size="16" class="mr-2" />
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
    transition: opacity 0.2s ease;
    will-change: opacity;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}

.countdown-fade-enter-active,
.countdown-fade-leave-active {
    transition: opacity 0.3s ease;
    will-change: opacity;
}

.countdown-fade-enter-from,
.countdown-fade-leave-to {
    opacity: 0;
}
</style>
