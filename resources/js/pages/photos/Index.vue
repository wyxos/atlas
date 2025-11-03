<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import ContentLayout from '@/layouts/ContentLayout.vue';
import ScrollableLayout from '@/layouts/ScrollableLayout.vue';
import SectionHeader from '@/components/audio/SectionHeader.vue';
import GridItem from '@/components/browse/GridItem.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogDescription, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type BreadcrumbItem } from '@/types';
import type { BrowseItem } from '@/types/browse';
import { Head, useForm } from '@inertiajs/vue3';
import { Masonry } from '@wyxos/vibe';
import { Image, Hash, ChevronsRight, List as ListIcon, Loader2, Shuffle, ChevronsLeft, X, RefreshCw } from 'lucide-vue-next';
import { computed, nextTick, onMounted, provide, reactive, ref, watch } from 'vue';
import { createPhotosGetPage } from './usePhotosPaging';
import axios from 'axios';
import * as BrowseController from '@/actions/App/Http/Controllers/BrowseController';
import { undoManager } from '@/lib/undo';
import { IO_VISIBILITY_ROOT_MARGIN, IO_VISIBILITY_THRESHOLD } from '@/lib/visibility';
import FullSizeViewer from '@/pages/browse/FullSizeViewer.vue';
import { enqueueModeration, flushModeration } from '@/lib/moderation';

const props = defineProps<{
    files?: any[];
    filter?: Record<string, any>;
    moderation?: {
        ids?: number[];
        previews?: Array<{ preview?: string | null; title?: string | null }>;
        blacklisted_count?: number;
    } | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Photos', href: '/photos' }];

const items = ref<BrowseItem[]>([]);
const scroller = ref<any>(null);
const form = useForm({ ...(props.filter || {}) });

function snapshotFilters(): string {
    const data = form.data() as Record<string, any>;
    const sort = data.sort ?? 'newest';
    const limit = Number(data.limit ?? 20) || 20;
    const source = data.source ?? null;
    const randSeed = sort === 'random' ? Number(data.rand_seed ?? 0) || 0 : 0;
    return JSON.stringify({ sort, limit, source, randSeed });
}

const appliedFilterSnapshot = ref(snapshotFilters());
const filtersBusy = ref(false);
const filtersDirty = computed(() => snapshotFilters() !== appliedFilterSnapshot.value);

const containerCounts = reactive(new Map<string, Map<string | number, number>>());
provide('browse-container-counts', containerCounts as unknown as Map<string, Map<string | number, number>>);

function recomputeContainerCounts(list: any[]) {
    const counts = new Map<string, Map<string | number, number>>();
    for (const entry of Array.isArray(list) ? list : []) {
        const containers = ((entry?.containers || []) as any[]).filter(Boolean);
        for (const container of containers) {
            const key = String(container?.key ?? '');
            if (!key) continue;
            const value = container?.value as string | number | null | undefined;
            if (value == null) continue;
            let mapped = counts.get(key);
            if (!mapped) {
                mapped = new Map();
                counts.set(key, mapped);
            }
            mapped.set(value, (mapped.get(value) ?? 0) + 1);
        }
    }
    containerCounts.clear();
    for (const [key, inner] of counts) {
        const snapshot = new Map<string | number, number>();
        for (const [value, count] of inner) {
            snapshot.set(value, count);
        }
        containerCounts.set(key, snapshot);
    }
}

let countsRecomputePending = false;
function scheduleContainerCountRecompute() {
    if (countsRecomputePending) return;
    countsRecomputePending = true;
    requestAnimationFrame(() => {
        try {
            recomputeContainerCounts(items.value);
        } finally {
            countsRecomputePending = false;
        }
    });
}

watch(() => items.value.length, () => {
    try {
        scheduleContainerCountRecompute();
    } catch {}
});

function generateSeed(): number {
    return Math.floor(Math.random() * 2147483646) + 1;
}

async function applyFilters() {
    if (filtersBusy.value) return;
    filtersBusy.value = true;
    // Ensure seeded random has a seed
if ((form.sort as any ?? 'random') === 'random') {
        if (!form.rand_seed || Number(form.rand_seed as any) <= 0) {
            (form as any).rand_seed = generateSeed();
        }
    } else {
        // Clear seed when not using random
        delete (form as any).rand_seed;
    }
    // Reset paging via form API
    form.defaults({ ...form.data(), page: 1, next: null });
    form.reset();
    try {
        if (scroller.value?.reset) scroller.value.reset();
        if (scroller.value?.loadNext) await scroller.value.loadNext();
        appliedFilterSnapshot.value = snapshotFilters();
    } finally {
        filtersBusy.value = false;
    }
}

async function resetToFirstPage() {
    try {
form.defaults({ ...form.data(), page: 1, next: null });
        form.reset();
        if (scroller.value?.reset) scroller.value.reset();
        if (scroller.value?.loadNext) await scroller.value.loadNext();
        appliedFilterSnapshot.value = snapshotFilters();
    } catch {}
}

async function refreshCurrentPage() {
    try {
        if (scroller.value?.refreshCurrentPage) {
            await scroller.value.refreshCurrentPage();
        }
    } catch {}
}

const jumpToPageInput = ref<string>('');
async function jumpToPage() {
    const targetPage = parseInt(jumpToPageInput.value);
    if (!targetPage || targetPage < 1 || isNaN(targetPage)) return;
    try {
        form.defaults({ ...form.data(), page: targetPage, next: null });
        form.reset();
        if (scroller.value?.reset) scroller.value.reset();
        if (scroller.value?.loadNext) await scroller.value.loadNext();
        appliedFilterSnapshot.value = snapshotFilters();
        jumpToPageInput.value = '';
    } catch {}
}

function rerollRandom() {
if ((form.sort as any ?? 'random') !== 'random') return;
    (form as any).rand_seed = generateSeed();
    void applyFilters();
}

// Full-size viewer state (parity with Browse)
const dialogOpen = ref(false);
const dialogItem = ref<any | null>(null);
function openImage(item: any) {
    dialogItem.value = item ?? null;
    dialogOpen.value = !!item;
}

// Backfill gating (parity with Browse)
const backfillEnabled = ref(true);

// Note: ensureNextPageIfEmpty is no longer needed - Vibe now automatically
// refreshes the current page when all items are removed via remove() or removeMany()

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
const loadedItems = computed(() => (Array.isArray(items.value) ? items.value.length : 0));
const totalItems = computed(() => ((form as any)?.total as any) ?? scroller.value?.totalItems ?? loadedItems.value);

// Provide context for GridItem batch operations (consistent with Browse)
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

// Refresh layout whenever the items count changes (helps after random-paged fetches)
watch(() => items.value.length, () => {
    try { scroller.value?.refreshLayout?.(items.value) } catch {}
});

// Shared IntersectionObserver for grid items (one per grid)
const ioRegistry = new WeakMap<Element, () => void>();
const pendingFns: Array<() => void> = [];
const browseIO = new IntersectionObserver(
    (entries) => {
        for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            const tgt = (entry as any).target as Element | undefined;
            if (tgt) {
                const fn = ioRegistry.get(tgt);
                if (fn) {
                    try {
                        fn();
                    } catch {}
                }
                try {
                    browseIO.unobserve(tgt);
                } catch {}
                ioRegistry.delete(tgt);
            } else {
                // No target provided (test polyfills) — call the most recently registered fn
                const fn = pendingFns.pop();
                if (fn) {
                    try {
                        fn();
                    } catch {}
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
        try {
            browseIO.unobserve(el);
        } catch {}
    },
});

onMounted(async () => {
    if (!scroller.value) return;
    // Ensure default sort and seed
if (!(form as any).sort) {
        (form as any).sort = 'newest';
    }
    if (((form as any).sort ?? 'random') === 'random' && (!(form as any).rand_seed || Number((form as any).rand_seed) <= 0)) {
        (form as any).rand_seed = generateSeed();
    }

    // Seed initial items from server and cursor state
    scroller.value.init(
        Array.isArray(props.files) ? (props.files as any) : [],
        (props.filter?.page as any) ?? 1,
        (props.filter?.next as any) ?? null,
    );
    await nextTick();

    try {
        processModerationPayload(props.moderation);
    } catch {}

    // If initial items are below page size, auto load next
const pageSize = Number(((form as any)?.limit as any) ?? 20) || 20;
    const count = Array.isArray(items.value) ? items.value.length : 0;
    if (count < pageSize && typeof scroller.value.loadNext === 'function') {
        try {
            await scroller.value.loadNext();
        } catch {
            // ignore auto-load errors on first paint
        }
    }
});

const limit = computed(() => Number(((form as any)?.limit as any) ?? 20) || 20);
const masonryLayout = { sizes: { base: 1, sm: 2, md: 3, lg: 4, xl: 5, '2xl': 10 }, header: 40, footer: 40 } as const;
const getPage = createPhotosGetPage(form as any);

function processModerationPayload(payload: any) {
    const moderation = payload || {};
    const ids = Array.isArray(moderation?.ids) ? moderation.ids : [];
    const count = Number(moderation?.blacklisted_count ?? 0);
    if (ids.length === 0 && count <= 0) {
        return;
    }

    const previews = Array.isArray(moderation?.previews)
        ? moderation.previews
              .map((entry: any) => entry?.preview || '')
              .filter(Boolean)
              .slice(0, 4)
        : [];
    const titles = Array.isArray(moderation?.previews)
        ? moderation.previews
              .map((entry: any) => entry?.title || '')
              .filter(Boolean)
              .slice(0, 4)
        : [];

    try {
        enqueueModeration(ids, previews, titles);
    } catch {}

    window.setTimeout(() => {
        try {
            flushModeration();
        } catch {}
    }, 200);
}

// Masonry backfill/retry event handlers
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

// Reaction flow (parity with Browse non-dislike behavior)

type ReactionKind = 'love' | 'like' | 'dislike' | 'funny' | null;

function computePrevType(file: any): ReactionKind {
    if (file?.loved) return 'love';
    if (file?.liked) return 'like';
    if (file?.disliked) return 'dislike';
    if (file?.funny) return 'funny';
    return null;
}

async function handleReactFlow(file: any, type: Exclude<ReactionKind, null>, event?: Event) {
    event?.stopPropagation?.();
    const fileId = file?.id as number | undefined;
    if (!fileId) return;

    const currentIndex = items.value.findIndex((candidate) => candidate?.id === fileId);
    const shouldAdvance = dialogOpen.value && dialogItem.value?.id === fileId;

    const removedIndex = items.value.findIndex((candidate) => candidate?.id === fileId);
    const snapshot = { ...file };
    let refreshedAfterRemoval = false;

    // Remove the item and refresh the current page if the list becomes empty
    try { await scroller.value?.remove?.(file); } catch {}
    await nextTick();
    if ((items.value || []).length === 0 && typeof scroller.value?.refreshCurrentPage === 'function') {
        try {
            await scroller.value.refreshCurrentPage();
            refreshedAfterRemoval = true;
        } catch {}
        await nextTick();
    }

    try {
        const action = type === 'dislike'
            ? (BrowseController as any).dislikeBlacklist({ file: fileId })
            : (BrowseController as any).reactDownload({ file: fileId });
        await axios.post(action.url, type === 'dislike' ? {} : { type });
    } catch {}

    if (shouldAdvance) {
        await nextTick();
        let candidateItems = items.value || [];
        const baseIndex = currentIndex < 0 ? 0 : currentIndex;
        let nextItem: any | null = candidateItems[baseIndex] ?? candidateItems[baseIndex + 1] ?? null;
        if (!nextItem) {
            if (!refreshedAfterRemoval) {
                try {
                    if (scroller.value?.loadNext) {
                        await scroller.value.loadNext();
                        await nextTick();
                    }
                } catch {}
            }
            candidateItems = items.value || [];
            nextItem = candidateItems[0] ?? null;
        }
        if (nextItem) {
            dialogItem.value = nextItem;
        } else {
            dialogOpen.value = false;
            dialogItem.value = null;
        }
    }

    const previousType = computePrevType(file);
    undoManager.push({
        label: `${type.charAt(0).toUpperCase() + type.slice(1)} 1 item`,
        previews: [snapshot?.preview || snapshot?.thumbnail_url || ''].filter(Boolean) as string[],
        previewTitles: [snapshot?.title || ''].filter(Boolean) as string[],
        applyUI: () => {},
        revertUI: () => {
            const index = Math.max(0, Math.min(removedIndex, items.value.length));
            items.value.splice(index, 0, snapshot);
            void nextTick().then(() => scroller.value?.refreshLayout?.(items.value));
        },
        do: async () => Promise.resolve(),
        undo: async () => {
            try {
                const react = (BrowseController as any).react({ file: fileId });
                if (previousType === null) {
                    await axios.post(react.url, { type, state: false });
                } else {
                    await axios.post(react.url, { type: previousType, state: true });
                }
            } catch {}
        },
    });
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

// Dislike behavior: purge-local for disliked category, otherwise remove + dislike
function onDislike(file: any, event: Event) {
    // Use unified reaction flow
    void handleReactFlow(file, 'dislike', event);
}

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

defineExpose({
    applyFilters,
    filtersDirty,
    filtersBusy,
    form,
});
</script>

<template>
    <Head title="Photos" />
    <AppLayout :breadcrumbs="breadcrumbs">
<ContentLayout>
            <SectionHeader title="Photos" :icon="Image" />
            <div class="mb-3 flex flex-wrap items-end gap-3">
                <div class="grid gap-1">
<Label class="text-xs text-muted-foreground">Sort</Label>
                    <select
                        class="h-9 rounded-md border px-2 text-sm dark:bg-neutral-900"
                        v-model="(form as any).sort"
                        data-test="photos-sort"
                    >
                        <option value="newest">Newest</option>
                        <option value="oldest">Oldest</option>
                        <option value="random">Random</option>
                    </select>
                </div>
                <div class="grid gap-1">
                    <Label class="text-xs text-muted-foreground">Limit</Label>
                    <select
                        class="h-9 rounded-md border px-2 text-sm dark:bg-neutral-900"
v-model.number="(form as any).limit"
                        data-test="photos-limit"
                    >
                        <option :value="20">20</option>
                        <option :value="40">40</option>
                        <option :value="60">60</option>
                        <option :value="100">100</option>
                        <option :value="200">200</option>
                    </select>
                </div>
                <div class="grid gap-1">
                    <Label class="text-xs text-muted-foreground">Source</Label>
                    <select
                        class="h-9 rounded-md border px-2 text-sm dark:bg-neutral-900"
                        v-model="(form as any).source"
                        data-test="photos-source"
                    >
                        <option :value="undefined">All</option>
                        <option value="local">Local</option>
                        <option value="spotify">Spotify</option>
                        <option value="youtube">YouTube</option>
                        <option value="booru">Booru</option>
                    </select>
                </div>

<div v-if="(form as any).sort === 'random'" class="flex items-center gap-2">
                    <span class="text-xs text-muted-foreground">Seed</span>
                    <span class="text-sm font-mono" data-test="photos-seed-value">{{ (form as any).rand_seed }}</span>
                    <Button variant="outline" class="h-9 w-9 p-0 cursor-pointer" @click="rerollRandom" data-test="photos-reroll" aria-label="Re-roll seed">
                        <Shuffle :size="18" />
                    </Button>
                </div>

                <Button
                    class="h-9 px-4 cursor-pointer"
                    :disabled="filtersBusy || isLoading || !filtersDirty"
                    @click="() => applyFilters()"
                    data-test="photos-apply"
                >
                    Apply
                </Button>

                <Button
                    variant="outline"
                    :disabled="isLoading"
                    @click="() => scroller.loadNext()"
                    class="h-9 w-9 p-0 cursor-pointer"
                    data-test="photos-next"
                    aria-label="Load more"
                >
                    <ChevronsRight :size="18" />
                </Button>
                <Button
                    variant="outline"
                    :disabled="isLoading"
                    @click="refreshCurrentPage"
                    class="h-9 w-9 p-0 cursor-pointer"
                    data-test="photos-refresh"
                    aria-label="Refresh current page"
                    title="Refresh current page"
                >
                    <RefreshCw :size="18" />
                </Button>
                <Button
                    variant="destructive"
                    :disabled="isLoading"
                    @click="resetToFirstPage"
                    class="h-9 w-9 p-0 cursor-pointer"
                    data-test="photos-reset"
                    aria-label="First page"
                >
                    <ChevronsLeft :size="18" />
                </Button>

                <div class="flex items-center gap-2">
                    <Input
                        v-model="jumpToPageInput"
                        type="number"
                        min="1"
                        placeholder="Page #"
                        class="h-9 w-24"
                        @keyup.enter="jumpToPage"
                        data-test="photos-jump-input"
                    />
                    <Button
                        variant="outline"
                        :disabled="isLoading || !jumpToPageInput"
                        @click="jumpToPage"
                        class="h-9 px-3 cursor-pointer"
                        data-test="photos-jump"
                        title="Go to page"
                    >
                        Go
                    </Button>
                </div>

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

                <!-- Batch Dislike CTA -->
                <Button
                    variant="destructive"
                    class="h-9 px-3"
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
                    v-model:items="items"
                    :get-next-page="getPage"
                    :skip-initial-load="true"
                    :page-size="limit"
                    ref="scroller"
:layout="masonryLayout"
:backfill-enabled="backfillEnabled"
                    :backfill-delay-ms="2000"
                    :retry-max-attempts="3"
                    :retry-initial-delay-ms="2000"
                    :retry-backoff-step-ms="2000"
                    :load-threshold-px="0"
                    :backfill-max-calls="Infinity"
                    @backfill:start="onBackfillStart"
                    @backfill:tick="onBackfillTick"
                    @backfill:stop="onBackfillStop"
                    @retry:start="onRetryStart"
                    @retry:tick="onRetryTick"
                    @retry:stop="onRetryStop"
                    @remove-all:complete="() => scroller?.refreshCurrentPage?.()"
                >
<template #item="{ item }">
                        <GridItem
                            :key="item.id"
                            :item="item"
                            :file-for-reactions="{ id: item.id, loved: item.loved, liked: item.liked, disliked: item.disliked, funny: item.funny }"
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
<span class="font-medium text-foreground">{{ (form.page as any) ?? 1 }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <ChevronsRight :size="24" />
                    <span class="font-semibold">next</span>
<span class="font-medium text-foreground">{{ (form.next as any) ?? (form.page as any) ?? '—' }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <ListIcon :size="24" />
                    <span class="font-semibold">total</span>
                    <span class="font-medium text-foreground">{{ totalItems }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <Image :size="24" />
                    <span class="font-semibold">loaded</span>
                    <span class="font-medium text-foreground">{{ loadedItems }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <Loader2 v-if="isLoading" :size="24" class="animate-spin text-red-500" />
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

    <!-- Full-size viewer -->
    <FullSizeViewer
        v-model:open="dialogOpen"
        v-model:item="dialogItem"
        :items="items"
        :scroller="scroller"
        :refresh-on-empty="true"
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
