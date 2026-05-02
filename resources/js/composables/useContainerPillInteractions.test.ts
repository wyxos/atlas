import { describe, it, expect, vi, beforeEach } from 'vitest';
import { ref, computed } from 'vue';
import { useContainerPillInteractions } from './useContainerPillInteractions';
import type { FeedItem } from './useTabs';

const { mockQueueBatchBlacklist, mockQueueBatchReaction } = vi.hoisted(() => ({
    mockQueueBatchBlacklist: vi.fn(),
    mockQueueBatchReaction: vi.fn(),
}));

// Mock dependencies
vi.mock('@/utils/reactions', () => ({
    createReactionCallback: vi.fn(() => vi.fn()),
}));

vi.mock('@/utils/reactionQueue', () => ({
    queueBatchBlacklist: mockQueueBatchBlacklist,
    queueBatchReaction: mockQueueBatchReaction,
}));

describe('useContainerPillInteractions', () => {
    const mockOnReaction = vi.fn();
    const mockRemove = vi.fn().mockResolvedValue(undefined);
    const onlineMode = computed(() => false);

    beforeEach(() => {
        vi.clearAllMocks();
        mockRemove.mockResolvedValue(undefined);
        (window as any).axios = { post: vi.fn() };
    });

    it('uses remove when batch reacting to multiple siblings', async () => {
        const items = ref<FeedItem[]>([
            {
                id: 1,
                width: 500,
                height: 500,
                page: 1,
                key: '1-1',
                index: 0,
                src: 'https://example.com/image1.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as FeedItem,
            {
                id: 2,
                width: 500,
                height: 500,
                page: 1,
                key: '1-2',
                index: 1,
                src: 'https://example.com/image2.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as FeedItem,
            {
                id: 3,
                width: 500,
                height: 500,
                page: 1,
                key: '1-3',
                index: 2,
                src: 'https://example.com/image3.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as FeedItem,
        ]);

        const masonry = ref({
            remove: mockRemove,
        });

        const { batchReactToSiblings } = useContainerPillInteractions({
            items,
            masonry,
            tabId: 1,
            isLocal: onlineMode,
            onReaction: mockOnReaction,
        });

        await batchReactToSiblings(1, 'like');

        // Verify remove was called with all siblings
        expect(mockRemove).toHaveBeenCalledTimes(1);
        expect(mockRemove).toHaveBeenCalledWith([
            expect.objectContaining({ id: 1 }),
            expect.objectContaining({ id: 2 }),
            expect.objectContaining({ id: 3 }),
        ]);
    });

    it('restores Vibe-owned sibling visibility without appending into options.items', async () => {
        const items = ref<FeedItem[]>([
            {
                id: 1,
                width: 500,
                height: 500,
                page: 1,
                key: '1-1',
                index: 0,
                src: 'https://example.com/image1.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as FeedItem,
            {
                id: 2,
                width: 500,
                height: 500,
                page: 1,
                key: '1-2',
                index: 1,
                src: 'https://example.com/image2.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as FeedItem,
            {
                id: 3,
                width: 500,
                height: 500,
                page: 1,
                key: '1-3',
                index: 2,
                src: 'https://example.com/image3.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as FeedItem,
        ]);

        const masonry = ref<any>({
            remove: vi.fn().mockResolvedValue(undefined),
            restore: vi.fn().mockResolvedValue(undefined),
        });

        const { batchReactToSiblings } = useContainerPillInteractions({
            items,
            masonry,
            tabId: 1,
            isLocal: computed(() => true),
            matchesActiveLocalFilters: (item) => item.id === 1,
            onReaction: mockOnReaction,
        });

        await batchReactToSiblings(1, 'like');

        expect(items.value.map((item) => item.id)).toEqual([1, 2, 3]);
        expect(masonry.value.remove).toHaveBeenCalledTimes(1);

        const restoreCallback = mockQueueBatchReaction.mock.calls[0]?.[3] as (() => Promise<void>) | undefined;
        expect(restoreCallback).toBeTypeOf('function');

        masonry.value = null;
        await restoreCallback?.();

        expect(items.value.map((item) => item.id)).toEqual([1, 2, 3]);
    });

    it('handles alt + middle click to favorite all siblings', async () => {
        const items = ref<FeedItem[]>([
            {
                id: 1,
                width: 500,
                height: 500,
                page: 1,
                key: '1-1',
                index: 0,
                src: 'https://example.com/image1.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as FeedItem,
            {
                id: 2,
                width: 500,
                height: 500,
                page: 1,
                key: '1-2',
                index: 1,
                src: 'https://example.com/image2.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as FeedItem,
        ]);

        const mockRemoveMany = vi.fn().mockResolvedValue(undefined);
        const masonry = ref({
            remove: mockRemoveMany,
        });

        const { handlePillAuxClick } = useContainerPillInteractions({
            items,
            masonry,
            tabId: 1,
            isLocal: onlineMode,
            onReaction: mockOnReaction,
        });

        // Simulate alt + middle click
        const mockEvent = {
            button: 1,
            altKey: true,
            preventDefault: vi.fn(),
            stopPropagation: vi.fn(),
        } as unknown as MouseEvent;

        handlePillAuxClick(1, mockEvent);

        // Wait for async batchReactToSiblings to complete
        await Promise.resolve();

        // Verify preventDefault and stopPropagation were called
        expect(mockEvent.preventDefault).toHaveBeenCalled();
        expect(mockEvent.stopPropagation).toHaveBeenCalled();

        // Verify removeMany was called (indicating batchReactToSiblings was called)
        expect(mockRemoveMany).toHaveBeenCalled();
    });

    it('handles middle click without alt to open container tab', () => {
        vi.useFakeTimers();
        const items = ref<FeedItem[]>([
            {
                id: 1,
                width: 500,
                height: 500,
                page: 1,
                key: '1-1',
                index: 0,
                src: 'https://example.com/image1.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as FeedItem,
        ]);

        const masonry = ref({});
        const onOpenContainerTab = vi.fn();

        const { handlePillAuxClick } = useContainerPillInteractions({
            items,
            masonry,
            tabId: 1,
            isLocal: onlineMode,
            onReaction: mockOnReaction,
            onOpenContainerTab,
        });

        // Simulate middle click without alt
        const mockEvent = {
            button: 1,
            altKey: false,
            preventDefault: vi.fn(),
            stopPropagation: vi.fn(),
        } as unknown as MouseEvent;

        handlePillAuxClick(1, mockEvent);
        expect(onOpenContainerTab).not.toHaveBeenCalled();

        vi.advanceTimersByTime(300);
        vi.runOnlyPendingTimers();

        expect(onOpenContainerTab).toHaveBeenCalledWith(
            expect.objectContaining({ id: 1, type: 'gallery' })
        );

        vi.useRealTimers();
    });

    it('handles alt + right click to blacklist all siblings', async () => {
        const items = ref<FeedItem[]>([
            {
                id: 1,
                width: 500,
                height: 500,
                page: 1,
                key: '1-1',
                index: 0,
                src: 'https://example.com/image1.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as FeedItem,
            {
                id: 2,
                width: 500,
                height: 500,
                page: 1,
                key: '1-2',
                index: 1,
                src: 'https://example.com/image2.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as FeedItem,
        ]);

        const mockRemoveMany = vi.fn().mockResolvedValue(undefined);
        const masonry = ref({
            remove: mockRemoveMany,
        });

        const { handlePillClick } = useContainerPillInteractions({
            items,
            masonry,
            tabId: 1,
            isLocal: onlineMode,
            onReaction: mockOnReaction,
        });

        // Simulate alt + right click (contextmenu event)
        const mockEvent = {
            button: 2,
            altKey: true,
            type: 'contextmenu',
            preventDefault: vi.fn(),
            stopPropagation: vi.fn(),
        } as unknown as MouseEvent;

        handlePillClick(1, mockEvent);

        // Wait for async blacklistSiblings to complete
        await Promise.resolve();
        await Promise.resolve();

        // Verify preventDefault and stopPropagation were called
        expect(mockEvent.preventDefault).toHaveBeenCalled();
        expect(mockEvent.stopPropagation).toHaveBeenCalled();

        expect(mockRemoveMany).toHaveBeenCalled();
        expect(mockQueueBatchBlacklist).toHaveBeenCalledWith(
            [1, 2],
            [
                { fileId: 1, thumbnail: 'https://example.com/image1.jpg' },
                { fileId: 2, thumbnail: 'https://example.com/image2.jpg' },
            ],
            expect.any(Function),
            items,
            expect.objectContaining({ onSuccess: expect.any(Function) }),
        );
    });

    it('handles double left click (without alt) to like all siblings', async () => {
        const items = ref<FeedItem[]>([
            {
                id: 1,
                width: 500,
                height: 500,
                page: 1,
                key: '1-1',
                index: 0,
                src: 'https://example.com/image1.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as FeedItem,
            {
                id: 2,
                width: 500,
                height: 500,
                page: 1,
                key: '1-2',
                index: 1,
                src: 'https://example.com/image2.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as FeedItem,
        ]);

        const mockRemoveMany = vi.fn().mockResolvedValue(undefined);
        const masonry = ref({
            remove: mockRemoveMany,
        });

        const { handlePillClick } = useContainerPillInteractions({
            items,
            masonry,
            tabId: 1,
            isLocal: onlineMode,
            onReaction: mockOnReaction,
        });

        // Simulate double left click (without alt)
        const mockEvent = {
            button: 0,
            altKey: false,
            type: 'dblclick',
            preventDefault: vi.fn(),
            stopPropagation: vi.fn(),
        } as unknown as MouseEvent;

        handlePillClick(1, mockEvent, true); // isDoubleClick = true

        // Wait for async batchReactToSiblings to complete
        await Promise.resolve();

        // Verify preventDefault and stopPropagation were called
        expect(mockEvent.preventDefault).toHaveBeenCalled();
        expect(mockEvent.stopPropagation).toHaveBeenCalled();

        // Verify removeMany was called (indicating batchReactToSiblings was called with 'like')
        expect(mockRemoveMany).toHaveBeenCalled();
    });

    it('handles double right click (without alt) to blacklist all siblings', async () => {
        const items = ref<FeedItem[]>([
            {
                id: 1,
                width: 500,
                height: 500,
                page: 1,
                key: '1-1',
                index: 0,
                src: 'https://example.com/image1.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as FeedItem,
            {
                id: 2,
                width: 500,
                height: 500,
                page: 1,
                key: '1-2',
                index: 1,
                src: 'https://example.com/image2.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as FeedItem,
        ]);

        const mockRemoveMany = vi.fn().mockResolvedValue(undefined);
        const masonry = ref({
            remove: mockRemoveMany,
        });

        const { handlePillClick } = useContainerPillInteractions({
            items,
            masonry,
            tabId: 1,
            isLocal: onlineMode,
            onReaction: mockOnReaction,
        });

        // Simulate double right click (without alt) - need to track the button from previous click
        // First click
        const firstClick = {
            button: 2,
            altKey: false,
            type: 'click',
            preventDefault: vi.fn(),
            stopPropagation: vi.fn(),
        } as unknown as MouseEvent;

        handlePillClick(1, firstClick, false);

        // Second click (double click)
        const secondClick = {
            button: 2,
            altKey: false,
            type: 'dblclick',
            preventDefault: vi.fn(),
            stopPropagation: vi.fn(),
        } as unknown as MouseEvent;

        handlePillClick(1, secondClick, true); // isDoubleClick = true

        // Wait for async blacklistSiblings to complete
        await Promise.resolve();
        await Promise.resolve();

        expect(mockRemoveMany).toHaveBeenCalled();
        expect(mockQueueBatchBlacklist).toHaveBeenCalledWith(
            [1, 2],
            [
                { fileId: 1, thumbnail: 'https://example.com/image1.jpg' },
                { fileId: 2, thumbnail: 'https://example.com/image2.jpg' },
            ],
            expect.any(Function),
            items,
            expect.objectContaining({ onSuccess: expect.any(Function) }),
        );
    });

    it('handles double middle click (without alt) to love all siblings', async () => {
        vi.useFakeTimers();
        const items = ref<FeedItem[]>([
            {
                id: 1,
                width: 500,
                height: 500,
                page: 1,
                key: '1-1',
                index: 0,
                src: 'https://example.com/image1.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as FeedItem,
            {
                id: 2,
                width: 500,
                height: 500,
                page: 1,
                key: '1-2',
                index: 1,
                src: 'https://example.com/image2.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as FeedItem,
        ]);

        const mockRemoveMany = vi.fn().mockResolvedValue(undefined);
        const masonry = ref({
            remove: mockRemoveMany,
        });

        const { handlePillAuxClick } = useContainerPillInteractions({
            items,
            masonry,
            tabId: 1,
            isLocal: onlineMode,
            onReaction: mockOnReaction,
        });

        const firstClick = {
            button: 1,
            altKey: false,
            type: 'auxclick',
            preventDefault: vi.fn(),
            stopPropagation: vi.fn(),
        } as unknown as MouseEvent;

        handlePillAuxClick(1, firstClick);

        const secondClick = {
            button: 1,
            altKey: false,
            type: 'auxclick',
            preventDefault: vi.fn(),
            stopPropagation: vi.fn(),
        } as unknown as MouseEvent;

        handlePillAuxClick(1, secondClick);

        // Wait for async batchReactToSiblings to complete
        await Promise.resolve();

        // Ensure the delayed open is canceled
        vi.advanceTimersByTime(300);
        vi.runOnlyPendingTimers();

        // Verify removeMany was called (indicating batchReactToSiblings was called with 'love')
        expect(mockRemoveMany).toHaveBeenCalled();

        vi.useRealTimers();
    });

    it('does not open container tab on double middle click', async () => {
        vi.useFakeTimers();
        const items = ref<FeedItem[]>([
            {
                id: 1,
                width: 500,
                height: 500,
                page: 1,
                key: '1-1',
                index: 0,
                src: 'https://example.com/image1.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as FeedItem,
        ]);

        const mockRemoveMany = vi.fn().mockResolvedValue(undefined);
        const masonry = ref({
            remove: mockRemoveMany,
        });
        const onOpenContainerTab = vi.fn();

        const { handlePillAuxClick } = useContainerPillInteractions({
            items,
            masonry,
            tabId: 1,
            isLocal: onlineMode,
            onReaction: mockOnReaction,
            onOpenContainerTab,
        });

        const firstClick = {
            button: 1,
            altKey: false,
            type: 'auxclick',
            preventDefault: vi.fn(),
            stopPropagation: vi.fn(),
        } as unknown as MouseEvent;

        const secondClick = {
            button: 1,
            altKey: false,
            type: 'auxclick',
            preventDefault: vi.fn(),
            stopPropagation: vi.fn(),
        } as unknown as MouseEvent;

        handlePillAuxClick(1, firstClick);
        handlePillAuxClick(1, secondClick);

        await Promise.resolve();
        vi.advanceTimersByTime(300);
        vi.runOnlyPendingTimers();

        expect(onOpenContainerTab).not.toHaveBeenCalled();
        expect(mockRemoveMany).toHaveBeenCalled();

        vi.useRealTimers();
    });
});

