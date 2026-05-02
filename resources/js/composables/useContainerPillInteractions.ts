import { computed, getCurrentInstance, onUnmounted, ref, triggerRef, type ComputedRef, type Ref } from 'vue';
import type { FeedItem } from './useTabs';
import { queueBatchBlacklist, queueBatchReaction } from '@/utils/reactionQueue';
import type { ReactionType } from '@/types/reaction';
import type { BrowseFeedHandle } from '@/types/browse';
import {
    applyConfirmedLocalBlacklistState,
    applyOptimisticLocalBlacklistState,
    applyOptimisticLocalReactionState,
    restoreOptimisticLocalReactionState,
    type LocalReactionSnapshot,
} from '@/utils/localReactionState';
import type { BatchBlacklistResult } from '@/utils/reactions';

export type ContainerPillTarget = {
    id: number;
    type: string;
    source?: string;
    source_id?: string;
    referrer?: string;
    browse_tab?: {
        label: string;
        params: Record<string, unknown>;
    } | null;
};

type ContainerEntry = {
    id?: number;
    type?: string;
    source?: string;
    source_id?: string;
    referrer?: string;
    browse_tab?: {
        label: string;
        params: Record<string, unknown>;
    } | null;
};

function isContainerEntry(container: ContainerEntry): container is ContainerPillTarget {
    return typeof container.id === 'number' && typeof container.type === 'string';
}

/**
 * Composable for handling container pill interactions (clicks, batch reactions, etc.).
 */
type ContainerDrawerToggleHandler = (container: ContainerPillTarget) => void;
type OpenContainerTabHandler = (container: ContainerPillTarget) => void;
type UseContainerPillInteractionsOptions = {
    items: Ref<FeedItem[]>;
    getItems?: () => FeedItem[];
    masonry: Ref<BrowseFeedHandle | null>;
    tabId: number | undefined | ComputedRef<number | undefined>;
    isLocal: Readonly<Ref<boolean>>;
    matchesActiveLocalFilters?: (item: FeedItem) => boolean;
    onReaction: (fileId: number, type: ReactionType) => void;
    onOpenContainerTab?: OpenContainerTabHandler;
    onPlainLeftClick?: ContainerDrawerToggleHandler;
};

export function useContainerPillInteractions(
    options: UseContainerPillInteractionsOptions,
) {
    // Unwrap computed/ref to get the actual value
    const tabIdValue = computed(() => (
        typeof options.tabId === 'object' && 'value' in options.tabId ? options.tabId.value : options.tabId
    ));
    const lastClickTime = ref<{ containerId: number; timestamp: number; button: number } | null>(null);
    const DOUBLE_CLICK_DELAY_MS = 300; // Maximum time between clicks to count as double-click
    let pendingMiddleClickTimer: ReturnType<typeof setTimeout> | null = null;
    let pendingLeftClickTimer: ReturnType<typeof setTimeout> | null = null;

    function getInteractionItems(): FeedItem[] {
        return options.getItems?.() ?? options.items.value;
    }

    /**
     * Get full container data for an item (including referrer URL).
     */
    function getContainersForItem(item: FeedItem): ContainerPillTarget[] {
        const containers = (item.containers as ContainerEntry[] | undefined) ?? [];
        return containers.filter(isContainerEntry);
    }

    /**
     * Get all sibling items (items with the same container ID).
     */
    function getSiblingItems(containerId: number): FeedItem[] {
        return getInteractionItems().filter((item) => {
            const containers = getContainersForItem(item);
            return containers.some((container) => container.id === containerId);
        });
    }

    /**
     * Get the referrer URL for a container (from the first item that has this container).
     */
    function getContainerUrl(containerId: number): string | null {
        for (const item of getInteractionItems()) {
            const containers = getContainersForItem(item);
            const container = containers.find((c) => c.id === containerId);
            if (container?.referrer) {
                return container.referrer;
            }
        }
        return null;
    }

    function getContainer(containerId: number): ContainerPillTarget | null {
        for (const item of getInteractionItems()) {
            const containers = getContainersForItem(item);
            const container = containers.find((c) => c.id === containerId);
            if (container) {
                return container;
            }
        }
        return null;
    }

    /**
     * Batch react to all sibling items of a container.
     */
    async function batchReactToSiblings(
        containerId: number,
        reactionType: ReactionType
    ): Promise<void> {
        const siblings = getSiblingItems(containerId);

        if (siblings.length === 0) {
            return;
        }

        const currentTabId = tabIdValue.value;
        const matchesActiveLocalFilters = options.matchesActiveLocalFilters;
        let batchRestoreCallback: (() => Promise<void> | void) | undefined;

        if (options.isLocal.value) {
            const snapshots = new Map<number, LocalReactionSnapshot>();

            for (const sibling of siblings) {
                snapshots.set(sibling.id, applyOptimisticLocalReactionState(sibling, reactionType));
            }

            const itemsToTemporarilyRemove = matchesActiveLocalFilters
                ? siblings.filter((sibling) => !matchesActiveLocalFilters(sibling))
                : [];

            if (itemsToTemporarilyRemove.length > 0 && options.masonry.value) {
                await options.masonry.value.remove(itemsToTemporarilyRemove);
            } else {
                triggerRef(options.items);
            }

            batchRestoreCallback = async () => {
                for (const sibling of siblings) {
                    const snapshot = snapshots.get(sibling.id);

                    if (snapshot) {
                        restoreOptimisticLocalReactionState(sibling, snapshot);
                    }
                }

                triggerRef(options.items);

                if (itemsToTemporarilyRemove.length === 0) {
                    return;
                }

                if (options.masonry.value) {
                    await options.masonry.value.restore(itemsToTemporarilyRemove);
                }
            };
        } else {
            // Only remove from masonry in online mode (not in local mode)
            // Vibe tracks removals and restores internally; restoring does not require indices.
            const removalResult = await options.masonry.value?.remove(siblings);
            if (removalResult && removalResult.ids.length === 0) {
                return;
            }

            batchRestoreCallback = currentTabId !== undefined
                ? async () => {
                    await options.masonry.value?.restore(siblings);
                }
                : undefined;
        }

        // Prepare previews and file IDs for the batch reaction queue
        const fileIds = siblings.map((item) => item.id);
        const previews = siblings.map((item) => ({
            fileId: item.id,
            thumbnail: item.thumbnail || item.src,
        }));

        // Queue batch reaction with countdown toast (pass restore callback for undo/error recovery)
        queueBatchReaction(fileIds, reactionType, previews, batchRestoreCallback, options.items, {
            updateLocalState: false,
        });

        // Call onReaction once for the batch (not per item)
        // This is just a callback to notify parent
        if (siblings.length > 0) {
            // Call onReaction for the first item as a representative of the batch
            // This maintains compatibility
            options.onReaction(siblings[0].id, reactionType);
        }
    }

    async function blacklistSiblings(containerId: number): Promise<void> {
        const siblings = getSiblingItems(containerId);
        if (siblings.length === 0) {
            return;
        }

        const currentTabId = tabIdValue.value;
        const matchesActiveLocalFilters = options.matchesActiveLocalFilters;
        let restoreCallback: (() => Promise<void> | void) | undefined;

        if (options.isLocal.value) {
            const snapshots = new Map<number, LocalReactionSnapshot>();

            for (const sibling of siblings) {
                snapshots.set(sibling.id, applyOptimisticLocalBlacklistState(sibling));
            }

            const itemsToTemporarilyRemove = matchesActiveLocalFilters
                ? siblings.filter((sibling) => !matchesActiveLocalFilters(sibling))
                : [];

            if (itemsToTemporarilyRemove.length > 0 && options.masonry.value) {
                await options.masonry.value.remove(itemsToTemporarilyRemove);
            } else {
                triggerRef(options.items);
            }

            restoreCallback = async () => {
                for (const sibling of siblings) {
                    const snapshot = snapshots.get(sibling.id);

                    if (snapshot) {
                        restoreOptimisticLocalReactionState(sibling, snapshot);
                    }
                }

                triggerRef(options.items);

                if (itemsToTemporarilyRemove.length === 0) {
                    return;
                }

                await options.masonry.value?.restore(itemsToTemporarilyRemove);
            };
        } else {
            const removalResult = await options.masonry.value?.remove(siblings);
            if (removalResult && removalResult.ids.length === 0) {
                return;
            }

            restoreCallback = currentTabId !== undefined
                ? async () => {
                    await options.masonry.value?.restore(siblings);
                }
                : undefined;
        }

        const fileIds = siblings.map((item) => item.id);
        const previews = siblings.map((item) => ({
            fileId: item.id,
            thumbnail: item.thumbnail || item.preview || item.src,
        }));
        const onSuccess = (results: BatchBlacklistResult[]) => {
            const resultMap = new Map(results.map((result) => [result.id, result]));

            for (const sibling of siblings) {
                const result = resultMap.get(sibling.id);

                if (result) {
                    applyConfirmedLocalBlacklistState(sibling, result);
                }
            }

            triggerRef(options.items);
        };

        queueBatchBlacklist(fileIds, previews, restoreCallback, options.items, { onSuccess });
    }

    /**
     * Handle middle click to open container URL in new tab without focus.
     */
    function handleMiddleClick(containerId: number): void {
        if (options.onOpenContainerTab) {
            const container = getContainer(containerId);
            if (container) {
                options.onOpenContainerTab(container);
                return;
            }
        }

        const url = getContainerUrl(containerId);
        if (url) {
            // Open in new tab without focusing it
            // Use window.open with specific features to prevent focus
            const newWindow = window.open(url, '_blank', 'noopener,noreferrer');
            if (newWindow) {
                // Blur the new window to prevent focus
                newWindow.blur();
                // Focus back to current window
                window.focus();
            }
        }
    }

    function cancelPendingMiddleClick(): void {
        if (!pendingMiddleClickTimer) {
            return;
        }
        clearTimeout(pendingMiddleClickTimer);
        pendingMiddleClickTimer = null;
    }

    function cancelPendingLeftClick(): void {
        if (!pendingLeftClickTimer) {
            return;
        }

        clearTimeout(pendingLeftClickTimer);
        pendingLeftClickTimer = null;
    }

    function scheduleMiddleClickOpen(containerId: number): void {
        cancelPendingMiddleClick();
        pendingMiddleClickTimer = setTimeout(() => {
            pendingMiddleClickTimer = null;
            handleMiddleClick(containerId);
        }, DOUBLE_CLICK_DELAY_MS);
    }

    function schedulePlainLeftClick(containerId: number): void {
        if (!options.onPlainLeftClick) {
            return;
        }

        cancelPendingLeftClick();
        pendingLeftClickTimer = setTimeout(() => {
            pendingLeftClickTimer = null;

            const container = getContainer(containerId);
            if (container) {
                options.onPlainLeftClick?.(container);
            }
        }, DOUBLE_CLICK_DELAY_MS);
    }

    /**
     * Handle click events (including alt+click and double-click) for batch reactions.
     */
    function handlePillClick(
        containerId: number,
        e: MouseEvent,
        isDoubleClick: boolean = false
    ): void {
        // Always stop propagation to prevent triggering parent click handlers (like file viewer)
        e.stopPropagation();
        if (e.type === 'contextmenu' || e.button === 2) {
            e.preventDefault();
        }

        if (e.button !== 0 || e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) {
            cancelPendingLeftClick();
        }

        // Middle click without alt - open URL (unless it's a double-click)
        if (e.button === 1 && !e.altKey && !isDoubleClick) {
            e.preventDefault();
            cancelPendingLeftClick();
            scheduleMiddleClickOpen(containerId);
            return;
        }

        // Check for double-click
        const now = Date.now();
        const lastClick = lastClickTime.value;
        const isDouble = isDoubleClick || (
            lastClick?.containerId === containerId &&
            lastClick?.button === e.button &&
            now - lastClick.timestamp < DOUBLE_CLICK_DELAY_MS
        );

        if (isDouble) {
            cancelPendingMiddleClick();
            cancelPendingLeftClick();
            lastClickTime.value = null; // Reset after handling
        } else {
            // Track click time and button for double-click detection
            lastClickTime.value = { containerId, timestamp: now, button: e.button };
        }

        const isPlainLeftClick = e.button === 0
            && !isDouble
            && !e.altKey
            && !e.ctrlKey
            && !e.metaKey
            && !e.shiftKey;

        if (isPlainLeftClick) {
            schedulePlainLeftClick(containerId);
            return;
        }

        // Determine reaction type based on button and modifiers
        let reactionType: ReactionType | null = null;

        // For double-click, use the button from the last click
        const buttonToCheck = isDoubleClick && lastClick ? lastClick.button : e.button;

        // Check if this is a reaction trigger: (Alt + Click) OR (Double Click)
        const isReactionTrigger = e.altKey || isDouble;

        if (isReactionTrigger) {
            // Alt + Left Click or Double Left Click = Like
            if (buttonToCheck === 0) {
                reactionType = 'like';
            }
            // Alt + Right Click or Double Right Click = Blacklist
            else if (buttonToCheck === 2 || e.type === 'contextmenu') {
                cancelPendingMiddleClick();
                e.preventDefault();
                void blacklistSiblings(containerId);

                return;
            }
            // Alt + Middle Click or Double Middle Click = Favorite (Love)
            else if (buttonToCheck === 1) {
                reactionType = 'love';
            }
        }

        if (reactionType) {
            cancelPendingMiddleClick();
            e.preventDefault();
            batchReactToSiblings(containerId, reactionType);
        }
        // If no reaction is triggered, we still stop propagation to prevent file viewer from opening
    }

    /**
     * Handle auxclick (for middle click detection).
     */
    function handlePillAuxClick(containerId: number, e: MouseEvent): void {
        // Always stop propagation to prevent triggering parent click handlers (like file viewer)
        e.stopPropagation();
        cancelPendingLeftClick();

        if (e.button === 1) {
            // Check for double-click on middle button
            const now = Date.now();
            const lastClick = lastClickTime.value;
            const isDouble = (
                lastClick?.containerId === containerId &&
                lastClick?.button === 1 &&
                now - lastClick.timestamp < DOUBLE_CLICK_DELAY_MS
            );

            if (isDouble) {
                // Double Middle Click = Favorite (Love) all siblings
                cancelPendingMiddleClick();
                lastClickTime.value = null; // Reset after handling
                e.preventDefault();
                batchReactToSiblings(containerId, 'love');
            } else if (e.altKey) {
                // Alt + Middle Click = Favorite (Love) all siblings
                cancelPendingMiddleClick();
                e.preventDefault();
                batchReactToSiblings(containerId, 'love');
            } else {
                // Middle click without alt and not double - open URL
                lastClickTime.value = { containerId, timestamp: now, button: 1 };
                e.preventDefault();
                scheduleMiddleClickOpen(containerId);
            }
        }
    }

    if (getCurrentInstance()) {
        onUnmounted(() => {
            cancelPendingMiddleClick();
            cancelPendingLeftClick();
        });
    }

    return {
        getContainersForItem,
        getSiblingItems,
        getContainer,
        getContainerUrl,
        batchReactToSiblings,
        handlePillClick,
        handlePillAuxClick,
    };
}
