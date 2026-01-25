<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, provide, ref, shallowRef, triggerRef, watch } from 'vue';
import type { TabData, FeedItem } from '@/composables/useTabs';
import { Masonry, MasonryItem } from '@wyxos/vibe';
import type { MasonryInstance, MasonryRestoredPages, PageToken } from '@wyxos/vibe';
import {
    ChevronDown,
    Copy,
    Info,
    Loader2,
    Play,
    TestTube,
    X
} from 'lucide-vue-next';
import FileViewer from './FileViewer.vue';
import BrowseStatusBar from './BrowseStatusBar.vue';
import FileReactions from './FileReactions.vue';
import DislikeProgressBar from './DislikeProgressBar.vue';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select';
import { Switch } from '@/components/ui/switch';
import Pill from './ui/Pill.vue';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from './ui/dialog';
import { useBrowseService } from '@/composables/useBrowseService';
import { useContainerBadges } from '@/composables/useContainerBadges';
import { useContainerPillInteractions } from '@/composables/useContainerPillInteractions';
import { usePromptData } from '@/composables/usePromptData';
import { createMasonryInteractions } from '@/utils/masonryInteractions';
import { useItemPreview } from '@/composables/useItemPreview';
import { useMasonryReactionHandler } from '@/composables/useMasonryReactionHandler';
import { useAutoDislikeQueue } from '@/composables/useAutoDislikeQueue';
import { BrowseFormKey, createBrowseForm, type BrowseFormData } from '@/composables/useBrowseForm';
import type { ServiceOption } from '@/composables/useBrowseService';
import TabFilter from './TabFilter.vue';
import ModerationRulesManager from './moderation/ModerationRulesManager.vue';
import ContainerBlacklistManager from './container-blacklist/ContainerBlacklistManager.vue';
import BatchModerationToast from './toasts/BatchModerationToast.vue';
import { useToast } from 'vue-toastification';
import { show as tabsShow } from '@/actions/App/Http/Controllers/TabController';
import { index as browseIndex } from '@/actions/App/Http/Controllers/BrowseController';
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
const hoveredItemIndex = ref<number | null>(null);
const hoveredItemId = ref<number | null>(null);
const isFilterSheetOpen = ref(false);

const itemIndexById = computed(() => {
    const map = new Map<number, number>();
    for (let i = 0; i < items.value.length; i += 1) {
        const id = items.value[i]?.id;
        if (typeof id === 'number') {
            map.set(id, i);
        }
    }
    return map;
});

function getItemIndex(itemId: number): number | undefined {
    return itemIndexById.value.get(itemId);
}

const masonryRenderKey = ref(0);
const startPageToken = ref<PageToken>(1);
const restoredPages = ref<MasonryRestoredPages | null>(null);

// Track which items have successfully preloaded so overlays can gate UI like the old implementation did.
const preloadedItemIds = ref<Set<number>>(new Set());

function markItemsPreloaded(batch: FeedItem[]): void {
    const next = new Set(preloadedItemIds.value);
    for (const it of batch) {
        if (typeof it?.id === 'number') {
            next.add(it.id);
        }
    }
    preloadedItemIds.value = next;
}

function isItemPreloaded(itemId: number): boolean {
    return preloadedItemIds.value.has(itemId);
}

function hasActiveReaction(item: FeedItem): boolean {
    return Boolean(item.reaction?.type);
}

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
const itemPreview = useItemPreview(items, computed(() => tab.value));

// Browse service composable - fetch services if not provided via prop
const { availableServices: localServices, availableSources, localService, fetchServices, fetchSources } = useBrowseService();

// Use prop services if available, otherwise use local services
const availableServices = computed(() => {
    return props.availableServices.length > 0 ? props.availableServices : localServices.value;
});

function updateService(nextService: string): void {
    const defaults = availableServices.value.find((s) => s.key === nextService)?.defaults;
    form.setService(nextService, defaults);
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

const selectedService = computed({
    get: () => form.data.service,
    set: (value: string) => {
        form.data.service = value;
    },
});

const currentTabService = computed(() => {
    const fromTab = tab.value?.params?.service;
    return (typeof fromTab === 'string' && fromTab.length > 0) ? fromTab : (form.data.service || null);
});

const hasServiceSelected = computed(() => {
    // In online mode, a service must be selected.
    if (form.data.feed === 'online') {
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

const layout = {
    gutterX: 12,
    gutterY: 12,
    sizes: { base: 1, sm: 2, md: 3, lg: 4, '2xl': 10 },
};

async function getPage(page: PageToken, context?: BrowseFormData) {
    const formData = context || form.getData();
    // Canonical query contract:
    // - Online browsing: `service=<serviceKey>`
    // - Local browsing: `source=<sourceName>` (filters local files by source column)
    // Never send `source` in online mode.
    const params: Record<string, unknown> = {
        feed: formData.feed,
        tab_id: formData.tab_id,
        page,
        limit: formData.limit,
    };

    if (formData.feed === 'online') {
        params.service = formData.service;
    } else {
        params.source = formData.source;
    }

    // Flatten service-specific filters into the query params, but don't let them override
    // the envelope keys above.
    const reserved = new Set(['service', 'source', 'feed', 'tab_id', 'page', 'limit', 'serviceFilters']);
    for (const [k, v] of Object.entries(formData.serviceFilters || {})) {
        if (reserved.has(k)) {
            continue;
        }
        params[k] = v;
    }

    if (props.onUpdateTabLabel) {
        if (formData.feed === 'local' || (formData.feed === 'online' && formData.service)) {
            const serviceLabel = formData.feed === 'local'
                ? (localService.value?.label ?? 'Local')
                : (availableServices.value.find((s) => s.key === formData.service)?.label ?? formData.service);
            props.onUpdateTabLabel(`${serviceLabel} - ${String(page)}`);
        }
    }

    handleLoadingStart();
    try {
        const { data } = await window.axios.get(browseIndex.url({ query: params }));

        return {
            items: data.items || [],
            nextPage: data.nextPage,
        };
    } finally {
        // Best-effort: Masonry no longer emits loading:stop, so we stop here.
        handleLoadingStop();
    }
}

async function applyFilters() {
    // Best-effort cancel/reset: remount Masonry.
    shouldShowForm.value = false;
    form.data.page = 1;
    items.value = [];
    preloadedItemIds.value = new Set();
    restoredPages.value = null;
    startPageToken.value = 1;
    masonryRenderKey.value += 1;
    // Wait for next tick to ensure form data updates are reactive
    await nextTick();
}

async function applyService() {
    shouldShowForm.value = false;
    items.value = [];
    preloadedItemIds.value = new Set();
    restoredPages.value = null;
    startPageToken.value = 1;
    masonryRenderKey.value += 1;
}

function onMasonryClick(e: MouseEvent): void {
    // Normal click behavior - open overlay (only for left click)
    if (e.button === 0 || (e.type === 'click' && !e.button)) {
        fileViewer.value?.openFromClick(e);
    }
}

function handleMasonryItemClick(e: MouseEvent, item: FeedItem): void {
    if (e.altKey) {
        masonryInteractions.handleAltClickReaction(e, item);
        return;
    }

    // Keep the click handling local to the item so it continues to work with Vibe 2.x
    // (which no longer renders the legacy `.masonry-item` wrapper).
    e.stopPropagation();
    fileViewer.value?.openFromClick(e);
}

function handleMasonryItemContextMenu(e: MouseEvent, item: FeedItem): void {
    if (e.altKey) {
        masonryInteractions.handleAltClickReaction(e, item);
    }
}

function handleMasonryItemMouseDown(e: MouseEvent, item: FeedItem): void {
    if (e.altKey && e.button === 1) {
        masonryInteractions.handleAltClickReaction(e, item);
        return;
    }
}

function onMasonryMouseDown(e: MouseEvent): void {
    // Prevent browser scroll for middle click (without ALT) - actual opening happens on auxclick
    if (!e.altKey && e.button === 1) {
        e.preventDefault();
        e.stopPropagation();
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
    // CivitAI: 'User' type is blacklistable (case-sensitive match)
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
            referrer: container.referrer,
        });
    }
}

// Container pill interactions composable
// tabId will be set when tab loads, using computed to reactively get the current value
const containerPillInteractions = useContainerPillInteractions(
    items,
    masonry,
    computed(() => tab.value.id),
    (fileId: number, type: 'love' | 'like' | 'dislike' | 'funny') => {
        props.onReaction(fileId, type);
    },
    (container) => {
        if (!props.onOpenContainerTab || form.data.feed !== 'online' || !form.data.service) {
            return;
        }

        const serviceKey = form.data.service;
        const serviceLabel = availableServices.value.find((service) => service.key === serviceKey)?.label ?? serviceKey;
        const containerValue = container.source_id ?? container.id;

        const params: Record<string, unknown> = {
            feed: 'online',
            service: serviceKey,
            page: 1,
            limit: form.data.limit,
        };

        const reserved = new Set(['service', 'source', 'feed', 'tab_id', 'page', 'limit', 'serviceFilters']);
        for (const [k, v] of Object.entries(form.data.serviceFilters || {})) {
            if (reserved.has(k)) {
                continue;
            }
            params[k] = v;
        }

        let hasContainerFilter = false;
        if (serviceKey === 'civit-ai-images' && container.source === 'CivitAI') {
            if (container.type === 'User' && container.source_id) {
                params.username = container.source_id;
                hasContainerFilter = true;
            }
            if (container.type === 'Post' && container.source_id) {
                params.postId = container.source_id;
                hasContainerFilter = true;
            }
        }

        if (!hasContainerFilter) {
            return;
        }

        props.onOpenContainerTab({
            label: `${serviceLabel} - ${container.type}: ${containerValue}`,
            params,
        });
    }
);

// Prompt data composable
const promptData = usePromptData(items);

// Masonry reaction handler composable
const { handleMasonryReaction } = useMasonryReactionHandler(
    items,
    masonry,
    computed(() => tab.value),
    (fileId: number, type: ReactionType) => {
        props.onReaction(fileId, type);
    }
);

// Masonry interactions composable (needs handleMasonryReaction)
const masonryInteractions = createMasonryInteractions(
    items,
    masonry,
    handleMasonryReaction
);

// Handle reset event from TabFilter
function handleResetFilters(): void {
    form.reset();
}

// Handle moderation rules changed
function handleModerationRulesChanged(): void {
    // TODO: Implement moderation rules changed logic
}

// Cancel masonry loading
function cancelMasonryLoad(): void {
    masonry.value?.cancel?.();
}

// Load next page manually (used by both button click and carousel load more)
async function loadNextPage(): Promise<void> {
    await masonry.value?.loadNextPage?.();
}


// Auto-dislike queue composable
const autoDislikeQueue = useAutoDislikeQueue(items, masonry);

function findNearestVideoElement(from: EventTarget | null): HTMLVideoElement | null {
    let el = from as HTMLElement | null;
    for (let i = 0; i < 8 && el; i += 1) {
        const video = el.querySelector('video');
        if (video instanceof HTMLVideoElement) {
            return video;
        }
        el = el.parentElement;
    }
    return null;
}

// Event handlers for masonry items
function handleMasonryItemMouseEnter(e: MouseEvent, item: FeedItem): void {
    const itemId = item.id;
    const index = items.value.findIndex((i) => i.id === itemId);
    hoveredItemIndex.value = index === -1 ? null : index;
    hoveredItemId.value = itemId;

    if (item.type === 'video') {
        const video = findNearestVideoElement(e.currentTarget);
        if (video) {
            // Avoid autoplay policy issues; Atlas doesn't expose volume controls on hover previews.
            video.muted = true;
            void video.play().catch(() => {
                // Ignore playback failures (browser policy, etc.)
            });
        }
    }

    // Freeze auto-dislike queue only if hovering over an item with an active countdown
    if (autoDislikeQueue.hasActiveCountdown(itemId)) {
        autoDislikeQueue.freezeAll();
    }
}

function handleMasonryItemMouseLeave(e: MouseEvent, item: FeedItem): void {
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

    if (item.type === 'video') {
        const video = findNearestVideoElement(e.currentTarget);
        if (video && !video.paused) {
            video.pause();
        }
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

/**
 * Handle when item is both fully in view AND media is loaded.
 * This is when we should increment preview count and check for auto-dislike.
 */
async function handleItemInViewAndLoaded(item: FeedItem): Promise<void> {
    const itemId = item.id;
    if (itemId) {
        // Increment preview count when item is fully in view AND media is loaded
        const result = await itemPreview.incrementPreviewCount(itemId);

        // Check if item is already flagged for auto-dislike (from moderation rules)
        // incrementPreviewCount mutates the item in place, so item already has updated values
        const isModerationFlagged = item.will_auto_dislike === true;

        // Start countdown if:
        // 1. Item was already flagged for auto-dislike (from moderation rules) OR
        // 2. Preview count increment indicates it should be auto-disliked (from preview count threshold)
        const shouldAutoDislike = isModerationFlagged || result?.will_auto_dislike === true;

        if (shouldAutoDislike) {
            autoDislikeQueue.startAutoDislikeCountdown(itemId, item);
            // Force items array reference update to trigger Vibe's computed to re-evaluate
            // This ensures the progress bar component sees the updated will_auto_dislike value immediately
            triggerRef(items);
            await nextTick();
        }
    }
}

async function handleItemPreloaded(item: FeedItem): Promise<void> {
    // Vibe loader success is gated by intersection >= 0.5, so this matches the old intent.
    await handleItemInViewAndLoaded(item);
}

function handleBatchPreloaded(batch: FeedItem[]): void {
    markItemsPreloaded(batch);
    for (const it of batch) {
        void handleItemPreloaded(it);
    }
}

function handleBatchFailures(_payloads: Array<{ item: FeedItem; error: unknown }>): void {
    // Intentionally no-op for now; per-item errors can still show via Vibe loader UI.
    void _payloads;
}

function handleMasonryItemAuxClick(e: MouseEvent, item: FeedItem): void {
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

function handlePromptDialogClick(item: FeedItem): void {
    promptData.openPromptDialog(item);
}

function handleFileReaction(itemId: number, type: ReactionType, remove: (() => void) | ((item: FeedItem) => void), index?: number): void {
    void remove;
    // Cancel auto-dislike countdown if user reacts manually
    autoDislikeQueue.cancelAutoDislikeCountdown(itemId);
    // Note: remove parameter is kept for FileReactions component compatibility but not used here
    // Find item and index if not provided
    const itemIndex = index !== undefined ? index : items.value.findIndex((i) => i.id === itemId);
    const item = itemIndex !== -1 ? items.value[itemIndex] : items.value.find((i) => i.id === itemId);
    if (item) {
        handleMasonryReaction(item, type, itemIndex !== -1 ? itemIndex : undefined);
    }
}

function handleFileViewerReaction(itemId: number, type: ReactionType): void {
    handleFileReaction(itemId, type, () => {}, items.value.findIndex((i) => i.id === itemId));
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
    if (!props.tabId) {
        return;
    }

    const { data } = await window.axios.get(tabsShow.url(props.tabId));

    if (data.tab) {
        tab.value = data.tab;

        // reset form to default
        form.reset()

        form.syncFromTab(tab.value);

        const params = (tab.value?.params ?? {}) as Record<string, unknown>;

        const itemsToRestore = Array.isArray(data.tab.items) ? data.tab.items : [];
        const hasRestoredItems = itemsToRestore.length > 0;

        const hasMeaningfulParams = Object.keys(params).length > 0;
        const shouldRestoreUi = hasRestoredItems || hasMeaningfulParams;

        if (shouldRestoreUi) {
            // Restore items + pagination state.
            // Hide form since we're resuming a previous session/search.
            shouldShowForm.value = false;
            isTabRestored.value = hasRestoredItems;

            const savedNextToken = params.page as PageToken | null | undefined;

            items.value = itemsToRestore as FeedItem[];
            preloadedItemIds.value = new Set();

            // Vibe contract: `page` is the next token to load.
            // `restoredPages` is optional history; Vibe can resume without it when items are preloaded.
            restoredPages.value = null;
            startPageToken.value = (savedNextToken ?? 1) as PageToken;

            // Ensure Masonry is remounted after restoring state.
            masonryRenderKey.value += 1;

            // Let the DOM catch up before any follow-up work.
            await nextTick();
        }
    }

    await fetchServices();

    // Legacy migration: older tabs stored the selected online service in `params.source`.
    // If we restored an online tab with an empty `service`, upgrade it in-memory so the
    // UI and requests consistently use `service`.
    if (form.data.feed === 'online' && !form.data.service) {
        const legacyCandidate = tab.value?.params?.source;
        if (typeof legacyCandidate === 'string' && legacyCandidate.length > 0) {
            const isKnownService = availableServices.value.some((s) => s.key === legacyCandidate);
            if (isKnownService) {
                updateService(legacyCandidate);
                // Reset local-mode source to its default to avoid leaking it into UI.
                form.data.source = 'all';
            }
        }
    }

    await fetchSources();
});



// Cleanup on unmount
onUnmounted(() => {
    // cancel any in-flight masonry requests
    masonry.value?.cancel?.();
});

defineExpose({
    // Expose compatibility fields used by some Browse tests
    selectedService,
    currentTabService,
    hasServiceSelected,
    loadAtPage,
    isTabRestored,
    // Expose the per-tab form for tests/debugging
    browseForm: form,
    masonry,
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
                    <Select :model-value="form.data.feed"
                        @update:model-value="(val: string) => { form.data.feed = val as 'online' | 'local'; }"
                        :disabled="masonry?.isLoading">
                        <SelectTrigger class="w-[120px]" data-test="source-type-select-trigger">
                            <SelectValue placeholder="Online" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="online" data-test="source-type-online">Online</SelectItem>
                            <SelectItem value="local" data-test="source-type-local">Local</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <!-- Service Dropdown (only show when feed is 'online') -->
                <div v-if="form.data.feed === 'online'" class="flex-1">
                    <Select :model-value="form.data.service" @update:model-value="(v) => updateService(v as string)" :disabled="masonry?.isLoading">
                        <SelectTrigger class="w-[200px]" data-test="service-select-trigger">
                            <SelectValue placeholder="Select a service..." />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="service in availableServices.filter(s => s.key !== 'local')"
                                :key="service.key" :value="service.key" data-test="service-select-item">
                                {{ service.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <!-- Source Dropdown (only show when feed is 'local') -->
                <div v-if="form.data.feed === 'local'" class="flex-1">
                    <Select v-model="form.data.source" :disabled="masonry?.isLoading">
                        <SelectTrigger class="w-[200px]" data-test="source-select-trigger">
                            <SelectValue placeholder="Select a source..." />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="source in availableSources" :key="source" :value="source"
                                data-test="source-select-item">
                                {{ source }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <!-- Filters Button (Primary) -->
                <TabFilter v-model:open="isFilterSheetOpen" :available-services="availableServices" :local-def="localService" :masonry="masonry"
                    @reset="handleResetFilters" @apply="applyFilters" />

                <!-- Moderation Rules Button (Info) -->
                <ModerationRulesManager :disabled="masonry?.isLoading" @rules-changed="handleModerationRulesChanged" />

                <!-- Container Blacklists Button (Warning) -->
                <ContainerBlacklistManager ref="containerBlacklistManager" :disabled="masonry?.isLoading"
                    @blacklists-changed="handleModerationRulesChanged" />

                <!-- Cancel Loading Button -->
                <Button @click="cancelMasonryLoad" size="sm" variant="ghost" class="h-10 w-10" color="danger"
                    data-test="cancel-loading-button" title="Cancel loading" :disabled="!masonry?.isLoading">
                    <X :size="14" />
                </Button>

                <!-- Load Next Page Button -->
                <Button @click="loadNextPage" size="sm" variant="ghost" class="h-10 w-10"
                    data-test="load-next-page-button" title="Load next page"
                    :disabled="masonry?.isLoading || masonry?.hasReachedEnd">
                    <ChevronDown :size="14" />
                </Button>

                <!-- Apply Service Button -->
                <Button @click="applyService" size="sm" class="h-10 w-10" data-test="apply-service-button"
                    :loading="masonry?.isLoading"
                    :disabled="masonry?.isLoading || (form.data.feed === 'online' && !form.data.service)"
                    title="Apply selected service">
                    <Play :size="14" />
                </Button>
            </div>
        </div>

        <!-- Masonry Content -->
        <div class="flex-1 min-h-0 overflow-hidden">
            <!-- Masonry -->
            <div class="relative flex h-full min-h-0 flex-col overflow-hidden masonry-container" ref="masonryContainer" @click="onMasonryClick"
                @contextmenu.prevent="onMasonryClick" @mousedown="onMasonryMouseDown">

                <div v-if="shouldShowForm" class="flex items-center justify-center h-full" data-test="new-tab-form">
                    <div
                        class="flex flex-col items-center gap-4 p-8 bg-prussian-blue-700/50 rounded-lg border border-twilight-indigo-500/30 max-w-md w-full">
                        <h2 class="text-xl font-semibold text-twilight-indigo-100 mb-2">Start Browsing</h2>
                        <p class="text-sm text-twilight-indigo-300 mb-6 text-center">Select a service and click play to begin</p>

                        <!-- Online/Local Switch -->
                        <div class="w-full flex items-center justify-between">
                            <label class="block text-sm font-medium text-twilight-indigo-200">Source</label>
                            <div class="flex items-center gap-3">
                                <span class="text-sm text-twilight-indigo-300"
                                    :class="{ 'text-twilight-indigo-100 font-medium': !form.isLocalMode.value }">Online</span>
                                <Switch :model-value="form.isLocalMode.value"
                                    @update:model-value="(val: boolean) => form.isLocalMode.value = val"
                                    data-test="source-type-switch" />
                                <span class="text-sm text-twilight-indigo-300"
                                    :class="{ 'text-twilight-indigo-100 font-medium': form.isLocalMode.value }">Local</span>
                            </div>
                        </div>

                        <!-- Service Dropdown (only show when Online) -->
                        <div v-if="form.data.feed === 'online'" class="w-full">
                            <label class="block text-sm font-medium text-twilight-indigo-200 mb-2">Service</label>
                            <Select :model-value="form.data.service" @update:model-value="(v) => updateService(v as string)" :disabled="masonry?.isLoading">
                                <SelectTrigger class="w-full" data-test="service-select-trigger">
                                    <SelectValue placeholder="Select a service..." />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="service in availableServices.filter(s => s.key !== 'local')"
                                        :key="service.key" :value="service.key" data-test="service-select-item">
                                        {{ service.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <!-- Source Dropdown (only show when Local) -->
                        <div v-if="form.data.feed === 'local'" class="w-full">
                            <label class="block text-sm font-medium text-twilight-indigo-200 mb-2">Source</label>
                            <Select v-model="form.data.source" :disabled="masonry?.isLoading">
                                <SelectTrigger class="w-full" data-test="source-select-trigger">
                                    <SelectValue placeholder="Select a source..." />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="source in availableSources" :key="source" :value="source"
                                        data-test="source-select-item">
                                        {{ source }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="flex gap-3 w-full mt-2 items-center">
                            <Button @click="applyService" size="sm" class="flex-1" data-test="play-button"
                                :disabled="form.data.feed === 'online' && !form.data.service">
                                <Play :size="16" />
                            </Button>
                        </div>
                    </div>
                </div>

                <Masonry v-else :key="`${tab.id}-${masonryRenderKey}`" ref="masonry" v-model:items="items"
                    class="min-h-0 flex-1 !mt-0 !py-0 !border-0"
                     :mode="form.isLocalMode.value ? 'default' : 'backfill'"
                    :get-content="getPage" :page="startPageToken" :restored-pages="restoredPages ?? undefined"
                    :page-size="Number(form.data.limit)"
                    :gap-x="layout.gutterX" :gap-y="layout.gutterY"
                    @preloaded="handleBatchPreloaded" @failures="handleBatchFailures" data-test="masonry-component">
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
                                            @reaction="(type) => handleFileReaction((item as FeedItem).id as number, type, remove)" />
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
            :visible="tab !== null && tab !== undefined && !shouldShowForm" />

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
