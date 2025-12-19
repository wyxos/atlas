import { ref, type Ref } from 'vue';
import type { MasonryItem } from './useBrowseTabs';
import { useReactionQueue } from './useReactionQueue';
import { createReactionCallback } from '@/utils/reactions';
import type { ReactionType } from '@/types/reaction';

type Container = {
    id: number;
    type: string;
    source?: string;
    source_id?: string;
    referrer?: string;
};

/**
 * Composable for handling container pill interactions (clicks, batch reactions, etc.).
 */
export function useContainerPillInteractions(
    items: Ref<MasonryItem[]>,
    masonry: Ref<{ removeMany?: (items: MasonryItem[]) => Promise<void> } | null>,
    tabId: number | undefined,
    onReaction: (fileId: number, type: ReactionType) => void,
    restoreManyToMasonry?: (
        itemsToRestore: Array<{ item: MasonryItem; index: number }>,
        masonryInstance?: any
    ) => Promise<void>
) {
    const { queueReaction } = useReactionQueue();
    const lastClickTime = ref<{ containerId: number; timestamp: number; button: number } | null>(null);
    const DOUBLE_CLICK_DELAY_MS = 300; // Maximum time between clicks to count as double-click

    /**
     * Get full container data for an item (including referrer URL).
     */
    function getContainersForItem(item: MasonryItem): Container[] {
        const containers = (item as any).containers || [];
        return containers.filter((container: any) => container?.id && container?.type) as Container[];
    }

    /**
     * Get all sibling items (items with the same container ID).
     */
    function getSiblingItems(containerId: number): MasonryItem[] {
        return items.value.filter((item) => {
            const containers = getContainersForItem(item);
            return containers.some((container) => container.id === containerId);
        });
    }

    /**
     * Get the referrer URL for a container (from the first item that has this container).
     */
    function getContainerUrl(containerId: number): string | null {
        for (const item of items.value) {
            const containers = getContainersForItem(item);
            const container = containers.find((c) => c.id === containerId);
            if (container?.referrer) {
                return container.referrer;
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

        // IMPORTANT: Capture indices BEFORE removing items
        // Collect items with their original indices for batch restore (before removal)
        const itemsToRestore = siblings.map((item) => {
            const itemIndex = items.value.findIndex((i) => i.id === item.id);
            return { item, index: itemIndex !== -1 ? itemIndex : items.value.length };
        });

        // Remove auto_disliked and will_auto_dislike flags if user is reacting (like, funny, favorite - not dislike)
        if (reactionType === 'love' || reactionType === 'like' || reactionType === 'funny') {
            for (const item of siblings) {
                const itemIndex = items.value.findIndex((i) => i.id === item.id);
                if (itemIndex !== -1) {
                    Object.assign(items.value[itemIndex], {
                        auto_disliked: false,
                        will_auto_dislike: false,
                    });
                }
            }
        }

        // Use removeMany for efficient batch removal
        if (masonry.value?.removeMany) {
            await masonry.value.removeMany(siblings);
        } else {
            console.warn('[useContainerPillInteractions] removeMany not available on masonry instance');
        }

        // Create a batch ID for grouping these reactions
        const batchId = `batch-${containerId}-${reactionType}-${Date.now()}`;

        // Create batch restore callback if restoreManyToMasonry is available
        const batchRestoreCallback = restoreManyToMasonry && tabId !== undefined
            ? async (restoreTabId: number, isTabActive: (tabId: number) => boolean) => {
                // Only restore if the tab is active
                const tabActive = isTabActive(restoreTabId);
                if (!tabActive) {
                    return;
                }

                // Restore all items in the batch using restoreManyToMasonry
                await restoreManyToMasonry(itemsToRestore, masonry.value);
            }
            : undefined;

        // Queue all reactions first, then the batch toast will be created/updated once
        // This ensures we don't create multiple toasts when queuing synchronously
        const reactionsToQueue = siblings.map((item) => {
            const itemIndex = items.value.findIndex((i) => i.id === item.id);
            const previewUrl = item.src;
            return {
                fileId: item.id,
                itemIndex,
                previewUrl,
                item,
            };
        });

        // Queue all reactions with the same batchId
        // The useReactionQueue will handle creating/updating a single batch toast
        // IMPORTANT: Don't call onReaction for each item - it might create individual toasts
        // The batch toast will be created by useReactionQueue, and onReaction is just a callback
        // that should be called once for the batch, not per item
        for (const { fileId, itemIndex, previewUrl, item } of reactionsToQueue) {
            queueReaction(
                fileId,
                reactionType,
                createReactionCallback(),
                previewUrl,
                undefined, // No individual restore callback for batch reactions
                tabId,
                itemIndex,
                item,
                batchId, // Pass batchId to group these reactions
                batchRestoreCallback // Pass batch restore callback
            );
        }

        // Call onReaction once for the batch (not per item) to avoid duplicate toasts
        // This is just a callback to notify parent, not to create toasts
        // The actual toast is created by useReactionQueue when batchId is provided
        if (siblings.length > 0) {
            // Call onReaction for the first item as a representative of the batch
            // This maintains compatibility but doesn't create individual toasts
            onReaction(siblings[0].id, reactionType);
        }
    }

    /**
     * Handle middle click to open container URL in new tab without focus.
     */
    function handleMiddleClick(containerId: number, e: MouseEvent): void {
        e.preventDefault();
        e.stopPropagation();

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

        // Middle click without alt - open URL (unless it's a double-click)
        if (e.button === 1 && !e.altKey && !isDoubleClick) {
            e.preventDefault();
            handleMiddleClick(containerId, e);
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
            lastClickTime.value = null; // Reset after handling
        } else {
            // Track click time and button for double-click detection
            lastClickTime.value = { containerId, timestamp: now, button: e.button };
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
            // Alt + Right Click or Double Right Click = Dislike
            else if (buttonToCheck === 2 || e.type === 'contextmenu') {
                reactionType = 'dislike';
            }
            // Alt + Middle Click or Double Middle Click = Favorite (Love)
            else if (buttonToCheck === 1) {
                reactionType = 'love';
            }
        }

        if (reactionType) {
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
                lastClickTime.value = null; // Reset after handling
                e.preventDefault();
                batchReactToSiblings(containerId, 'love');
            } else if (e.altKey) {
                // Alt + Middle Click = Favorite (Love) all siblings
                e.preventDefault();
                batchReactToSiblings(containerId, 'love');
            } else {
                // Middle click without alt and not double - open URL
                lastClickTime.value = { containerId, timestamp: now, button: 1 };
                e.preventDefault();
                handleMiddleClick(containerId, e);
            }
        }
    }

    return {
        getContainersForItem,
        getSiblingItems,
        getContainerUrl,
        batchReactToSiblings,
        handlePillClick,
        handlePillAuxClick,
    };
}

