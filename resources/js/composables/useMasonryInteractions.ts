import type { MasonryItem } from './useBrowseTabs';
import type { ReactionType } from '@/types/reaction';
import type { Masonry } from '@wyxos/vibe';

/**
 * Composable for handling masonry item interactions (mouse events, alt-click reactions).
 */
export function useMasonryInteractions(
    items: import('vue').Ref<MasonryItem[]>,
    masonry: import('vue').Ref<InstanceType<typeof Masonry> | null>,
    handleMasonryReaction: (fileId: number, type: ReactionType) => Promise<void>
) {
    // Handle ALT + mouse button combinations for quick reactions
    function handleAltClickReaction(e: MouseEvent, fileId: number): void {
        // Prevent default behavior and stop propagation
        e.preventDefault();
        e.stopPropagation();

        let reactionType: ReactionType | null = null;

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
            if (item && masonry.value?.remove) {
                handleMasonryReaction(fileId, reactionType);
            }
        }
    }

    // Handle ALT + click on masonry background
    function handleAltClickOnMasonry(e: MouseEvent): void {
        if (!e.altKey) return;

        const target = e.target as HTMLElement;
        const img = target.closest('.masonry-item')?.querySelector('img') as HTMLImageElement;
        if (!img) return;

        const itemSrc = img.src;
        const item = items.value.find((item) => {
            const baseSrc = item.src || item.thumbnail || '';
            return baseSrc === itemSrc || baseSrc.includes(itemSrc) || itemSrc.includes(baseSrc);
        });
        if (item) {
            handleAltClickReaction(e, item.id);
        }
    }

    // Handle middle click mousedown on masonry item (prevent default to avoid browser scroll)
    function handleMasonryItemMouseDown(e: MouseEvent): void {
        // Middle click without ALT - prevent default to avoid browser scroll
        // Actual opening will be handled in auxclick
        if (!e.altKey && e.button === 1) {
            e.preventDefault();
            e.stopPropagation();
        }
    }

    // Handle middle click (auxclick) on masonry item to open original URL
    function handleMasonryItemAuxClick(e: MouseEvent, item: MasonryItem): void {
        // Middle click without ALT - open original URL
        if (!e.altKey && e.button === 1) {
            e.preventDefault();
            e.stopPropagation();

            const url = item.originalUrl || item.src;
            if (url) {
                try {
                    window.open(url, '_blank', 'noopener,noreferrer');
                } catch {
                    // ignore
                }
            }
        }
    }

    // Open original URL in new tab
    function openOriginalUrl(item: MasonryItem): void {
        const url = item.originalUrl || item.src;
        if (url) {
            try {
                window.open(url, '_blank', 'noopener,noreferrer');
            } catch {
                // ignore
            }
        }
    }

    return {
        handleAltClickReaction,
        handleAltClickOnMasonry,
        handleMasonryItemMouseDown,
        handleMasonryItemAuxClick,
        openOriginalUrl,
    };
}

