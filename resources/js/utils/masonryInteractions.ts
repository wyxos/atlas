import type { Ref } from 'vue';
import type { Masonry } from '@wyxos/vibe';
import type { MasonryItem } from '@/composables/useTabs';
import type { ReactionType } from '@/types/reaction';

export function createMasonryInteractions(
    items: Ref<MasonryItem[]>,
    masonry: Ref<InstanceType<typeof Masonry> | null>,
    handleMasonryReaction: (fileId: number, type: ReactionType) => Promise<void>
) {
    function handleAltClickReaction(e: MouseEvent, fileId: number): void {
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

        const item = items.value.find((i) => i.id === fileId);
        if (!item || !masonry.value?.remove) {
            return;
        }

        void handleMasonryReaction(fileId, reactionType);
    }

    function handleMasonryItemMouseDown(e: MouseEvent): void {
        if (!e.altKey && e.button === 1) {
            e.preventDefault();
            e.stopPropagation();
        }
    }

    function handleMasonryItemAuxClick(e: MouseEvent, item: MasonryItem): void {
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

    function openOriginalUrl(item: MasonryItem): void {
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
