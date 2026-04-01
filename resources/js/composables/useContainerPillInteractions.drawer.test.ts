import { describe, expect, it, vi } from 'vitest';
import { computed, ref } from 'vue';
import { useContainerPillInteractions } from './useContainerPillInteractions';
import type { FeedItem } from './useTabs';

vi.mock('@/utils/reactions', () => ({
    createReactionCallback: vi.fn(() => vi.fn()),
}));

vi.mock('@/utils/reactionQueue', () => ({
    queueBatchReaction: vi.fn(),
}));

function createItem(id: number): FeedItem {
    return {
        id,
        width: 500,
        height: 500,
        page: 1,
        key: `1-${id}`,
        index: id - 1,
        src: `https://example.com/image${id}.jpg`,
        containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
    } as FeedItem;
}

function createLeftClickEvent(type: 'click' | 'dblclick' = 'click'): MouseEvent {
    return {
        button: 0,
        altKey: false,
        ctrlKey: false,
        metaKey: false,
        shiftKey: false,
        type,
        preventDefault: vi.fn(),
        stopPropagation: vi.fn(),
    } as MouseEvent;
}

describe('useContainerPillInteractions drawer callbacks', () => {
    const onlineMode = computed(() => false);
    const mockOnReaction = vi.fn();

    it('handles single left click without modifiers to trigger the drawer callback', () => {
        vi.useFakeTimers();
        const items = ref<FeedItem[]>([createItem(1)]);
        const onPlainLeftClick = vi.fn();

        const { handlePillClick } = useContainerPillInteractions({
            items,
            masonry: ref({}),
            tabId: 1,
            isLocal: onlineMode,
            onReaction: mockOnReaction,
            onPlainLeftClick,
        });

        handlePillClick(1, createLeftClickEvent());
        expect(onPlainLeftClick).not.toHaveBeenCalled();

        vi.advanceTimersByTime(300);
        vi.runOnlyPendingTimers();

        expect(onPlainLeftClick).toHaveBeenCalledWith(
            expect.objectContaining({ id: 1, type: 'gallery' }),
        );

        vi.useRealTimers();
    });

    it('does not trigger the drawer callback on double left click', async () => {
        vi.useFakeTimers();
        const mockRemoveMany = vi.fn().mockResolvedValue(undefined);
        const items = ref<FeedItem[]>([createItem(1), createItem(2)]);
        const onPlainLeftClick = vi.fn();

        const { handlePillClick } = useContainerPillInteractions({
            items,
            masonry: ref({
                remove: mockRemoveMany,
            }),
            tabId: 1,
            isLocal: onlineMode,
            onReaction: mockOnReaction,
            onPlainLeftClick,
        });

        handlePillClick(1, createLeftClickEvent(), false);
        handlePillClick(1, createLeftClickEvent('dblclick'), true);

        await Promise.resolve();
        vi.advanceTimersByTime(300);
        vi.runOnlyPendingTimers();

        expect(onPlainLeftClick).not.toHaveBeenCalled();
        expect(mockRemoveMany).toHaveBeenCalled();

        vi.useRealTimers();
    });
});
