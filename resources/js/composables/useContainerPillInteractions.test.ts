import { describe, it, expect, vi, beforeEach } from 'vitest';
import { ref, computed } from 'vue';
import { useContainerPillInteractions } from './useContainerPillInteractions';
import type { MasonryItem } from './useTabs';

// Mock dependencies
vi.mock('@/utils/reactions', () => ({
    createReactionCallback: vi.fn(() => vi.fn()),
}));

vi.mock('@/utils/reactionQueue', () => ({
    queueBatchReaction: vi.fn(),
}));

vi.mock('./useBrowseForm', () => ({
    useBrowseForm: () => ({
        isLocal: computed(() => false), // Online mode for tests
    }),
}));

describe('useContainerPillInteractions', () => {
    const mockOnReaction = vi.fn();
    const mockRemoveMany = vi.fn().mockResolvedValue(undefined);

    beforeEach(() => {
        vi.clearAllMocks();
        mockRemoveMany.mockResolvedValue(undefined);
    });

    it('uses removeMany when batch reacting to multiple siblings', async () => {
        const items = ref<MasonryItem[]>([
            {
                id: 1,
                width: 500,
                height: 500,
                page: 1,
                key: '1-1',
                index: 0,
                src: 'https://example.com/image1.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as MasonryItem,
            {
                id: 2,
                width: 500,
                height: 500,
                page: 1,
                key: '1-2',
                index: 1,
                src: 'https://example.com/image2.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as MasonryItem,
            {
                id: 3,
                width: 500,
                height: 500,
                page: 1,
                key: '1-3',
                index: 2,
                src: 'https://example.com/image3.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as MasonryItem,
        ]);

        const masonry = ref({
            removeMany: mockRemoveMany,
        });

        const { batchReactToSiblings } = useContainerPillInteractions(
            items,
            masonry,
            1,
            mockOnReaction
        );

        await batchReactToSiblings(1, 'like');

        // Verify removeMany was called with all siblings
        expect(mockRemoveMany).toHaveBeenCalledTimes(1);
        expect(mockRemoveMany).toHaveBeenCalledWith([
            expect.objectContaining({ id: 1 }),
            expect.objectContaining({ id: 2 }),
            expect.objectContaining({ id: 3 }),
        ]);
    });

    it('handles alt + middle click to favorite all siblings', async () => {
        const items = ref<MasonryItem[]>([
            {
                id: 1,
                width: 500,
                height: 500,
                page: 1,
                key: '1-1',
                index: 0,
                src: 'https://example.com/image1.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as MasonryItem,
            {
                id: 2,
                width: 500,
                height: 500,
                page: 1,
                key: '1-2',
                index: 1,
                src: 'https://example.com/image2.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as MasonryItem,
        ]);

        const mockRemoveMany = vi.fn().mockResolvedValue(undefined);
        const masonry = ref({
            removeMany: mockRemoveMany,
        });

        const { handlePillAuxClick } = useContainerPillInteractions(
            items,
            masonry,
            1,
            mockOnReaction
        );

        // Simulate alt + middle click
        const mockEvent = {
            button: 1,
            altKey: true,
            preventDefault: vi.fn(),
            stopPropagation: vi.fn(),
        } as unknown as MouseEvent;

        handlePillAuxClick(1, mockEvent);

        // Wait for async batchReactToSiblings to complete
        await new Promise(resolve => setTimeout(resolve, 0));

        // Verify preventDefault and stopPropagation were called
        expect(mockEvent.preventDefault).toHaveBeenCalled();
        expect(mockEvent.stopPropagation).toHaveBeenCalled();

        // Verify removeMany was called (indicating batchReactToSiblings was called)
        expect(mockRemoveMany).toHaveBeenCalled();
    });

    it('handles middle click without alt to open URL', () => {
        const items = ref<MasonryItem[]>([
            {
                id: 1,
                width: 500,
                height: 500,
                page: 1,
                key: '1-1',
                index: 0,
                src: 'https://example.com/image1.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as MasonryItem,
        ]);

        const masonry = ref({});

        // Mock window.open
        const mockOpen = vi.fn();
        const originalOpen = window.open;
        window.open = mockOpen;

        const { handlePillAuxClick } = useContainerPillInteractions(
            items,
            masonry,
            1,
            mockOnReaction
        );

        // Simulate middle click without alt
        const mockEvent = {
            button: 1,
            altKey: false,
            preventDefault: vi.fn(),
            stopPropagation: vi.fn(),
        } as unknown as MouseEvent;

        handlePillAuxClick(1, mockEvent);

        // Verify window.open was called (to open URL)
        expect(mockOpen).toHaveBeenCalled();

        // Restore window.open
        window.open = originalOpen;
    });

    it('handles alt + right click to dislike all siblings', async () => {
        const items = ref<MasonryItem[]>([
            {
                id: 1,
                width: 500,
                height: 500,
                page: 1,
                key: '1-1',
                index: 0,
                src: 'https://example.com/image1.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as MasonryItem,
            {
                id: 2,
                width: 500,
                height: 500,
                page: 1,
                key: '1-2',
                index: 1,
                src: 'https://example.com/image2.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as MasonryItem,
        ]);

        const mockRemoveMany = vi.fn().mockResolvedValue(undefined);
        const masonry = ref({
            removeMany: mockRemoveMany,
        });

        const { handlePillClick } = useContainerPillInteractions(
            items,
            masonry,
            1,
            mockOnReaction
        );

        // Simulate alt + right click (contextmenu event)
        const mockEvent = {
            button: 2,
            altKey: true,
            type: 'contextmenu',
            preventDefault: vi.fn(),
            stopPropagation: vi.fn(),
        } as unknown as MouseEvent;

        handlePillClick(1, mockEvent);

        // Wait for async batchReactToSiblings to complete
        await new Promise(resolve => setTimeout(resolve, 0));

        // Verify preventDefault and stopPropagation were called
        expect(mockEvent.preventDefault).toHaveBeenCalled();
        expect(mockEvent.stopPropagation).toHaveBeenCalled();

        // Verify removeMany was called (indicating batchReactToSiblings was called with 'dislike')
        expect(mockRemoveMany).toHaveBeenCalled();
    });

    it('handles double left click (without alt) to like all siblings', async () => {
        const items = ref<MasonryItem[]>([
            {
                id: 1,
                width: 500,
                height: 500,
                page: 1,
                key: '1-1',
                index: 0,
                src: 'https://example.com/image1.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as MasonryItem,
            {
                id: 2,
                width: 500,
                height: 500,
                page: 1,
                key: '1-2',
                index: 1,
                src: 'https://example.com/image2.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as MasonryItem,
        ]);

        const mockRemoveMany = vi.fn().mockResolvedValue(undefined);
        const masonry = ref({
            removeMany: mockRemoveMany,
        });

        const { handlePillClick } = useContainerPillInteractions(
            items,
            masonry,
            1,
            mockOnReaction
        );

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
        await new Promise(resolve => setTimeout(resolve, 0));

        // Verify preventDefault and stopPropagation were called
        expect(mockEvent.preventDefault).toHaveBeenCalled();
        expect(mockEvent.stopPropagation).toHaveBeenCalled();

        // Verify removeMany was called (indicating batchReactToSiblings was called with 'like')
        expect(mockRemoveMany).toHaveBeenCalled();
    });

    it('handles double right click (without alt) to dislike all siblings', async () => {
        const items = ref<MasonryItem[]>([
            {
                id: 1,
                width: 500,
                height: 500,
                page: 1,
                key: '1-1',
                index: 0,
                src: 'https://example.com/image1.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as MasonryItem,
            {
                id: 2,
                width: 500,
                height: 500,
                page: 1,
                key: '1-2',
                index: 1,
                src: 'https://example.com/image2.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as MasonryItem,
        ]);

        const mockRemoveMany = vi.fn().mockResolvedValue(undefined);
        const masonry = ref({
            removeMany: mockRemoveMany,
        });

        const { handlePillClick } = useContainerPillInteractions(
            items,
            masonry,
            1,
            mockOnReaction
        );

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

        // Wait for async batchReactToSiblings to complete
        await new Promise(resolve => setTimeout(resolve, 0));

        // Verify removeMany was called (indicating batchReactToSiblings was called with 'dislike')
        expect(mockRemoveMany).toHaveBeenCalled();
    });

    it('handles double middle click (without alt) to love all siblings', async () => {
        const items = ref<MasonryItem[]>([
            {
                id: 1,
                width: 500,
                height: 500,
                page: 1,
                key: '1-1',
                index: 0,
                src: 'https://example.com/image1.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as MasonryItem,
            {
                id: 2,
                width: 500,
                height: 500,
                page: 1,
                key: '1-2',
                index: 1,
                src: 'https://example.com/image2.jpg',
                containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
            } as MasonryItem,
        ]);

        const mockRemoveMany = vi.fn().mockResolvedValue(undefined);
        const masonry = ref({
            removeMany: mockRemoveMany,
        });

        const { handlePillClick } = useContainerPillInteractions(
            items,
            masonry,
            1,
            mockOnReaction
        );

        // Simulate double middle click (without alt)
        const mockEvent = {
            button: 1,
            altKey: false,
            type: 'dblclick',
            preventDefault: vi.fn(),
            stopPropagation: vi.fn(),
        } as unknown as MouseEvent;

        handlePillClick(1, mockEvent, true); // isDoubleClick = true

        // Wait for async batchReactToSiblings to complete
        await new Promise(resolve => setTimeout(resolve, 0));

        // Verify preventDefault and stopPropagation were called
        expect(mockEvent.preventDefault).toHaveBeenCalled();
        expect(mockEvent.stopPropagation).toHaveBeenCalled();

        // Verify removeMany was called (indicating batchReactToSiblings was called with 'love')
        expect(mockRemoveMany).toHaveBeenCalled();
    });
});
