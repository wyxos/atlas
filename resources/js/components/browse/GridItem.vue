<script setup lang="ts">
import FileReactions from '@/components/audio/FileReactions.vue';
import { Button } from '@/components/ui/button';
import LoaderOverlay from '@/components/ui/LoaderOverlay.vue';
import { Eye, ZoomIn, MoreHorizontal, User, Newspaper, Book, BookOpen, Palette, Tag, Info, ImageOff, AlertTriangle, Copy, TestTube } from 'lucide-vue-next';
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import ActionMenu, { type ActionOption } from '@/components/browse/ActionMenu.vue';
import ContainerBadge from '@/components/browse/ContainerBadge.vue';
import { bus } from '@/lib/bus';
import { ringForSlot, badgeClassForSlot } from '@/pages/browse/highlight';
import { createBatchReact, type BatchAction, type BatchScope } from '@/pages/browse/useBatchReact';
import * as BrowseController from '@/actions/App/Http/Controllers/BrowseController';
import axios from 'axios';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { IO_VISIBILITY_ROOT_MARGIN, IO_VISIBILITY_THRESHOLD } from '@/lib/visibility';
import { highlightPromptHtml } from '@/utils/moderationHighlight';

const props = defineProps<{
    item: any;
    fileForReactions?: any;
}>();

// --- Moderation highlighting helpers ---
const moderationInfo = computed(() => {
    const meta = (props.item as any)?.metadata ?? {};
    const m = (meta as any)?.moderation ?? null;
    if (!m || (m as any)?.reason !== 'moderation:rule') return null;
    return m as { reason: string; rule_id?: number; rule_name?: string | null; options?: { case_sensitive?: boolean; whole_word?: boolean } | null; hits?: string[] };
});

const moderationHits = computed<string[]>(() => {
    const arr = (moderationInfo.value?.hits || []) as string[];
    return Array.isArray(arr) ? arr.filter((s) => typeof s === 'string' && s.trim().length > 0) : [];
});

const moderationRuleLabel = computed(() => {
    const r = moderationInfo.value;
    if (!r) return '';
    return r.rule_name ? String(r.rule_name) : (r.rule_id ? `Rule #${r.rule_id}` : 'Rule');
});

/**
 * Compute highlighted prompt HTML using the moderation rule options.
 *
 * This function now uses the highlightPromptHtml utility which mirrors the PHP
 * Moderator::termMatches() logic, respecting whole_word and case_sensitive options.
 */
function computeHighlightedPromptHtml(): string {
    const raw = String(((props.item as any)?.metadata?.prompt ?? '') || '');
    if (!raw) return '';

    const terms = moderationHits.value;
    if (!terms.length) {
        // No hits to highlight - return escaped prompt
        return highlightPromptHtml(raw, [], {});
    }

    // Get moderation options from metadata (defaults: whole_word=true, case_sensitive=false)
    const options = (moderationInfo.value?.options ?? {}) as { case_sensitive?: boolean; whole_word?: boolean };

    // Use the utility function that mirrors PHP moderation logic
    return highlightPromptHtml(raw, terms, options);
}

const highlightedPromptHtml = computed(() => computeHighlightedPromptHtml());

// Build URL for moderation test page with prefilled data
const moderationTestUrl = computed(() => {
    const prompt = String((props.item as any)?.metadata?.prompt ?? '');
    if (!prompt) return null;

    const params = new URLSearchParams();
    params.set('text', prompt);

    if (moderationInfo.value?.rule_id) {
        params.set('rule_id', String(moderationInfo.value.rule_id));
    }

    return `/moderation/test?${params.toString()}`;
});

const emit = defineEmits<{
    (e: 'open', item: any): void;
    (e: 'favorite', item: any, event: Event): void;
    (e: 'like', item: any, event: Event): void;
    (e: 'dislike', item: any, event: Event): void;
    (e: 'laughed-at', item: any, event: Event): void;
}>();

const root = ref<HTMLElement | null>(null);
const isVisible = ref(false);
const hasLoaded = ref(false);
let outsideHandler: ((e: MouseEvent) => void) | null = null;

// Retry preview loading up to 3 times before marking as not found
const retryCount = ref(0);

// When video keeps failing, fall back to the preview image (if any)
const useImageFallback = ref(false);
const videoEl = ref<HTMLVideoElement | null>(null);

const imageSrc = computed(() => {
    const p = (props.item as any)?.preview as string | undefined;
    if (!p) { return ''; }
    return p;
});

const videoSrc = computed(() => {
    const src = (props.item as any)?.preview as string | undefined;
    if (!src) { return ''; }
    return src;
});

// Force the video element to reload when retryCount changes (programmatic cache busting)
watch(retryCount, () => {
    if (videoEl.value) {
        try { videoEl.value.load(); } catch {}
    }
});

// Reset retry state when the item changes
watch(() => (props.item as any)?.id, () => {
    retryCount.value = 0;
    hasLoaded.value = false;
    previewReported.value = false;
    missingReported.value = false;
    useImageFallback.value = false;
    errorMessage.value = null;
});

// Inject shared intersection observer registry from parent (one per grid)
const sharedIO = inject<{ observer: IntersectionObserver; register: (el: Element, fn: () => void) => void; unregister: (el: Element) => void } | null>('browse-io', null);
const anchorX = ref<number | null>(null);
const anchorY = ref<number | null>(null);
const overlayRef = ref<HTMLElement | null>(null);
const isHovering = ref(false);

const panelFixedStyle = computed<Record<string, string>>(() => {
    const el = root.value;
    if (!el) return { display: 'none' };
    const rect = el.getBoundingClientRect();
    const y = anchorY.value ?? rect.top;
    const margin = 10;
    const style: Record<string, string> = {
        position: 'fixed',
        left: `${rect.left}px`,
        width: `${rect.width}px`,
    };
    if (y <= window.innerHeight / 2) {
        style.top = `${Math.min(window.innerHeight - margin, y + margin)}px`;
    } else {
        style.bottom = `${Math.max(margin, window.innerHeight - y + margin)}px`;
    }
    return style;
});

onMounted(() => {
    // Close action panel when clicking outside this item
    const onOutside = (e: MouseEvent) => {
        if (!actionPanelOpen.value) return;
        const el = root.value;
        const overlay = overlayRef.value;
        if (overlay && e.target instanceof Node && overlay.contains(e.target)) return;
        if (el && e.target instanceof Node && el.contains(e.target)) return;
        actionPanelOpen.value = false;
        // Clear highlight when panel is closed due to outside click
        bus.emit('browse:clear-highlight');
    };
    window.addEventListener('mousedown', onOutside, { capture: true });
    window.addEventListener('contextmenu', onOutside, { capture: true });
    outsideHandler = onOutside;

    // Visibility observation via shared IO (one per grid)
    if (sharedIO && root.value) {
        sharedIO.register(root.value, () => {
            isVisible.value = true;
        });
    } else if (root.value) {
        // Fallback: create a tiny one-off observer only for this element
        const fallback = new IntersectionObserver(
            (entries) => {
                if (entries[0]?.isIntersecting) {
                    isVisible.value = true;
                    try { fallback.unobserve(entries[0].target); } catch {}
                }
            },
            { root: null, rootMargin: IO_VISIBILITY_ROOT_MARGIN, threshold: IO_VISIBILITY_THRESHOLD },
        );
        fallback.observe(root.value);
    }
});

onBeforeUnmount(() => {
    if (outsideHandler) {
        window.removeEventListener('mousedown', outsideHandler as any, true as any);
        window.removeEventListener('contextmenu', outsideHandler as any, true as any);
        outsideHandler = null;
    }
    if (sharedIO && root.value) {
        sharedIO.unregister(root.value);
    }
    // Ensure highlight cleared when component unmounts
    bus.emit('browse:clear-highlight');
});

// Action panel state
const actionPanelOpen = ref(false);
const initialPathLabels = ref<string[] | null>(null);

// Container registry: define label, color and icon per key; unknowns fallback
const containerRegistry: Record<string, { label: string; color: string; icon: any }> = {
    username: { label: 'user', color: 'rose', icon: User },
    postId: { label: 'post', color: 'sky', icon: Newspaper },
    manga: { label: 'manga', color: 'emerald', icon: Book },
    chapter: { label: 'chapter', color: 'amber', icon: BookOpen },
    artist: { label: 'artist', color: 'fuchsia', icon: Palette },
    gallery: { label: 'gallery', color: 'cyan', icon: Tag },
    baseModel: { label: 'model', color: 'indigo', icon: Tag },
};

function resolveMeta(key: string, label?: string | null) {
    const meta = containerRegistry[key] || { label: key, color: 'violet', icon: Tag };
    return { label: (label && String(label)) || meta.label, color: meta.color as string, icon: meta.icon };
}

function listItemContainers(item: any): Array<{ key: string; value: string | number; label: string; color: string; icon: any }> {
    const containersRaw = (((item?.containers || []) as any[]) || []).filter(Boolean);
    const formatted: Array<{ key: string; value: string | number; label: string; color: string; icon: any }> = [];
    for (const container of containersRaw) {
        const containerKey = String(container?.key ?? '');
        if (!containerKey) continue;
        const containerValue = container?.value as string | number | null | undefined;
        if (containerValue == null) continue;
        const { label, color, icon } = resolveMeta(containerKey, container?.label as string | null | undefined);
        formatted.push({ key: containerKey, value: containerValue as any, label, color, icon });
    }
    return formatted;
}

// Highlight state (generic entries)
const highlightTargets = ref<{
    sourceId?: number;
    entries: Array<{ key: string; label: string; value: string | number; count: number; slotIndex: number }>;
} | null>(null);
const hoverBadgeHighlightActive = ref(false);

function computeContainerEntries() {
    const sourceItem = props.item as any;
    const itemContainers = listItemContainers(sourceItem);
    const allItems = (((providedItems as any)?.value || []) as any[]) || [];
    const entries: Array<{ key: string; label: string; value: string | number; count: number; slotIndex: number }> = [];
    for (let index = 0; index < itemContainers.length; index++) {
        const container = itemContainers[index];
        const countAll = allItems.filter((item) => ((item?.containers || []) as any[]).some((x: any) => x?.key === container.key && x?.value == container.value)).length;
        // Only include when there is at least one sibling (count > 1 including self),
        // and report count INCLUDING the source item (target) as requested.
        if (countAll > 1) entries.push({ key: container.key, label: container.label, value: container.value, count: countAll, slotIndex: index });
    }
    return entries;
}

const highlightActive = computed(
    () => !!highlightTargets.value && Array.isArray(highlightTargets.value.entries) && highlightTargets.value.entries.length > 0,
);

const isSibling = computed(() => {
    if (!highlightActive.value) return false;
    const itemContainers = listItemContainers(props.item);
    const entries = highlightTargets.value?.entries || [];
    return entries.some((entry) => itemContainers.some((container) => container.key === entry.key && container.value == entry.value));
});

const ringClass = computed(() => {
    if (!highlightActive.value || !isSibling.value) return '';
    const itemContainers = listItemContainers(props.item);
    const entries = highlightTargets.value?.entries || [];
    // Choose ring by the smallest slotIndex among matched entries (container order from backend)
    let chosenSlotIndex: number | null = null;
    for (let i = 0; i < entries.length; i++) {
        const entry = entries[i];
        if (itemContainers.some((container) => container.key === entry.key && container.value == entry.value)) {
            if (chosenSlotIndex == null || entry.slotIndex < chosenSlotIndex) {
                chosenSlotIndex = entry.slotIndex;
            }
        }
    }
    return chosenSlotIndex != null ? ringForSlot(chosenSlotIndex) : '';
});

const isSource = computed(() => !!highlightTargets.value && highlightTargets.value.sourceId === (props.item as any)?.id);

const sourceEntries = computed(() => {
    if (!isSource.value) {
        return [] as Array<{ key: string; label: string; value: string | number; count: number; slotIndex: number }>;
    }
    const highlighted = highlightTargets.value?.entries;
    if (highlighted && highlighted.length) {
        return highlighted;
    }
    return computeContainerEntries();
});

// Color class for previewed count: >=3 red, >=2 amber, otherwise inherit
const previewedCountColorClass = computed<string>(() => {
    const count = Number(((props.item as any)?.previewed_count ?? 0) as number);
    if (count >= 3) {
        return 'text-red-600 dark:text-red-400';
    }
    if (count >= 2) {
        return 'text-amber-600 dark:text-amber-400';
    }
    return '';
});

function emitHighlight(
    entries: Array<{ key: string; label: string; value: string | number; count: number; slotIndex: number }>,
    fromHover = false,
): boolean {
    if (!entries.length) {
        return false;
    }
    const sourceId = (props.item as any)?.id;
    if (!sourceId) {
        return false;
    }
    bus.emit('browse:highlight-containers', { sourceId, entries });
    hoverBadgeHighlightActive.value = fromHover;
    return true;
}

function broadcastHighlightForCurrentItem() {
    const entries = computeContainerEntries();
    emitHighlight(entries);
}

function clearHighlight() {
    hoverBadgeHighlightActive.value = false;
    bus.emit('browse:clear-highlight');
}

// Subscribe to highlight events
bus.on('browse:highlight-containers', (payload) => {
    highlightTargets.value = payload || null;
});

bus.on('browse:clear-highlight', () => {
    hoverBadgeHighlightActive.value = false;
    highlightTargets.value = null;
});

watch(isHovering, (hovering) => {
    if (!hovering && hoverBadgeHighlightActive.value) {
        clearHighlight();
    }
});

// Keep bus state in sync when panel closes
watch(actionPanelOpen, (open) => {
    if (!open) {
        clearHighlight();
    }
});

// Injected browse context
const providedItems = inject<any>('browse-items');
const providedScroller = inject<any>('browse-scroller');
const providedScheduleRefresh = inject<() => void>('browse-schedule-refresh');
const containerCounts = inject<Map<string, Map<string | number, number>>>('browse-container-counts', new Map());

const batchReact = createBatchReact({ items: providedItems as any, scroller: providedScroller as any, scheduleRefresh: providedScheduleRefresh as any });

const hasContainers = computed(() => Array.isArray((props.item as any)?.containers) && ((props.item as any)?.containers?.length || 0) > 0);

type ContainerScopeEntry = {
    label: string;
    count: number;
    scope: BatchScope;
    badge: { text: string; class: string };
    slotIndex: number;
};

const hoverBatchEntries = computed<ContainerScopeEntry[]>(() => containerScopes());

function isEventFromBatchBadge(event: MouseEvent): boolean {
    const path = typeof event.composedPath === 'function' ? event.composedPath() : [];
    for (const entry of path) {
        if (entry instanceof HTMLElement && entry.dataset?.batchBadge === 'true') {
            return true;
        }
    }
    const target = event.target;
    if (target instanceof HTMLElement && target.dataset?.batchBadge === 'true') {
        return true;
    }
    return false;
}

function getIdsForScope(scope: BatchScope): number[] {
    const seen = new Set<number>();
    const items = (((providedItems as any)?.value || []) as any[]) || [];
    for (const candidate of items) {
        if (!candidate || typeof candidate !== 'object') continue;
        if (!Array.isArray(candidate.containers)) continue;
        const matches = candidate.containers.some((container: any) => container?.key === scope.key && container?.value == scope.value);
        if (!matches) continue;
        const id = candidate?.id;
        if (typeof id === 'number') {
            seen.add(id);
        }
    }
    const currentId = (props.item as any)?.id;
    if (typeof currentId === 'number') {
        seen.add(currentId);
    }
    return Array.from(seen);
}

function containerScopes(): ContainerScopeEntry[] {
    const uniqueKeyMap: Record<string, boolean> = {};
    const containers = listItemContainers(props.item);
    const entries: ContainerScopeEntry[] = [];

    for (let index = 0; index < containers.length; index++) {
        const container = containers[index];
        const keyString = `${container.key}:${container.value}`;
        if (uniqueKeyMap[keyString]) {
            continue;
        }
        uniqueKeyMap[keyString] = true;

        const inner = containerCounts.get(container.key);
        const countAll = inner ? (inner.get(container.value) ?? 1) : 1;
        if (countAll <= 1) {
            continue;
        }

        entries.push({
            label: container.label,
            count: countAll,
            scope: { key: container.key, value: container.value },
            badge: { text: String(countAll), class: badgeClassForSlot(index) },
            slotIndex: index,
        });
    }

    return entries;
}

function highlightScopeEntry(entry: ContainerScopeEntry) {
    emitHighlight(
        [
            {
                key: entry.scope.key,
                label: entry.label,
                value: entry.scope.value,
                count: entry.count,
                slotIndex: entry.slotIndex,
            },
        ],
        true,
    );
}

function handleBatchBadgeMouseEnter(entry: ContainerScopeEntry) {
    if (actionPanelOpen.value) {
        return;
    }
    highlightScopeEntry(entry);
}

function handleBatchBadgeMouseLeave() {
    if (!hoverBadgeHighlightActive.value) {
        return;
    }
    clearHighlight();
}

async function handleBatchBadgeMouseDown(scope: { key: string; value: string | number }, e: MouseEvent) {
    // Prevent browser autoscroll for middle-click (with or without ALT)
    if (e.button === 1) {
        e.preventDefault();
        e.stopPropagation();
        // Favorite action will be handled on auxclick
        return;
    }

    if (!(e.altKey && e.button === 0)) {
        return;
    }
    e.preventDefault();
    e.stopPropagation();

    const ids = getIdsForScope(scope);
    if (!ids.length) {
        return;
    }

    try {
        await batchReact('like', scope);
    } catch {}

    for (const id of ids) {
        try {
            const action = (BrowseController as any).reactDownload({ file: id });
            if (action?.url) {
                await axios.post(action.url, { type: 'like' });
            }
        } catch {}
    }
}

async function handleBatchBadgeAuxClick(scope: { key: string; value: string | number }, e: MouseEvent) {
    if (e.altKey && e.button === 1) {
        e.preventDefault();
        e.stopPropagation();

        const ids = getIdsForScope(scope);
        if (!ids.length) {
            return;
        }

        try {
            await batchReact('favorite', scope);
        } catch {}

        for (const id of ids) {
            try {
                const action = (BrowseController as any).reactDownload({ file: id });
                if (action?.url) {
                    await axios.post(action.url, { type: 'love' });
                }
            } catch {}
        }

        return;
    }

    if (e.altKey && (e.button === 3 || e.button === 4)) {
        e.preventDefault();
        e.stopPropagation();
        try {
            await batchReact('dislike', scope);
        } catch {}
    }
}

async function handleBatchBadgeContextMenu(scope: { key: string; value: string | number }, e: MouseEvent) {
    if (!e.altKey) {
        return;
    }
    e.preventDefault();
    e.stopPropagation();
    try {
        await batchReact('dislike', scope);
    } catch {}
}

const menuRoot = computed<ActionOption[]>(() => {
    const base: ActionOption[] = [
        {
            label: 'react',
            children: [
                { label: 'favorite', action: (e?: Event) => emit('favorite', props.item, (e || ({} as any)) as any) },
                { label: 'like', action: (e?: Event) => emit('like', props.item, (e || ({} as any)) as any) },
                { label: 'dislike', action: (e?: Event) => emit('dislike', props.item, (e || ({} as any)) as any) },
                { label: 'funny', action: (e?: Event) => emit('laughed-at', props.item, (e || ({} as any)) as any) },
            ],
        },
        { label: 'view', action: () => openModal() },
    ];

    const copyChildren: ActionOption[] = [];

    // Copy referrer url (always show if available)
    if (referrerUrl.value) {
        copyChildren.push({ label: 'copy referrer url', action: () => copyUrl(referrerUrl.value) });
    }

    // Copy original url (url column in database = true_original_url)
    if (trueOriginalUrl.value) {
        copyChildren.push({ label: 'copy original url', action: () => copyUrl(trueOriginalUrl.value) });
    }

    // Copy original preview url (thumbnail_url column in database = true_thumbnail_url)
    if (trueThumbnailUrl.value) {
        copyChildren.push({ label: 'copy original preview url', action: () => copyUrl(trueThumbnailUrl.value) });
    }

    // Copy thumbnail url (currently used, in case it's pointing to local)
    if (thumbnailUrl.value) {
        copyChildren.push({ label: 'copy thumbnail url', action: () => copyUrl(thumbnailUrl.value) });
    }

    // Copy url (currently used, in case pointing to local)
    const currentUrl = originalUrl.value || thumbnailUrl.value;
    if (currentUrl) {
        copyChildren.push({ label: 'copy url', action: () => copyUrl(currentUrl) });
    }

    // Copy image (to clipboard)
    if (shouldRenderVideo.value === false) {
        copyChildren.push({ label: 'copy image', action: () => copyImageToClipboard() });
    }

    if (copyChildren.length > 0) {
        base.push({ label: 'copy url', children: copyChildren });
    }

    // Open URL menu items
    const openChildren: ActionOption[] = [];

    // Open referrer url in new tab
    if (referrerUrl.value) {
        openChildren.push({ label: 'open referrer url', action: () => openUrlInNewTab(referrerUrl.value) });
    }

    // Open original url in new tab (url column in database = true_original_url)
    if (trueOriginalUrl.value) {
        openChildren.push({ label: 'open original url', action: () => openUrlInNewTab(trueOriginalUrl.value) });
    }

    // Open original preview url in new tab (thumbnail_url column in database = true_thumbnail_url)
    if (trueThumbnailUrl.value) {
        openChildren.push({ label: 'open original preview url', action: () => openUrlInNewTab(trueThumbnailUrl.value) });
    }

    // Open thumbnail url in new tab (currently used, in case it's pointing to local)
    if (thumbnailUrl.value) {
        openChildren.push({ label: 'open thumbnail url', action: () => openUrlInNewTab(thumbnailUrl.value) });
    }

    // Open url in new tab (currently used, in case pointing to local)
    if (currentUrl) {
        openChildren.push({ label: 'open url', action: () => openUrlInNewTab(currentUrl) });
    }

    if (openChildren.length > 0) {
        base.push({ label: 'open url', children: openChildren });
    }

    const scopes = containerScopes();
    if (scopes.length > 0) {
        const scopeChildren = (action: BatchAction): ActionOption[] =>
            scopes.map((s) => ({ label: s.label, badge: s.badge, action: () => batchReact(action, s.scope) }));

        base.push({
            label: 'batch',
            children: [
                { label: 'favorite', children: scopeChildren('favorite') },
                { label: 'like', children: scopeChildren('like') },
                { label: 'funny', children: scopeChildren('funny') },
                { label: 'dislike', children: scopeChildren('dislike') },
            ],
        });
    }

    return base;
});

function computeAnchorFromEvent(event?: Event) {
    const mouseEvent = event as MouseEvent | undefined;
    if (mouseEvent && typeof mouseEvent.clientX === 'number' && typeof mouseEvent.clientY === 'number') {
        anchorX.value = mouseEvent.clientX;
        anchorY.value = mouseEvent.clientY;
        return;
    }
    const targetElement = (event?.target as Element | null) ?? root.value;
    const rect = targetElement?.getBoundingClientRect?.();
    if (rect) {
        anchorX.value = Math.round(rect.left + rect.width / 2);
        anchorY.value = Math.round(rect.bottom);
    } else {
        anchorX.value = Math.round(window.innerWidth / 2);
        anchorY.value = 0;
    }
}

function toggleActionPanel(e?: Event) {
    e?.stopPropagation?.();
    if (!actionPanelOpen.value) {
        initialPathLabels.value = null;
        computeAnchorFromEvent(e);
        actionPanelOpen.value = true;
    } else {
        actionPanelOpen.value = false;
    }
}

function openModal() {
    if (!props.item) return;
    emit('open', props.item);
}

function onCardClick(e: MouseEvent) {
    if (!props.item) return;
    if (e.altKey) {
        e.preventDefault();
        e.stopPropagation();
        emit('like', props.item, e as unknown as Event);
        return;
    }
    // If action panel is open, close it instead of opening modal
    if (actionPanelOpen.value) {
        e.preventDefault();
        e.stopPropagation();
        actionPanelOpen.value = false;
        return;
    }
    openModal();
}

function onCardMouseDown(e: MouseEvent) {
    if (isEventFromBatchBadge(e)) {
        return;
    }
    // Prevent browser autoscroll when using middle-click (with or without ALT)
    if (e.button === 1) {
        e.preventDefault();
        e.stopPropagation();
        // With ALT: Favorite action will still be handled on mouseup/auxclick via tryHandleAltMiddleFavorite
        // Without ALT: Will open in new tab on auxclick
        return;
    }
    // ALT + back mouse button opens the action panel and jumps to batch > dislike
    if (e.altKey && e.button === 3) {
        e.preventDefault();
        e.stopPropagation();
        initialPathLabels.value = hasContainers.value ? ['batch', 'dislike'] : null;
        computeAnchorFromEvent(e);
        actionPanelOpen.value = true;
        return;
    }
    // ALT + forward mouse button opens the action panel and jumps to batch > like
    if (e.altKey && e.button === 4) {
        e.preventDefault();
        e.stopPropagation();
        initialPathLabels.value = hasContainers.value ? ['batch', 'like'] : null;
        computeAnchorFromEvent(e);
        actionPanelOpen.value = true;
        return;
    }
}

let lastAltMiddleFavoriteAt = 0;
function tryHandleAltMiddleFavorite(mouseEvent: MouseEvent): boolean {
    const button = (mouseEvent as any).button;
    const altKey = (mouseEvent as any).altKey;
    if (!(altKey && button === 1)) return false;
    if (!props.item) return false;
    const now = Date.now();
    if (now - lastAltMiddleFavoriteAt < 250) {
        mouseEvent.preventDefault();
        mouseEvent.stopPropagation();
        return true;
    }
    lastAltMiddleFavoriteAt = now;
    mouseEvent.preventDefault();
    mouseEvent.stopPropagation();
    emit('favorite', props.item, mouseEvent as unknown as Event);
    return true;
}

function onCardMouseUp(e: MouseEvent) {
    // Block browser history when using ALT + back/forward
    if (e.altKey && (e.button === 3 || e.button === 4)) {
        e.preventDefault();
        e.stopPropagation();
    }
    if (isEventFromBatchBadge(e)) {
        return;
    }
    // Fallback for browsers that don't dispatch auxclick for middle button
    if (tryHandleAltMiddleFavorite(e)) return;
}

function onCardAuxClick(mouseEvent: MouseEvent) {
    if (isEventFromBatchBadge(mouseEvent)) {
        return;
    }
    const button = (mouseEvent as any).button;
    const altKey = (mouseEvent as any).altKey;

    // Block history buttons when ALT is held
    if (altKey && (button === 3 || button === 4)) {
        mouseEvent.preventDefault();
        mouseEvent.stopPropagation();
        return;
    }
    // Primary path for alt+middle favorite
    if (tryHandleAltMiddleFavorite(mouseEvent)) return;
    // Middle-click (aux button) without Alt should open the original URL in a new tab
    if (!altKey && button === 1) {
        mouseEvent.preventDefault();
        mouseEvent.stopPropagation();
        openUrlInNewTab();
        return;
    }
}

function onContextMenu(event: MouseEvent) {
    // Right-click: open panel; Alt+right keeps quick-dislike
    if (event.altKey) {
        event.preventDefault();
        emit('dislike', props.item, event as unknown as Event);
        return;
    }
    event.preventDefault();
    event.stopPropagation();
    initialPathLabels.value = null;
    computeAnchorFromEvent(event);
    // No automatic highlight on generic open; will toggle when navigating into 'batch'
    actionPanelOpen.value = true;
}

const previewReported = ref(false);
const missingReported = ref(false);
const errorMessage = ref<string | null>(null);
const errorKind = ref<'none' | 'not-found' | 'unavailable'>('none');
const errorStatus = ref<number | null>(null);
const verifiedAvailableOnce = ref(false);

function setErrorState(kind: 'none' | 'not-found' | 'unavailable', status: number | null = null, message: string | null = null) {
    errorKind.value = kind;
    errorStatus.value = status;
    errorMessage.value = message;

    if (kind === 'not-found') {
        (props.item as any).not_found = true;
    } else {
        (props.item as any).not_found = false;
    }

    if (kind === 'none') {
        delete (props.item as any).media_error;
    } else {
        (props.item as any).media_error = {
            kind,
            status,
            message,
        };
    }
}

const isBackendFlaggedNotFound = computed(() => (props.item as any)?.not_found === true && errorKind.value === 'none');
const isResolutionRequired = computed(() => (props.item as any)?.resolutionRequired === true);
const isNotFoundError = computed(() => ((props.item as any)?.not_found === true) || errorKind.value === 'not-found');
const showErrorOverlay = computed(() => isNotFoundError.value || errorKind.value === 'unavailable');
const overlayIcon = computed(() => (isNotFoundError.value ? ImageOff : AlertTriangle));
const overlayMessage = computed(() => (isNotFoundError.value ? 'Not found' : 'Unable to load media'));
const overlayDetails = computed(() => {
    if (isNotFoundError.value) {
        return null;
    }
    if (errorMessage.value) {
        return errorMessage.value;
    }
    if (errorStatus.value != null) {
        return `HTTP ${errorStatus.value}`;
    }
    return null;
});

const shouldAttemptMediaLoad = computed(() => !isBackendFlaggedNotFound.value && !isResolutionRequired.value);

watch(
    isBackendFlaggedNotFound,
    (flagged) => {
        if (flagged) {
            hasLoaded.value = true;
        }
    },
    { immediate: true },
);

watch(
    isResolutionRequired,
    (required) => {
        if (required) {
            hasLoaded.value = true;
            setErrorState('none');
        }
    },
    { immediate: true },
);

const thumbnailUrl = computed<string | null>(() => {
    const item = props.item as any;
    const preview = typeof item?.preview === 'string' ? item.preview.trim() : '';
    if (preview) {
        return preview;
    }
    const fallback = typeof item?.thumbnail_url === 'string' ? item.thumbnail_url.trim() : '';
    return fallback || null;
});

const originalUrl = computed<string | null>(() => {
    const item = props.item as any;
    const original = typeof item?.original === 'string' ? item.original.trim() : '';
    return original || null;
});

const trueOriginalUrl = computed<string | null>(() => {
    const raw = (props.item as any)?.true_original_url;
    if (typeof raw !== 'string') {
        return null;
    }
    const trimmed = raw.trim();
    return trimmed.length > 0 ? trimmed : null;
});

const trueThumbnailUrl = computed<string | null>(() => {
    const raw = (props.item as any)?.true_thumbnail_url;
    if (typeof raw !== 'string') {
        return null;
    }
    const trimmed = raw.trim();
    return trimmed.length > 0 ? trimmed : null;
});

const referrerUrl = computed<string | null>(() => {
    const raw = (props.item as any)?.referrer_url;
    if (typeof raw !== 'string') {
        return null;
    }
    const trimmed = raw.trim();
    return trimmed.length > 0 ? trimmed : null;
});

const resolvedMediaKind = computed<'video' | 'image'>(() => {
    if (useImageFallback.value) {
        return 'image';
    }

    const item = props.item as any;
    const type = (item?.type ?? '').toString().toLowerCase();
    const mime = (item?.mime_type ?? '').toString().toLowerCase();

    // Check both type property and mime_type to determine if it's a video
    if (type === 'video' || mime.startsWith('video/')) {
        return 'video';
    }

    return 'image';
});

const shouldRenderVideo = computed(() => resolvedMediaKind.value === 'video');

// Context dropdown removed; using ActionMenu component

async function copyToClipboard(text: string): Promise<boolean> {
    if (!text) {
        return false;
    }

    try {
        if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);
            return true;
        }
    } catch {}

    if (typeof document === 'undefined') {
        return false;
    }

    try {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', 'true');
        textarea.style.position = 'absolute';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(textarea);
        return ok;
    } catch {}

    return false;
}

function copyUrl(url: string | null): void {
    if (!url) {
        return;
    }
    void copyToClipboard(url);
}

async function copyImageToClipboard(): Promise<void> {
    try {
        const imgElement = root.value?.querySelector('img') as HTMLImageElement | null;
        if (!imgElement || !imgElement.complete) {
            return;
        }

        // Create canvas and draw image
        const canvas = document.createElement('canvas');
        canvas.width = imgElement.naturalWidth || imgElement.width;
        canvas.height = imgElement.naturalHeight || imgElement.height;
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            return;
        }

        ctx.drawImage(imgElement, 0, 0);

        // Convert to blob and copy to clipboard
        canvas.toBlob(async (blob) => {
            if (!blob) {
                return;
            }

            try {
                if (navigator.clipboard && navigator.clipboard.write) {
                    const clipboardItem = new ClipboardItem({ [blob.type]: blob });
                    await navigator.clipboard.write([clipboardItem]);
                }
            } catch {
                // Fallback: try to copy as data URL if ClipboardItem is not supported
                try {
                    const dataUrl = canvas.toDataURL('image/png');
                    await copyToClipboard(dataUrl);
                } catch {}
            }
        }, 'image/png');
    } catch {
        // Silently fail
    }
}

async function reportPreviewSeen() {
    if (previewReported.value) return;
    const id = props.item?.id;
    if (!id) return;
    previewReported.value = true;
    try {
        const action = (BrowseController as any).previewSeen({ file: id });
        if (action?.url) {
            const res = await axios.post(action.url);
            const cnt = (res?.data?.previewed_count ?? null) as number | null;
            if (typeof cnt === 'number') {
                (props.item as any).previewed_count = cnt;
            } else {
                (props.item as any).previewed_count = ((props.item as any).previewed_count ?? 0) + 1;
            }
        }
    } catch {
        // ignore
    }
}

function onPreviewImageLoad() {
    hasLoaded.value = true;
    verifiedAvailableOnce.value = false;
    setErrorState('none');
    missingReported.value = false;
    void reportPreviewSeen();
}

async function reportMissingMedia() {
    if (missingReported.value) {
        return;
    }

    const id = (props.item as any)?.id as number | undefined;
    if (!id) {
        return;
    }

    missingReported.value = true;

    try {
        const action = (BrowseController as any).reportMissing({ file: id });
        if (!action?.url) {
            return;
        }

        const response = await axios.post(action.url, { verify: true });
        const statusRaw = response?.data?.status;
        const status = typeof statusRaw === 'number' ? Number(statusRaw) : null;
        const confirmedMissing = response?.data?.not_found === true;
        const serverMessage = (response?.data?.message ?? null) as string | null;

        if (confirmedMissing) {
            verifiedAvailableOnce.value = false;
            setErrorState('not-found', status ?? 404, null);
            return;
        }

        if (status && status >= 400) {
            const fallbackMessage = serverMessage || `Remote server responded with ${status}`;
            setErrorState('unavailable', status, fallbackMessage);
            return;
        }

        if ((status === 200 || response?.data?.verified === true)) {
            if (!verifiedAvailableOnce.value) {
                verifiedAvailableOnce.value = true;
                setErrorState('none');
                retryLoad();
                return;
            }

            const message = serverMessage || 'Unable to load media after retry';
            setErrorState('unavailable', status ?? null, message);
            return;
        }

        const fallback = serverMessage || 'Unable to load media';
        setErrorState('unavailable', status ?? null, fallback);
    } catch (error: any) {
        const status = error?.response?.status as number | undefined;
        if (status === 404) {
            verifiedAvailableOnce.value = false;
            setErrorState('not-found', 404, null);
        } else {
            const message =
                error?.response?.data?.message ||
                error?.message ||
                (status ? `Remote server responded with ${status}` : 'Unable to load media');
            setErrorState('unavailable', status ?? null, message);
        }
    } finally {
        missingReported.value = false;
    }
}

function onPreviewError(event?: Event) {
    const target = event?.target as HTMLVideoElement | HTMLImageElement | null;
    const isVideoElement = target instanceof HTMLVideoElement;
    const treatAsVideo = isVideoElement || (!target && shouldRenderVideo.value);

    if (treatAsVideo) {
        // Attempt up to 3 retries with cache-busting before giving up on videos
        if (retryCount.value < 3) {
            retryCount.value += 1;
            hasLoaded.value = false;
            return;
        }
        // For videos, if there's a preview image available, fall back to it instead of hard failing
        const hasPreview = !!((props.item as any)?.preview as string | undefined);
        if (hasPreview && !useImageFallback.value) {
            useImageFallback.value = true;
            hasLoaded.value = false;
            (props.item as any).not_found = false;
            return; // let the <img> branch load and report seen on load
        }
    }

    // Images (or videos after exhausting retries and without preview fallback): mark missing immediately
    hasLoaded.value = true;
    void reportMissingMedia();
}

function onPreviewVideoCanPlay() {
    hasLoaded.value = true;
    verifiedAvailableOnce.value = false;
    setErrorState('none');
    missingReported.value = false;
    useImageFallback.value = false; // ensure we stick with video once it can play
}

function onPreviewVideoTimeUpdate(e: Event) {
    if (previewReported.value) return;
    const el = e?.target as HTMLVideoElement | null;
    const duration = el?.duration ?? 0;
    const current = el?.currentTime ?? 0;
    if (!duration || !isFinite(duration)) return;
    const threshold = Math.max(0, duration - Math.min(0.25, duration * 0.05));
    if (current >= threshold) {
        void reportPreviewSeen();
    }
}

function onOverlayMouseDown(e: MouseEvent) {
    if (e.altKey && e.button === 1) {
        e.preventDefault();
        e.stopPropagation();
    }
}

function onOverlayAuxClick(e: MouseEvent) {
    // Mirror the card behavior for Alt+Middle favorite when clicking on the overlay
    if (tryHandleAltMiddleFavorite(e)) {
        return;
    }
    // If user middle-clicks the overlay (no Alt), open URL in new tab
    const button = (e as any).button;
    const altKey = (e as any).altKey;
    if (!altKey && button === 1) {
        try { e.preventDefault(); e.stopPropagation(); } catch {}
        openUrlInNewTab();
        return;
    }
}

function retryLoad(fromUser = false) {
    if (fromUser) {
        verifiedAvailableOnce.value = false;
    }
    // Clear flags and bump retry counter to bust the cache and force reload
    hasLoaded.value = false;
    setErrorState('none');
    missingReported.value = false;
    useImageFallback.value = false; // retry the video again
    // If automatic retries already maxed, still allow manual retries indefinitely
    retryCount.value += 1;
}

function openUrlInNewTab(url?: string | null) {
    const raw = url || ((props.item as any)?.original as string | undefined) || ((props.item as any)?.preview as string | undefined) || '';
    if (!raw) { return; }
    try {
        window.open(raw, '_blank', 'noopener,noreferrer');
    } catch {
        // ignore
    }
}

function onActionMenuPathChange(p: string[]): void {
    if (p && p[0] === 'batch' && (hasContainers as any).value) {
        broadcastHighlightForCurrentItem();
    } else {
        clearHighlight();
    }
}

function closeActionPanel(): void {
    actionPanelOpen.value = false;
    clearHighlight();
}
</script>

<template>
    <div
        class="grid-item group relative flex h-full flex-col rounded-md transition-[opacity,box-shadow] duration-150"
        :class="highlightActive ? (isSibling ? ringClass : 'opacity-50') : ''"
        ref="root"
        @mousedown.capture="onCardMouseDown"
        @mouseup.capture="onCardMouseUp"
        @auxclick.capture="onCardAuxClick"
        @mouseenter="isHovering = true"
        @mouseleave="isHovering = false"
    >
        <div class="flex items-center justify-between">
            <Button variant="ghost" class="ml-auto h-8 w-8 p-0" aria-label="More options" @click.stop="toggleActionPanel">
                <MoreHorizontal :size="16" />
            </Button>
            <div class="pl-1" v-if="item?.metadata?.prompt">
                <TooltipProvider>
                    <Tooltip>
                        <TooltipTrigger>
                            <Button variant="ghost" class="h-8 w-8 p-0" aria-label="Show prompt">
                                <Info :size="16" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>
                            <div class="max-w-[520px] whitespace-pre-wrap break-words text-sm space-y-2">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex-1 space-y-2">
                                        <div v-if="moderationInfo" class="text-[11px] font-medium text-amber-600 dark:text-amber-400">
                                            Auto-blacklisted by {{ moderationRuleLabel }}
                                        </div>
                                        <div v-if="highlightedPromptHtml" class="leading-relaxed" v-html="highlightedPromptHtml"></div>
                                        <div v-if="moderationHits.length" class="flex flex-wrap gap-1 pt-1">
                                            <span v-for="t in moderationHits" :key="t" class="rounded bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground">{{ t }}</span>
                                        </div>
                                        <div v-if="moderationTestUrl" class="pt-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                class="h-7 text-xs"
                                                as="a"
                                                :href="moderationTestUrl"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                @click.stop
                                            >
                                                <TestTube :size="12" class="mr-1.5" />
                                                Test Rule
                                            </Button>
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 flex-col gap-1">
                                        <Button
                                            v-if="item?.metadata?.prompt"
                                            variant="ghost"
                                            size="sm"
                                            class="h-6 w-6 p-0"
                                            aria-label="Copy prompt"
                                            @click.stop="() => copyToClipboard(String(item?.metadata?.prompt || ''))"
                                        >
                                            <Copy :size="14" />
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </div>
        </div>
        <!-- Action panel overlay: fixed to viewport but horizontally aligned to the card; vertical near cursor -->
        <Teleport to="body">
            <div v-if="actionPanelOpen" ref="overlayRef" class="pointer-events-auto z-[1000]" :style="panelFixedStyle" @mousedown.capture="onOverlayMouseDown" @auxclick.capture="onOverlayAuxClick">
                <ActionMenu
                    :open="true"
                    :options="menuRoot"
                    :initialPathLabels="initialPathLabels || undefined"
                    @path-change="onActionMenuPathChange"
                    @close="closeActionPanel"
                />
            </div>
        </Teleport>

        <!-- Media wrapper to scope loaders and highlights to the preview area -->
        <div class="relative flex-1 overflow-hidden cursor-zoom-in cursor-zoom-custom" @contextmenu="onContextMenu">
            <div
                v-if="isResolutionRequired"
                class="absolute inset-0 z-[805] grid place-items-center bg-background/65 text-muted-foreground"
                data-testid="grid-item-resolving-overlay"
            >
                <div class="flex flex-col items-center gap-2 text-xs font-medium">
                    <LoaderOverlay />
                    <span>Resolving…</span>
                </div>
            </div>

            <!-- Loader overlay while waiting for visibility or until media is ready; fades out -->
            <div
                v-if="isVisible && !hasLoaded && shouldAttemptMediaLoad"
                class="pointer-events-none absolute inset-0 grid place-items-center"
            >
                <LoaderOverlay />
            </div>

            <!-- Missing or error overlay -->
            <div
                v-if="showErrorOverlay"
                class="absolute inset-0 z-[800] grid place-items-center bg-background/60 text-destructive"
                data-testid="grid-item-error-overlay"
                @click="onCardClick"
                @mousedown.capture="onCardMouseDown"
                @mouseup.capture="onCardMouseUp"
                @auxclick.capture="onCardAuxClick"
                @contextmenu="onContextMenu"
            >
                <div class="flex flex-col items-center text-center">
                    <component :is="overlayIcon" :size="42" />
                    <span class="mt-1 text-xs font-medium">{{ overlayMessage }}</span>
                    <div v-if="overlayDetails" class="mt-1 max-w-[220px] text-[11px] font-normal text-muted-foreground">
                        {{ overlayDetails }}
                    </div>
                    <div class="mt-2 flex gap-2">
                        <Button
                            class="h-7 px-2 text-xs"
                            variant="outline"
                            @click.stop.prevent="retryLoad(true)"
                        >Retry</Button>
                        <Button
                            class="h-7 px-2 text-xs"
                            variant="outline"
                            @click.stop.prevent="openUrlInNewTab"
                        >Open</Button>
                    </div>
                </div>
            </div>

            <video
                v-if="isVisible && shouldAttemptMediaLoad && shouldRenderVideo"
                :key="videoSrc"
                :src="videoSrc"
                ref="videoEl"
                referrerpolicy="no-referrer"
                class="h-full w-full cursor-zoom-in cursor-zoom-custom object-cover transition-opacity duration-300"
                :class="hasLoaded ? 'opacity-100' : 'opacity-0'"
                autoplay
                loop
                muted
                playsinline
                preload="auto"
                @canplay="onPreviewVideoCanPlay"
                @timeupdate="onPreviewVideoTimeUpdate"
                @error="onPreviewError"
                @mousedown="onCardMouseDown"
                @mouseup="onCardMouseUp"
                @click="onCardClick"
                @auxclick="onCardAuxClick"
                @contextmenu="onContextMenu"
            ></video>
            <img
                v-else-if="isVisible && shouldAttemptMediaLoad"
                :key="imageSrc"
                :src="imageSrc"
                alt=""
                referrerpolicy="no-referrer"
                class="h-full w-full cursor-zoom-in cursor-zoom-custom object-cover transition-opacity duration-300"
                :class="hasLoaded ? 'opacity-100' : 'opacity-0'"
                @load="onPreviewImageLoad"
                @error="onPreviewError"
                @mousedown="onCardMouseDown"
                @mouseup="onCardMouseUp"
                @click="onCardClick"
                @auxclick="onCardAuxClick"
                @contextmenu="onContextMenu"
            />

            <!-- Source chips: show counts on the source item only -->
            <div
                v-if="highlightActive && isSource && sourceEntries.length > 0 && !hoverBadgeHighlightActive"
                class="pointer-events-none absolute top-2 left-2 z-[700] flex flex-wrap items-center gap-2"
            >
                <ContainerBadge
                    v-for="e in sourceEntries"
                    :key="e.key + ':' + e.value"
                    :label="e.label"
                    :count="e.count"
                    :slot-index="e.slotIndex"
                />
            </div>

            <!-- Hover batch badges -->
            <div
                v-if="isHovering && hoverBatchEntries.length > 0 && !(highlightActive && isSource && !hoverBadgeHighlightActive)"
                class="pointer-events-auto absolute top-2 left-2 z-[720] flex flex-wrap items-center gap-2"
            >
                <ContainerBadge
                    v-for="entry in hoverBatchEntries"
                    :key="entry.scope.key + ':' + entry.scope.value"
                    :label="entry.label"
                    :count="entry.count"
                    :slot-index="entry.slotIndex"
                    class="cursor-pointer select-none shadow-md ring-1 ring-black/10"
                    data-testid="hover-batch-badge"
                    data-batch-badge="true"
                    :data-container-key="entry.scope.key"
                    :data-container-value="String(entry.scope.value)"
                    @mouseenter="handleBatchBadgeMouseEnter(entry)"
                    @mouseleave="handleBatchBadgeMouseLeave"
                    @mousedown.capture="handleBatchBadgeMouseDown(entry.scope, $event)"
                    @auxclick.capture="handleBatchBadgeAuxClick(entry.scope, $event)"
                    @contextmenu="handleBatchBadgeContextMenu(entry.scope, $event)"
                    @click.stop.prevent
                />
            </div>
        </div>

        <div class="flex w-full items-center gap-2 px-2">
            <FileReactions
                v-if="item"
                :file="fileForReactions ?? { id: item.id }"
                :size="18"
                @favorite="(emittedFile, emittedEvent) => emit('favorite', item, emittedEvent)"
                @like="(emittedFile, emittedEvent) => emit('like', item, emittedEvent)"
                @dislike="(emittedFile, emittedEvent) => emit('dislike', item, emittedEvent)"
                @laughed-at="(emittedFile, emittedEvent) => emit('laughed-at', item, emittedEvent)"
            />
            <div class="ml-auto flex items-center gap-3 text-xs text-muted-foreground">
                <div class="flex items-center gap-1">
                    <Eye :size="18" :class="previewedCountColorClass" />
                    <span :class="previewedCountColorClass">{{ item?.previewed_count ?? 0 }}</span>
                </div>
                <div class="flex items-center gap-1">
                    <ZoomIn :size="18" />
                    <span>{{ item?.seen_count ?? 0 }}</span>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/**** Performance: reduce offscreen work without affecting in-viewport animations ****/
.grid-item { content-visibility: auto; contain-intrinsic-size: 240px 240px; }

/* Custom zoom cursor for media hover with fallback (center hotspot at 16 16; adjust if needed) */
.cursor-zoom-custom { cursor: url('../../../svg/zoom.svg') 16 16, zoom-in; }
</style>
