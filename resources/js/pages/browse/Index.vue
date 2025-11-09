<script setup lang="ts">
import * as BrowseController from '@/actions/App/Http/Controllers/BrowseController';
import SectionHeader from '@/components/audio/SectionHeader.vue';
import GridItem from '@/components/browse/GridItem.vue';
import ModerationRulesManager from '@/components/moderation/ModerationRulesManager.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogDescription, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import ContentLayout from '@/layouts/ContentLayout.vue';
import ScrollableLayout from '@/layouts/ScrollableLayout.vue';
import { bus } from '@/lib/bus';
import { enqueueModeration, flushModeration } from '@/lib/moderation';
import { undoManager } from '@/lib/undo';
import FullSizeViewer from '@/pages/browse/FullSizeViewer.vue';
import { IO_VISIBILITY_ROOT_MARGIN, IO_VISIBILITY_THRESHOLD } from '@/lib/visibility';
import { type BreadcrumbItem } from '@/types';
import type { BrowseItem } from '@/types/browse';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Masonry } from '@wyxos/vibe';
import axios from 'axios';
import { Check, ChevronsLeft, ChevronsRight, Hash, List as ListIcon, Loader2, Search, X, RefreshCw } from 'lucide-vue-next';
import { computed, markRaw, nextTick, onBeforeUnmount, onMounted, provide, reactive, ref, shallowRef, watch } from 'vue';
import { createBrowseGetPage } from './useBrowsePaging';

const props = defineProps({
    files: {
        type: Array as () => any[],
        required: true,
    },
    filter: {
        type: Object as () => Record<string, any>,
        required: true,
    },
    services: {
        type: Array as () => Array<{ key: string; label: string; defaults?: Record<string, any> }>,
        required: false,
        default: () => [],
    },
});

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Browse', href: '/browse' }];

const items = shallowRef<BrowseItem[]>([]);

// Precomputed container counts to avoid O(n^2) scans in each GridItem
// Map<key, Map<value, count>>
const containerCounts = reactive(new Map<string, Map<string | number, number>>());
provide('browse-container-counts', containerCounts as unknown as Map<string, Map<string | number, number>>);

function recomputeContainerCounts(list: any[]) {
    const counts = new Map<string, Map<string | number, number>>();
    for (const it of Array.isArray(list) ? list : []) {
        const containers = ((it?.containers || []) as any[]).filter(Boolean);
        for (const c of containers) {
            const k = String(c?.key ?? '');
            if (!k) continue;
            const v = c?.value as string | number | null | undefined;
            if (v == null) continue;
            let inner = counts.get(k);
            if (!inner) { inner = new Map(); counts.set(k, inner); }
            inner.set(v, (inner.get(v) ?? 0) + 1);
        }
    }
    // Replace reactive map contents
    containerCounts.clear();
    for (const [k, inner] of counts) {
        const copy = new Map<string | number, number>();
        for (const [v, n] of inner) copy.set(v, n);
        containerCounts.set(k, copy);
    }
}
// current/next live on the form (filter); do not duplicate state here.

// useForm to keep filters consistent with backend (mirror server filter)
const form = useForm({ type: null, ...(props.filter || {}) });

const serviceList = computed(() => {
    const arr = (props as any).services || [];
    return Array.isArray(arr) ? arr.map((s: any) => ({ label: s.label, value: s.key })) : [];
});
const serviceDefaultsMap = computed<Record<string, any>>(() => {
    const map: Record<string, any> = {};
    const arr = (props as any).services || [];
    if (Array.isArray(arr)) {
        for (const s of arr) {
            map[s.key] = s.defaults || {};
        }
    }
    return map;
});

// totalItems derived from scroller; no manual watch needed.

const currentSource = ref<any>(form.source as any);

const selectedType = computed({
    get: () => ((form.type as any) === 'image' || (form.type as any) === 'video') ? (form.type as any) : 'all',
    set: (value: string) => {
        form.type = value === 'image' || value === 'video' ? (value as any) : null;
    },
});

function snapshotFilters(): string {
    const data = form.data() as Record<string, any>;
    return JSON.stringify({
        source: data.source ?? null,
        nsfw: Number(data.nsfw ?? 0) === 1 ? 1 : 0,
        sort: data.sort ?? null,
        limit: Number(data.limit ?? 20) || 20,
        type: (data.type === 'image' || data.type === 'video') ? data.type : null,
    });
}

const appliedFilterSnapshot = ref(snapshotFilters());
const filtersBusy = ref(false);
const filtersDirty = computed(() => snapshotFilters() !== appliedFilterSnapshot.value);

async function applyFilters() {
    if (filtersBusy.value) return;
    filtersBusy.value = true;
    try {
    // Reset filter via form and trigger scroller to fetch next
    const selectedSource = form.source as any;
    if (selectedSource !== currentSource.value) {
        const defaults = serviceDefaultsMap.value[selectedSource] || {};
        form.defaults({ ...defaults, source: selectedSource, page: 1, next: null });
        form.reset();
        currentSource.value = selectedSource;
    } else {
        form.defaults({ ...form.data(), page: 1, next: null });
        form.reset();
    }

    if (scroller.value?.reset) {
        scroller.value.reset();
    }
    if (scroller.value?.loadPage) {
        await scroller.value.loadPage(1);
    } else if (scroller.value?.loadNext) {
        // Fallback for older Masonry API
        await scroller.value.loadNext();
    }
        appliedFilterSnapshot.value = snapshotFilters();
    } finally {
        filtersBusy.value = false;
    }
}

const scroller = ref<any>(null);
// Gate Masonry backfill during interactions to reduce timer-driven work
const backfillEnabled = ref(true);

// Coalesced removal with RAF and backfill gating
const pendingRemovalIds = new Set<number>();
let removalRafScheduled = false;
function scheduleRemoveItem(file: any) {
    const id = file?.id as number | undefined;
    if (!id) return;
    pendingRemovalIds.add(id);
    // Pause backfill immediately
    backfillEnabled.value = false;
    if (removalRafScheduled) return;
    removalRafScheduled = true;
    requestAnimationFrame(async () => {
        try {
            // Build batch from current items to maintain snapshot order
            const list = (items.value || []).filter((it: any) => pendingRemovalIds.has(it?.id));
            const totalBefore = (items.value || []).length;
            const isAll = list.length > 0 && list.length === totalBefore;
            pendingRemovalIds.clear();
            if (list.length > 0) {
                // If removing all, use removeAll so Masonry does NOT auto-refresh; we'll advance to next page via @remove-all:complete
                if (isAll) {
                    try { await scroller.value?.removeAll?.(); } catch {}
                } else {
                    try { await scroller.value?.removeMany?.(list); } catch { try { for (const it of list) await scroller.value?.remove?.(it); } catch {} }
                }
                await nextTick();
                // Advance dialog item if open and current removed
                if (dialogOpen.value) {
                    const current = dialogItem.value;
                    const idx = list.findIndex((x: any) => x?.id === current?.id);
                    if (idx >= 0) {
                        const indexInItems = items.value.findIndex((x: any) => x?.id === current?.id);
                        const nextItem = items.value[indexInItems] || items.value[indexInItems + 1] || items.value[0] || null;
                        dialogItem.value = nextItem;
                        if (!nextItem) dialogOpen.value = false;
                    }
                }
                // Note: When removing all in Browse, next page is loaded (see @remove-all:complete)
            }
        } finally {
            removalRafScheduled = false;
            // Resume backfill after 200ms
            setTimeout(() => { backfillEnabled.value = true; }, 200);
        }
    });
}

// Stable masonry layout object (avoid new object each render)
const masonryLayout = { sizes: { base: 1, sm: 2, md: 3, lg: 4, xl: 5, '2xl': 10 }, header: 40, footer: 40 } as const;

// ---------- Moderation rules modal state ----------
// Provide context to children like GridItem for batch operations
provide('browse-items', items);
provide('browse-scroller', scroller);

// Frame-coalesced layout refresh for undo/apply UI mutations
let masonryRefreshPending = false;
function scheduleMasonryRefresh() {
    if (masonryRefreshPending) return;
    masonryRefreshPending = true;
    queueMicrotask(() => {
        requestAnimationFrame(() => {
            try {
                scroller.value?.refreshLayout?.(items.value);
            } finally {
                masonryRefreshPending = false;
            }
        });
    });
}
provide('browse-schedule-refresh', scheduleMasonryRefresh);

// Manual refresh function for the refresh button
// Note: Browse maintains "load next" behavior when all items are removed (default Vibe behavior)
// For automatic page refresh on empty, see Photos Index which uses refreshOnEmpty=true

async function refreshCurrentPage() {
    try {
        if (scroller.value?.refreshCurrentPage) {
            await scroller.value.refreshCurrentPage();
        }
    } catch {}
}


// Shared IntersectionObserver for grid items (one per grid)
// Registry maps element -> onVisible handler for item-local reactions
const ioRegistry = new WeakMap<Element, () => void>();
// Fallback stack for environments/tests where entries may not include target
const pendingFns: Array<() => void> = [];
const browseIO = new IntersectionObserver(
    (entries) => {
        for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            const tgt = (entry as any).target as Element | undefined;
            if (tgt) {
                const fn = ioRegistry.get(tgt);
                if (fn) {
                    try { fn(); } catch {}
                }
                try { browseIO.unobserve(tgt); } catch {}
                ioRegistry.delete(tgt);
            } else {
                // No target provided (test polyfills) — call the most recently registered fn
                const fn = pendingFns.pop();
                if (fn) {
                    try { fn(); } catch {}
                }
            }
        }
    },
    { root: null, rootMargin: IO_VISIBILITY_ROOT_MARGIN, threshold: IO_VISIBILITY_THRESHOLD },
);
provide('browse-io', {
    observer: browseIO,
    register(el: Element, onVisible: () => void) {
        ioRegistry.set(el, onVisible);
        pendingFns.push(onVisible);
        browseIO.observe(el);
    },
    unregister(el: Element) {
        ioRegistry.delete(el);
        try { browseIO.unobserve(el); } catch {}
    },
});

// Backfill progress state driven by Masonry events
const backfill = reactive({
    active: false,
    fetched: 0,
    target: 0,
    calls: 0,
    // normal fill wait (between successive successful calls)
    waiting: false,
    waitTotalMs: 0,
    waitRemainingMs: 0,
    // retry wait state
    retryActive: false,
    retryAttempt: 0,
    retryMax: 3,
    retryWaitTotalMs: 0,
    retryWaitRemainingMs: 0,
});

const isLoading = computed(() => !!scroller.value?.isLoading || backfill.active || backfill.waiting || backfill.retryActive);

// Provide getPage (single-page fetcher)
const getPage = createBrowseGetPage(form);
const totalItems = computed(() => scroller.value?.totalItems ?? items.value.length);
// Keep counts roughly in sync; coalesce recomputes to the next frame to avoid thrash on bursts
let countsRecomputePending = false;
function scheduleContainerCountRecompute() {
    if (countsRecomputePending) return;
    countsRecomputePending = true;
    requestAnimationFrame(() => {
        try { recomputeContainerCounts(items.value); } finally { countsRecomputePending = false; }
    });
}
watch(() => items.value.length, () => { try { scheduleContainerCountRecompute(); } catch {} });

type ReactionKind = 'love' | 'like' | 'dislike' | 'funny';

// local reaction state per id so buttons can reflect instantly
const reactionsById = reactive<Record<number, { loved?: boolean; liked?: boolean; disliked?: boolean; funny?: boolean }>>({});

function getReactionFile(item: any) {
    const r = reactionsById[item?.id] || {};
    return {
        id: item?.id,
        loved: (r.loved ?? item?.loved ?? false) as boolean,
        liked: (r.liked ?? item?.liked ?? false) as boolean,
        disliked: (r.disliked ?? item?.disliked ?? false) as boolean,
        funny: (r.funny ?? item?.funny ?? false) as boolean,
    };
}

function computePrevType(item: any): ReactionKind | null {
    if (item?.loved) return 'love';
    if (item?.liked) return 'like';
    if (item?.disliked) return 'dislike';
    if (item?.funny) return 'funny';
    return null;
}

async function handleReactFlow(file: any, type: ReactionKind, event?: Event) {
    event?.stopPropagation?.();
    const id = file?.id;
    if (!id) return;

    // Prepare undo action first so tests can spy and capture label
    const currentIdx = items.value.findIndex((candidate) => candidate.id === id);
    const prevType = computePrevType(file);
    const removedIndex = currentIdx;
    const snapshot = { ...file };
    undoManager.push({
        label: `${type.charAt(0).toUpperCase() + type.slice(1)} 1 item`,
        previews: [snapshot?.preview || snapshot?.thumbnail_url || ''].filter(Boolean) as string[],
        previewTitles: [snapshot?.title || ''].filter(Boolean) as string[],
        applyUI: () => {},
        revertUI: () => {
            reactionsById[id] = {
                loved: prevType === 'love',
                liked: prevType === 'like',
                disliked: prevType === 'dislike',
                funny: prevType === 'funny',
            };
            void nextTick().then(() => {
                const idx = Math.max(0, Math.min(removedIndex, items.value.length));
                items.value.splice(idx, 0, snapshot);
                try { scroller.value?.refreshLayout?.(items.value) } catch {}
            });
        },
        do: async () => Promise.resolve(),
        undo: async () => {
            try {
                const reactAction = (BrowseController as any).react({ file: id });
                if (prevType === null) {
                    await axios.post(reactAction.url, { type, state: false });
                } else {
                    await axios.post(reactAction.url, { type: prevType, state: true });
                }
            } catch {}
        },
    });

    // Network first; schedule removal to coalesce and gate backfill
    scheduleRemoveItem(file);

    try {
        const action = type === 'dislike'
            ? (BrowseController as any).dislikeBlacklist({ file: id })
            : (BrowseController as any).reactDownload({ file: id });
        await axios.post(action.url, type === 'dislike' ? {} : { type });
    } catch {}

    // If modal open, advance to next or load more then show first
    if (dialogOpen.value) {
        await nextTick();
        const idx = currentIdx < 0 ? 0 : currentIdx;
        let nextItem: any | null = items.value[idx] ?? items.value[idx + 1] ?? null;
        if (!nextItem) {
            try {
                if (scroller.value?.loadNext) {
                    await scroller.value.loadNext();
                }
            } catch {}
            await nextTick();
            nextItem = items.value[0] ?? null;
        }
        if (nextItem) {
            dialogItem.value = nextItem;
        } else {
            dialogOpen.value = false;
            dialogItem.value = null;
        }
    }
}

function onFavorite(file: any, event: Event) {
    void handleReactFlow(file, 'love', event);
}
function onLike(file: any, event: Event) {
    void handleReactFlow(file, 'like', event);
}
function onFunny(file: any, event: Event) {
    void handleReactFlow(file, 'funny', event);
}

function onDislike(file: any, event: Event) {
    void handleReactFlow(file, 'dislike', event);
}

const dialogOpen = ref(false);
const dialogItem = ref<any | null>(null);
function openImage(item: any) {
    dialogItem.value = item ?? null;
    dialogOpen.value = !!item;
}

async function resetToFirstPage() {
    try {
        // Reset form pagination and cursor
        form.defaults({ ...form.data(), page: 1, next: null });
        form.reset();
        // Reset scroller and load first page explicitly
        if (scroller.value?.reset) scroller.value.reset();
        if (scroller.value?.loadPage) await scroller.value.loadPage(1);
        else if (scroller.value?.loadNext) await scroller.value.loadNext();
    } catch (e) {
        console.warn('Failed to reset to first page:', e);
    }
}

let onBrowseRefresh: ((...args: any[]) => any) | null = null;
onMounted(async () => {
    // Allow external triggers to refresh the list (e.g., undo moderation)
    onBrowseRefresh = async () => {
        await resetToFirstPage();
    };
    bus.on('browse:refresh', onBrowseRefresh as any);
    if (!scroller.value) return;
    // Seed initial items from server and cursor state (normalize heavy fields)
const normalizeItem = (raw: any) => {
        const normalized: any = { ...raw };
        if (normalized && normalized.metadata) normalized.metadata = markRaw(normalized.metadata);
        if (Array.isArray(normalized?.containers)) normalized.containers = markRaw(normalized.containers);
        if (normalized && normalized.listing_metadata) normalized.listing_metadata = markRaw(normalized.listing_metadata);
        if (normalized && normalized.detail_metadata) normalized.detail_metadata = markRaw(normalized.detail_metadata);
        return normalized;
    };
    const initial = Array.isArray(props.files) ? (props.files as any[]).map(normalizeItem) : [];
    scroller.value.init(initial, props.filter.page, props.filter.next ?? null);
    // Precompute container counts for initial list
    recomputeContainerCounts(items.value);
    await nextTick();

    // Initial moderation toast from first payload (if present)
    try {
        const m = (usePage() as any).props?.moderation || (props as any)?.moderation || null;
        const ids = Array.isArray(m?.ids) ? m.ids : [];
        if (ids.length) {
            const previews = Array.isArray(m?.previews)
                ? m.previews
                      .map((p: any) => p?.preview || '')
                      .filter(Boolean)
                      .slice(0, 4)
                : [];
            const titles = Array.isArray(m?.previews)
                ? m.previews
                      .map((p: any) => p?.title || '')
                      .filter(Boolean)
                      .slice(0, 4)
                : [];
            enqueueModeration(ids, previews, titles);
            // Flush soon to avoid long delay on first paint
            setTimeout(() => {
                try {
                    flushModeration();
                } catch {}
            }, 200);
        }
    } catch {}

    // If initial items are empty or below the requested limit, auto load next
    const limit = Number((form.limit as any) ?? 20) || 20;
    const count = Array.isArray(items.value) ? items.value.length : 0;
    if (count < limit && typeof scroller.value.loadNext === 'function') {
        try {
            await scroller.value.loadNext();
        } catch {
            // ignore auto-load errors on first paint
        }
    }
});

// Full-size viewer moved to component; no global listeners needed here.
// Cleanup bus listeners
onBeforeUnmount(() => {
    try {
        if (onBrowseRefresh) {
            bus.off('browse:refresh', onBrowseRefresh as any);
        }
    } catch {}
});
// Masonry backfill/retry event handlers

// Batch CTA: dislike all shown items and auto-load next page
const batchBusy = ref(false);
const batchConfirmOpen = ref(false);
function openBatchConfirm() { batchConfirmOpen.value = true; }
async function confirmBatchBlacklist() {
    if (batchBusy.value) return;
    batchConfirmOpen.value = false;
    await nextTick();
    await batchDislikeVisible();
}

// Batch dislike function for visible items
async function batchDislikeVisible() {
    if (batchBusy.value) return;
    const list = Array.isArray(items.value) ? (items.value as any[]) : [];
    if (!list.length) return;

    // Proceed with batch using themed confirmation dialog upstream

    batchBusy.value = true;
    try {
        // Prepare snapshots
        const previousList = list.slice();
        const snapshotEntries = previousList.map((item, index) => ({
            id: item?.id,
            index,
            prevType: computePrevType(item),
            snapshot: { ...item },
        }));
        const ids = snapshotEntries.map((e) => e.id).filter((x) => typeof x === 'number');

        // Optimistic removal using removeAll (scrolls to top, removes all items, recalculates height)
        try {
            await scroller.value?.removeAll?.();
        } catch {}
        await nextTick();

        // Kick off server request and next page load concurrently
        try {
            const actionDescriptor = (BrowseController as any).batchReact({});
            void axios.post(actionDescriptor.url, { ids, type: 'dislike' }).catch(() => {});
        } catch {}
        try {
            await scroller.value?.loadNext?.();
        } catch {}

        // Undo support
        undoManager.push({
            label: `Blacklisted ${ids.length} item${ids.length > 1 ? 's' : ''}`,
            previews: snapshotEntries
                .slice(0, 4)
                .map((s) => s.snapshot?.preview || s.snapshot?.thumbnail_url || '')
                .filter(Boolean),
            previewTitles: snapshotEntries
                .slice(0, 4)
                .map((s) => s.snapshot?.title || '')
                .filter(Boolean),
            applyUI: () => {},
            revertUI: () => {
                const dst = (items.value || []) as any[];
                for (const s of snapshotEntries.sort((a, b) => a.index - b.index)) {
                    const idx = Math.max(0, Math.min(s.index, dst.length));
                    dst.splice(idx, 0, s.snapshot);
                }
                scheduleMasonryRefresh();
            },
            do: async () => Promise.resolve(),
            undo: async () => {
                try {
                    const unblacklist = (BrowseController as any).batchUnblacklist({});
                    await axios.post(unblacklist.url, { ids });
                } catch {}
                try {
                    // Restore previous reactions
                    for (const s of snapshotEntries) {
                        const reactAction = (BrowseController as any).react({ file: s.id });
                        if (s.prevType === null) {
                            await axios.post(reactAction.url, { type: 'dislike', state: false });
                        } else {
                            await axios.post(reactAction.url, { type: s.prevType, state: true });
                        }
                    }
                } catch {}
            },
        });
    } finally {
        batchBusy.value = false;
    }
}

function onBackfillStart(payload: { target: number; fetched: number; calls?: number }) {
    backfill.active = true;
    backfill.target = payload.target;
    backfill.fetched = payload.fetched;
    backfill.calls = payload.calls ?? 0;
    backfill.waiting = false;
}
function onBackfillTick(payload: { fetched: number; target: number; calls?: number; remainingMs: number; totalMs: number }) {
    backfill.active = true;
    backfill.fetched = payload.fetched;
    backfill.target = payload.target;
    backfill.calls = payload.calls ?? backfill.calls;
    backfill.waiting = true;
    backfill.waitRemainingMs = payload.remainingMs;
    backfill.waitTotalMs = payload.totalMs;
}
function onBackfillStop(payload: { fetched?: number; calls?: number }) {
    backfill.active = false;
    backfill.waiting = false;
    if (payload.fetched != null) backfill.fetched = payload.fetched;
    if (payload.calls != null) backfill.calls = payload.calls;
    backfill.waitRemainingMs = 0;
    backfill.waitTotalMs = 0;
    try {
        flushModeration();
    } catch {}
}
// eslint-disable-next-line @typescript-eslint/no-unused-vars
function onRetryStart(payload: { attempt: number; max: number; totalMs: number }) {
    backfill.retryActive = true;
    backfill.retryAttempt = payload.attempt;
    backfill.retryMax = payload.max;
    backfill.retryWaitTotalMs = payload.totalMs;
    backfill.retryWaitRemainingMs = payload.totalMs;
}
 
function onRetryTick(payload: { attempt: number; remainingMs: number; totalMs: number }) {
    backfill.retryActive = true;
    backfill.retryAttempt = payload.attempt;
    backfill.retryWaitRemainingMs = payload.remainingMs;
    backfill.retryWaitTotalMs = payload.totalMs;
}
 
function onRetryStop() {
    backfill.retryActive = false;
    backfill.retryAttempt = 0;
    backfill.retryWaitTotalMs = 0;
    backfill.retryWaitRemainingMs = 0;
}

defineExpose({
    applyFilters,
    filtersDirty,
    filtersBusy,
    form,
});
</script>

<template>
    <Head title="Browse" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <ContentLayout>
            <SectionHeader title="Browse" :icon="Search" />
            <div class="mb-3 flex flex-wrap items-end gap-3">
                <div class="grid gap-1">
                    <Label class="text-xs text-muted-foreground">Source</Label>
                    <select class="h-9 rounded-md border px-2 text-sm dark:bg-neutral-900" v-model="form.source as any">
                        <option v-for="s in serviceList" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                </div>

                <div class="grid gap-1">
                    <Label class="text-xs text-muted-foreground" for="nsfw-toggle">NSFW</Label>
                    <div class="flex h-9 items-center">
                        <input
                            id="nsfw-toggle"
                            type="checkbox"
                            :true-value="1"
                            :false-value="0"
                            v-model="form.nsfw as any"
                            class="h-4 w-4 rounded border dark:bg-neutral-900"
                        />
                    </div>
                </div>

                <div class="grid gap-1">
                    <Label class="text-xs text-muted-foreground">Type</Label>
                    <div class="flex h-9 items-center gap-4">
                        <label class="flex items-center gap-2 text-sm font-medium text-foreground cursor-pointer">
                            <input
                                type="radio"
                                name="browse-type"
                                value="all"
                                v-model="selectedType"
                                class="h-4 w-4 border dark:bg-neutral-900"
                                data-testid="type-option-all"
                            />
                            <span>All</span>
                        </label>
                        <label class="flex items-center gap-2 text-sm font-medium text-foreground cursor-pointer">
                            <input
                                type="radio"
                                name="browse-type"
                                value="image"
                                v-model="selectedType"
                                class="h-4 w-4 border dark:bg-neutral-900"
                                data-testid="type-option-image"
                            />
                            <span>Image</span>
                        </label>
                        <label class="flex items-center gap-2 text-sm font-medium text-foreground cursor-pointer">
                            <input
                                type="radio"
                                name="browse-type"
                                value="video"
                                v-model="selectedType"
                                class="h-4 w-4 border dark:bg-neutral-900"
                                data-testid="type-option-video"
                            />
                            <span>Video</span>
                        </label>
                    </div>
                </div>

                <div class="grid gap-1">
                    <Label class="text-xs text-muted-foreground">Sort</Label>
                    <select class="h-9 rounded-md border px-2 text-sm dark:bg-neutral-900" v-model="form.sort as any">
                        <option value="Newest">Newest</option>
                        <option value="Most Reactions">Most Reactions</option>
                        <option value="Most Buzz">Most Buzz</option>
                        <!-- Wallhaven-compatible sorting (UI only; mapping handled in service defaults) -->
                        <option value="date_added">date_added</option>
                        <option value="relevance">relevance</option>
                        <option value="random">random</option>
                        <option value="views">views</option>
                        <option value="favorites">favorites</option>
                        <option value="toplist">toplist</option>
                    </select>
                </div>

                <div class="grid gap-1">
                    <Label class="text-xs text-muted-foreground">Limit</Label>
                    <select class="h-9 rounded-md border px-2 text-sm dark:bg-neutral-900" v-model.number="form.limit as any">
                        <option :value="20">20</option>
                        <option :value="40">40</option>
                        <option :value="60">60</option>
                        <option :value="100">100</option>
                        <option :value="200">200</option>
                    </select>
                </div>

                <Button
                    class="h-9 px-4 cursor-pointer"
                    :disabled="filtersBusy || isLoading || !filtersDirty"
                    @click="() => applyFilters()"
                >
                    Apply
                </Button>

                <Button variant="outline" :disabled="isLoading" @click="() => scroller.loadNext()" class="h-9 w-9 p-0 cursor-pointer" aria-label="Load more">
                    <ChevronsRight :size="18" />
                </Button>
                <Button variant="outline" :disabled="isLoading" @click="refreshCurrentPage" class="h-9 w-9 p-0 cursor-pointer" aria-label="Refresh current page" title="Refresh current page">
                    <RefreshCw :size="18" />
                </Button>
                <Button variant="secondary" :disabled="isLoading" @click="resetToFirstPage" class="h-9 w-9 p-0 cursor-pointer" aria-label="First page">
                    <ChevronsLeft :size="18" />
                </Button>

                <!-- Cancel Loading CTA -->
                <Button
                    v-if="isLoading"
                    variant="destructive"
                    class="h-9 px-3 cursor-pointer"
                    @click="() => { scroller?.cancelLoad?.(); }"
                    title="Cancel current loading operation"
                    data-testid="cancel-loading-cta"
                >
                    <X :size="16" class="mr-1" />
                    Cancel
                </Button>

                <ModerationRulesManager
                    :disabled="isLoading"
                    :nsfw="!!(form.nsfw as any)"
                    button-class="h-9 px-3 cursor-pointer"
                />

                <!-- Batch Dislike CTA -->
                <Button
                    variant="destructive"
                    class="h-9 px-3 cursor-pointer"
                    :disabled="batchBusy || isLoading || ((items || []).length === 0)"
                    @click="openBatchConfirm"
                    title="Blacklist all currently shown items"
                    data-testid="batch-blacklist-cta"
                >
                    Blacklist all shown
                </Button>
            </div>

            <ScrollableLayout class="flex flex-col">
                <Masonry
                    class="p-1"
                    v-model:items="items"
                    :get-next-page="getPage"
                    :skip-initial-load="true"
                    :page-size="Number(form.limit as any) || 20"
                    ref="scroller"
                    :layout="masonryLayout"
                    :backfill-enabled="backfillEnabled"
                    :backfill-delay-ms="2000"
                    :backfill-max-calls="Infinity"
                    :retry-max-attempts="3"
                    :retry-initial-delay-ms="2000"
                    :retry-backoff-step-ms="2000"
                    @backfill:start="onBackfillStart"
                    @backfill:tick="onBackfillTick"
                    @backfill:stop="onBackfillStop"
                    @retry:tick="onRetryTick"
                    @retry:stop="onRetryStop"
                    @remove-all:complete="() => scroller?.loadNext?.()"
                >
                    <template #item="{ item }">
                        <GridItem
                            :key="item?.id ?? Math.random()"
                            :item="item"
                            :file-for-reactions="getReactionFile(item)"
                            @open="openImage"
                            @favorite="onFavorite"
                            @like="onLike"
                            @dislike="onDislike"
                            @laughed-at="onFunny"
                        />
                    </template>
                </Masonry>
            </ScrollableLayout>

            <div class="mt-4 flex flex-wrap gap-6 text-muted-foreground">
                <div class="flex items-center gap-2">
                    <Hash :size="24" />
                    <span class="font-semibold">current</span>
                    <span class="font-medium text-foreground">{{ form.page ?? 1 }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <ChevronsRight :size="24" />
                    <span class="font-semibold">next</span>
                    <span class="font-medium text-foreground">{{ form.next ?? form.page ?? '—' }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <ListIcon :size="24" />
                    <span class="font-semibold">total</span>
                    <span class="font-medium text-foreground">{{ totalItems }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <Loader2 v-if="isLoading" :size="24" class="animate-spin text-red-500" />
                    <Check v-else :size="24" class="text-green-500" />
                    <span class="font-semibold">status</span>
                    <span class="font-medium text-foreground" :class="[isLoading ? 'text-red-500' : 'text-green-500']">{{
                        isLoading ? 'loading' : 'ready'
                    }}</span>
                </div>
                <div v-if="backfill.active" class="flex items-center gap-2">
                    <Loader2 :size="24" class="animate-spin text-amber-500" />
                    <span class="font-semibold">filling</span>
                    <span class="font-medium text-foreground">{{ backfill.fetched }} / {{ backfill.target }} ({{ backfill.calls }} calls)</span>
                </div>
                <div v-if="backfill.waiting" class="flex min-w-[220px] items-center gap-2">
                    <Loader2 :size="24" class="animate-spin text-amber-500" />
                    <div class="flex w-40 flex-col gap-1">
                        <div class="h-2 w-full overflow-hidden rounded bg-muted">
                            <div
                                class="h-full bg-primary transition-[width] duration-100"
                                :style="{
                                    width: Math.max(0, 100 - Math.round((backfill.waitRemainingMs / Math.max(1, backfill.waitTotalMs)) * 100)) + '%',
                                }"
                            />
                        </div>
                        <div class="text-[11px] text-muted-foreground">next in {{ (backfill.waitRemainingMs / 1000).toFixed(1) }}s</div>
                    </div>
                </div>
                <div v-if="backfill.retryActive" class="flex min-w-[260px] items-center gap-2">
                    <Loader2 :size="24" class="animate-spin text-orange-500" />
                    <div class="flex w-48 flex-col gap-1">
                        <div class="h-2 w-full overflow-hidden rounded bg-muted">
                            <div
                                class="h-full bg-orange-500 transition-[width] duration-100"
                                :style="{
                                    width:
                                        Math.max(
                                            0,
                                            100 - Math.round((backfill.retryWaitRemainingMs / Math.max(1, backfill.retryWaitTotalMs)) * 100),
                                        ) + '%',
                                }"
                            />
                        </div>
                        <div class="text-[11px] text-muted-foreground">
                            retry {{ backfill.retryAttempt }} / {{ backfill.retryMax }} in {{ (backfill.retryWaitRemainingMs / 1000).toFixed(1) }}s
                        </div>
                    </div>
                </div>
            </div>
        </ContentLayout>
    </AppLayout>

    <!-- Full-size viewer (refactored) -->
    <FullSizeViewer
        v-model:open="dialogOpen"
        v-model:item="dialogItem"
        :items="items"
        :scroller="scroller"
        @favorite="onFavorite"
        @like="onLike"
        @dislike="onDislike"
        @laughed-at="onFunny"
    />

    <!-- Batch Blacklist Confirmation Dialog -->
    <Dialog v-model:open="batchConfirmOpen">
        <DialogScrollContent class="max-w-[520px]">
            <DialogTitle>Blacklist all shown?</DialogTitle>
            <DialogDescription>
                This will dislike and blacklist {{ (items || []).length }} shown item{{ (items || []).length === 1 ? '' : 's' }}.
                They will be removed from this list and the next page will load.
            </DialogDescription>
            <div class="mt-4 flex gap-2 justify-end">
                <Button variant="secondary" @click="() => (batchConfirmOpen = false)" data-testid="cancel-batch-blacklist">Cancel</Button>
                <Button variant="destructive" :disabled="batchBusy" @click="confirmBatchBlacklist" data-testid="confirm-batch-blacklist">
                    Blacklist all
                </Button>
            </div>
        </DialogScrollContent>
    </Dialog>
</template>
