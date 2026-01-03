import type { Ref } from 'vue';
import { Masonry } from '@wyxos/vibe';
import type { FeedItem } from '@/composables/useTabs';
import type { ReactionType } from '@/types/reaction';

export function createMasonryInteractions(
    items: Ref<FeedItem[]>,
    masonry: Ref<InstanceType<typeof Masonry> | null>,
    handleMasonryReaction: (item: FeedItem, type: ReactionType, index?: number) => Promise<void>
) {
    function handleAltClickReaction(e: MouseEvent, item: FeedItem, index?: number): void {
        e.preventDefault();
        e.stopPropagation();

        let reactionType: ReactionType | null = null;

        if (e.button === 0 || (e.type === 'click' && e.button === 0)) {
            reactionType = 'like';
        } else if (e.button === 2 || e.type === 'contextmenu') {
            reactionType = 'dislike';
        } else if (e.button === 1) {
            reactionType = 'love';
        }

        if (!reactionType) {
            return;
        }

        // Pass item directly - this is the exact object reference that Vibe is tracking
        // Pass index if available to avoid findIndex lookup
        void handleMasonryReaction(item, reactionType, index);
    }

    function handleMasonryItemMouseDown(e: MouseEvent): void {
        if (!e.altKey && e.button === 1) {
            e.preventDefault();
            e.stopPropagation();
        }
    }

    function handleMasonryItemAuxClick(e: MouseEvent, item: FeedItem): void {
        if (!e.altKey && e.button === 1) {
            e.preventDefault();
            e.stopPropagation();

            const url = item.originalUrl || item.src;
            if (!url) {
                return;
            }

            try {
                window.open(url, '_blank', 'noopener,noreferrer');
            } catch {
                // ignore
            }
        }
    }

    function openOriginalUrl(item: FeedItem): void {
        const url = item.originalUrl || item.src;
        if (!url) {
            return;
        }

        try {
            window.open(url, '_blank', 'noopener,noreferrer');
        } catch {
            // ignore
        }
    }

    return {
        handleAltClickReaction,
        handleMasonryItemMouseDown,
        handleMasonryItemAuxClick,
        openOriginalUrl,
    };
}
