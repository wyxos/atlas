import { describe, expect, it, vi, afterEach } from 'vitest';
import type { FeedItem } from '@/composables/useTabs';
import { createBrowseV2MouseShortcutHandlers } from './tabContentV2MouseShortcuts';

function createFeedItem(id: number): FeedItem {
    return {
        id,
        width: 100,
        height: 100,
        page: 1,
        key: `1-${id}`,
        index: 0,
        src: `https://example.com/preview-${id}.jpg`,
        preview: `https://example.com/preview-${id}.jpg`,
        original: `https://example.com/original-${id}.jpg`,
        type: 'image',
        notFound: false,
        previewed_count: 0,
        seen_count: 0,
        containers: [],
    } as FeedItem;
}

describe('tabContentV2MouseShortcuts', () => {
    afterEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    it('does not open the original item when middle-clicking a container pill', () => {
        const item = createFeedItem(1);
        const onReaction = vi.fn();
        const onBlacklist = vi.fn();
        const openSpy = vi.spyOn(window, 'open').mockReturnValue(null);
        const handlers = createBrowseV2MouseShortcutHandlers({
            getCurrentItem: () => item,
            getItemFromTarget: () => item,
            getSurfaceMode: () => 'list',
            onBlacklist,
            onReaction,
        });

        document.body.innerHTML = `
            <article data-item-id="1">
                <div data-container-pill-trigger>
                    <span id="pill-label">User</span>
                </div>
            </article>
        `;

        const target = document.getElementById('pill-label');
        const event = new MouseEvent('auxclick', { button: 1 });
        Object.defineProperty(event, 'target', { value: target, configurable: true });

        handlers.handleAuxClickCapture(event);

        expect(openSpy).not.toHaveBeenCalled();
        expect(onBlacklist).not.toHaveBeenCalled();
        expect(onReaction).not.toHaveBeenCalled();
    });

    it('opens the original item when middle-clicking a normal grid target', () => {
        const item = createFeedItem(1);
        const openSpy = vi.spyOn(window, 'open').mockReturnValue(null);
        const handlers = createBrowseV2MouseShortcutHandlers({
            getCurrentItem: () => item,
            getItemFromTarget: () => item,
            getSurfaceMode: () => 'list',
            onBlacklist: vi.fn(),
            onReaction: vi.fn(),
        });

        document.body.innerHTML = `
            <article data-item-id="1">
                <div id="card-body">Card</div>
            </article>
        `;

        const target = document.getElementById('card-body');
        const event = new MouseEvent('auxclick', { button: 1 });
        Object.defineProperty(event, 'target', { value: target, configurable: true });

        handlers.handleAuxClickCapture(event);

        expect(openSpy).toHaveBeenCalledWith(item.original, '_blank', 'noopener,noreferrer');
    });

    it('uses the caller-provided target resolver for duplicate item ids', () => {
        const firstDuplicate = createFeedItem(1);
        const secondDuplicate = createFeedItem(1);
        secondDuplicate.key = '1-1-duplicate';
        const onBlacklist = vi.fn();
        const onReaction = vi.fn();
        const handlers = createBrowseV2MouseShortcutHandlers({
            getCurrentItem: () => firstDuplicate,
            getItemFromTarget: () => secondDuplicate,
            getSurfaceMode: () => 'list',
            onBlacklist,
            onReaction,
        });

        document.body.innerHTML = `
            <article data-item-id="1" data-occurrence-key="vibe-occurrence-2">
                <div id="card-body">Card</div>
            </article>
        `;

        const target = document.getElementById('card-body');
        const event = new MouseEvent('contextmenu', { altKey: true, button: 2 });
        Object.defineProperty(event, 'target', { value: target, configurable: true });

        handlers.handleContextMenuCapture(event);

        expect(onBlacklist).toHaveBeenCalledWith(secondDuplicate);
        expect(onReaction).not.toHaveBeenCalled();
    });
});
