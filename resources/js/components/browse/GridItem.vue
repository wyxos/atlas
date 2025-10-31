<script setup lang="ts">
import FileReactions from '@/components/audio/FileReactions.vue';
import { Button } from '@/components/ui/button';
import LoaderOverlay from '@/components/ui/LoaderOverlay.vue';
import { Eye, ZoomIn, MoreHorizontal, User, Newspaper, Book, BookOpen, Palette, Tag, Info, ImageOff } from 'lucide-vue-next';
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import ActionMenu, { type ActionOption } from '@/components/browse/ActionMenu.vue';
import { bus } from '@/lib/bus';
import { ringForSlot, badgeClassForSlot } from '@/pages/browse/highlight'
import { createBatchReact, type BatchAction, type BatchScope } from '@/pages/browse/useBatchReact'
import * as BrowseController from '@/actions/App/Http/Controllers/BrowseController'
import axios from 'axios'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import { IO_VISIBILITY_ROOT_MARGIN, IO_VISIBILITY_THRESHOLD } from '@/lib/visibility'
import { highlightPromptHtml } from '@/utils/moderationHighlight'

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

function bustUrl(u: string, n: number): string {
    try {
        const url = new URL(u, window.location.origin);
        url.searchParams.set('retry', String(n));
        url.searchParams.set('ts', String(Date.now()));
        return url.toString();
    } catch {
        const sep = u.includes('?') ? '&' : '?';
        return `${u}${sep}retry=${n}&ts=${Date.now()}`;
    }
}

const imageSrc = computed(() => {
    const p = (props.item as any)?.preview as string | undefined;
    if (!p) { return ''; }
    return retryCount.value > 0 ? bustUrl(p, retryCount.value) : p;
});

const videoSrc = computed(() => {
    const src = ((props.item as any)?.original as string | undefined) || ((props.item as any)?.preview as string | undefined);
    if (!src) { return ''; }
    return retryCount.value > 0 ? bustUrl(src, retryCount.value) : src;
});

// Force the video element to reload when src changes
watch(videoSrc, () => {
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
    if (!isSource.value) return [] as Array<{ key: string; label: string; value: string | number; count: number; slotIndex: number }>;
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

function broadcastHighlightForCurrentItem() {
    const entries = computeContainerEntries();
    if (!entries.length) return; // no effect when no siblings
    bus.emit('browse:highlight-containers', { sourceId: (props.item as any)?.id, entries });
}

function clearHighlight() {
    bus.emit('browse:clear-highlight');
}

// Subscribe to highlight events
bus.on('browse:highlight-containers', (payload) => {
    highlightTargets.value = payload || null;
});

bus.on('browse:clear-highlight', () => {
    highlightTargets.value = null;
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

function containerScopes(): { label: string; scope: BatchScope; badge?: { text: string; class: string } }[] {
    const uniqueKeyMap: Record<string, boolean> = {};
    const containers = listItemContainers(props.item);
    return containers
        .filter((container) => {
            const keyString = `${container.key}:${container.value}`;
            if (uniqueKeyMap[keyString]) return false;
            uniqueKeyMap[keyString] = true;
            return true;
        })
        .map((container, index) => {
            const inner = containerCounts.get(container.key);
            const countAll = inner ? (inner.get(container.value) ?? 1) : 1;
            return {
                label: container.label,
                scope: { key: container.key, value: container.value },
                badge: { text: String(countAll), class: badgeClassForSlot(index) },
            };
        });
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
    // Fallback for browsers that don't dispatch auxclick for middle button
    if (tryHandleAltMiddleFavorite(e)) return;
}

function onCardAuxClick(mouseEvent: MouseEvent) {
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

// Context dropdown removed; using ActionMenu component

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
    (props.item as any).not_found = false;
    missingReported.value = false;
    errorMessage.value = null;
    void reportPreviewSeen();
}

async function reportMissingMedia() {
    if (missingReported.value) { return; }
    const id = (props.item as any)?.id as number | undefined;
    if (!id) { return; }
    missingReported.value = true;
    try {
        const action = (BrowseController as any).reportMissing({ file: id });
        if (!action?.url) {
            missingReported.value = false;
            return;
        }
        const response = await axios.post(action.url, { verify: true });
        const confirmedMissing = response?.data?.not_found === true;
        (props.item as any).not_found = confirmedMissing;
        
        // Store the actual error message if not a 404
        if (confirmedMissing) {
            errorMessage.value = null; // It's a genuine 404, use default "Not found" message
        } else {
            // If the server says it's not missing but we still had an error, show a generic error
            errorMessage.value = 'Failed to load media';
        }
        
        if (!confirmedMissing) {
            missingReported.value = false;
            if (retryCount.value <= 3) {
                retryLoad();
            }
        }
    } catch (error: any) {
        // Capture the actual error message from the response
        if (error?.response?.status === 404) {
            (props.item as any).not_found = true;
            errorMessage.value = null; // Use default "Not found" message
        } else {
            (props.item as any).not_found = true;
            // Extract error message from various possible sources
            errorMessage.value = error?.response?.data?.message 
                || error?.message 
                || `Error ${error?.response?.status || 'loading media'}`;
        }
        missingReported.value = false;
    }
}

function onPreviewError() {
    const isVideo = (props.item as any)?.type === 'video';
    if (isVideo) {
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
    // Images: mark missing immediately (no retries) to align with UI/tests
    hasLoaded.value = true;
    void reportMissingMedia();
}

function onPreviewVideoCanPlay() {
    hasLoaded.value = true;
    (props.item as any).not_found = false;
    missingReported.value = false;
    errorMessage.value = null;
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

function retryLoad() {
    // Clear flags and bump retry counter to bust the cache and force reload
    hasLoaded.value = false;
    (props.item as any).not_found = false;
    missingReported.value = false;
    errorMessage.value = null;
    useImageFallback.value = false; // retry the video again
    // If automatic retries already maxed, still allow manual retries indefinitely
    retryCount.value += 1;
}

function openUrlInNewTab() {
    const raw = ((props.item as any)?.original as string | undefined) || ((props.item as any)?.preview as string | undefined) || '';
    if (!raw) { return; }
    const url = retryCount.value > 0 ? bustUrl(raw, retryCount.value) : raw;
    try {
        window.open(url, '_blank', 'noopener');
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
                                <div v-if="moderationInfo" class="text-[11px] font-medium text-amber-600 dark:text-amber-400">
                                    Auto-blacklisted by {{ moderationRuleLabel }}
                                </div>
                                <div v-if="highlightedPromptHtml" class="leading-relaxed" v-html="highlightedPromptHtml"></div>
                                <div v-if="moderationHits.length" class="flex flex-wrap gap-1 pt-1">
                                    <span v-for="t in moderationHits" :key="t" class="rounded bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground">{{ t }}</span>
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
        <div class="relative flex-1 overflow-hidden cursor-zoom-in cursor-zoom-custom">
            <!-- Loader overlay while waiting for visibility or until media is ready; fades out -->
            <div
                v-if="isVisible && !hasLoaded"
                class="absolute inset-0 grid place-items-center"
            >
                <LoaderOverlay />
            </div>

            <!-- Missing overlay -->
            <div v-if="item?.not_found" class="absolute inset-0 z-[800] grid place-items-center bg-background/60 text-destructive">
                <div class="flex flex-col items-center">
                    <ImageOff :size="42" />
                    <span class="mt-1 text-xs font-medium">{{ errorMessage || 'Not found' }}</span>
                    <div class="mt-2 flex gap-2">
                        <Button
                            class="h-7 px-2 text-xs"
                            variant="outline"
                            @click.stop.prevent="retryLoad"
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
                v-if="isVisible && item?.type === 'video' && !useImageFallback"
                :key="videoSrc"
                :src="videoSrc"
                ref="videoEl"
                class="h-full w-full cursor-zoom-in cursor-zoom-custom object-cover transition-opacity duration-300"
                :class="hasLoaded ? 'opacity-100' : 'opacity-0'"
                autoplay
                loop
                muted
                playsinline
                preload="metadata"
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
                v-else-if="isVisible"
                :key="imageSrc"
                :src="imageSrc"
                alt=""
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
                v-if="highlightActive && isSource && sourceEntries.length > 0"
                class="pointer-events-none absolute top-2 left-2 z-[700] flex flex-wrap items-center gap-2"
            >
                <template v-for="e in sourceEntries" :key="e.key + ':' + e.value">
                    <div
                        class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-[11px] font-medium text-background"
                        :class="badgeClassForSlot(e.slotIndex)"
                    >
                        <span>{{ e.label }} · {{ e.count }}</span>
                    </div>
                </template>
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
