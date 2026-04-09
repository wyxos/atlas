import type { FeedItem } from '@/composables/useTabs';
import type { ReactionType } from '@/types/reaction';

type BrowseV2MouseShortcutOptions = {
    getCurrentItem: () => FeedItem | null;
    getVisibleItems: () => FeedItem[];
    getSurfaceMode: () => 'fullscreen' | 'list';
    onReaction: (item: FeedItem, type: ReactionType) => void | Promise<void>;
};

export function createBrowseV2MouseShortcutHandlers(options: BrowseV2MouseShortcutOptions) {
    function getShortcutItemFromTarget(target: EventTarget | null): FeedItem | null {
        if (options.getSurfaceMode() === 'fullscreen') {
            return options.getCurrentItem();
        }

        if (!(target instanceof Element)) {
            return null;
        }

        const itemElement = target.closest<HTMLElement>('[data-item-id]');
        const itemId = Number(itemElement?.dataset.itemId ?? '');
        if (!Number.isFinite(itemId)) {
            return null;
        }

        return options.getVisibleItems().find((item) => item.id === itemId) ?? null;
    }

    function isShortcutSuppressedTarget(target: EventTarget | null): boolean {
        if (!(target instanceof Element)) {
            return false;
        }

        if (target.closest('[data-testid="vibe-list-card-open"]')) {
            return false;
        }

        if (target.closest('[data-container-pill-trigger]')) {
            return true;
        }

        return Boolean(target.closest('button,input,select,textarea,a,[role="button"],[role="slider"],[data-swipe-lock="true"]'));
    }

    function openOriginalItem(item: FeedItem): void {
        const url = item.original || item.originalUrl || item.preview || item.src || item.url;
        if (!url) {
            return;
        }

        try {
            window.open(url, '_blank', 'noopener,noreferrer');
        } catch {
            // Ignore popup errors.
        }
    }

    function handleClickCapture(event: MouseEvent): void {
        if (!event.altKey || event.button !== 0 || isShortcutSuppressedTarget(event.target)) {
            return;
        }

        const item = getShortcutItemFromTarget(event.target);
        if (!item) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        void options.onReaction(item, 'like');
    }

    function handleContextMenuCapture(event: MouseEvent): void {
        if (!event.altKey || isShortcutSuppressedTarget(event.target)) {
            return;
        }

        const item = getShortcutItemFromTarget(event.target);
        if (!item) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        void options.onReaction(item, 'dislike');
    }

    function handleMouseDownCapture(event: MouseEvent): void {
        if (event.button !== 1 || isShortcutSuppressedTarget(event.target)) {
            return;
        }

        const item = getShortcutItemFromTarget(event.target);
        if (!item) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        if (event.altKey) {
            void options.onReaction(item, 'love');
        }
    }

    function handleAuxClickCapture(event: MouseEvent): void {
        if (event.button !== 1 || event.altKey || isShortcutSuppressedTarget(event.target)) {
            return;
        }

        const item = getShortcutItemFromTarget(event.target);
        if (!item) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        openOriginalItem(item);
    }

    return {
        handleAuxClickCapture,
        handleClickCapture,
        handleContextMenuCapture,
        handleMouseDownCapture,
    };
}
