import { computed, nextTick, onUnmounted, ref, triggerRef, type Ref, type ShallowRef } from 'vue';
import { createMasonryInteractions } from '@/utils/masonryInteractions';
import type { DownloadedReactionChoice } from './useDownloadedReactionPrompt';
import { useMasonryReactionHandler } from './useMasonryReactionHandler';
import type { BrowseFormInstance } from './useBrowseForm';
import type { FeedItem, TabData } from './useTabs';
import type { ReactionType } from '@/types/reaction';
import type { BrowseFeedHandle } from '@/types/browse';
import { createLoadedItemsBulkActions } from '@/lib/tabContentLoadedItemsBulkActions';
import { useTabContentNotFoundReconciliation } from './useTabContentNotFoundReconciliation';

export type { LoadedItemsBulkAction } from '@/lib/tabContentLoadedItemsBulkActions';

type FileViewerRef = {
    openFromClick: (event: MouseEvent) => void;
};

type UseTabContentItemInteractionsOptions = {
    items: ShallowRef<FeedItem[]>;
    loadedItems?: Ref<FeedItem[]>;
    tab: Ref<TabData | null>;
    form: BrowseFormInstance;
    masonry: Ref<BrowseFeedHandle | null>;
    fileViewer: Ref<FileViewerRef | null>;
    matchesActiveLocalFilters?: (item: FeedItem) => boolean;
    isPositiveOnlyLocalView?: () => boolean;
    itemPreview: {
        incrementPreviewCount: (fileId: number) => Promise<{ previewed_count: number } | null>;
        clearPreviewedItems: (fileIds?: number[]) => void;
        markPreviewedItems: (fileIds: number[]) => void;
    };
    onReaction: (fileId: number, type: ReactionType) => void;
    promptDownloadedReaction: () => Promise<DownloadedReactionChoice>;
    clearHoveredContainer: () => void;
};

function safelyPlayVideoPreview(video: HTMLVideoElement): void {
    try {
        const playback = video.play();

        if (playback && typeof playback.catch === 'function') {
            void playback.catch(() => {});
        }
    } catch {
        // Ignore autoplay and non-browser test environment errors.
    }
}

export function useTabContentItemInteractions(options: UseTabContentItemInteractionsOptions) {
    const hoveredItemIndex = ref<number | null>(null);
    const hoveredItemId = ref<number | null>(null);
    const preloadedItemIds = ref<Set<number>>(new Set());

    const itemIndexById = computed(() => {
        const map = new Map<number, number>();

        for (let index = 0; index < options.items.value.length; index += 1) {
            const id = options.items.value[index]?.id;

            if (typeof id === 'number') {
                map.set(id, index);
            }
        }

        return map;
    });

    function getItemIndex(itemId: number): number | undefined {
        return itemIndexById.value.get(itemId);
    }

    function markItemsPreloaded(batch: FeedItem[]): void {
        const next = new Set(preloadedItemIds.value);

        for (const item of batch) {
            if (typeof item?.id === 'number') {
                next.add(item.id);
            }
        }

        preloadedItemIds.value = next;
    }

    function resetPreloadedItems(): void {
        preloadedItemIds.value = new Set();
    }

    function isItemPreloaded(itemId: number): boolean {
        return preloadedItemIds.value.has(itemId);
    }

    function hasActiveReaction(item: FeedItem): boolean {
        return Boolean(item.reaction?.type);
    }

    const { handleMasonryReaction } = useMasonryReactionHandler({
        items: options.items,
        masonry: options.masonry,
        tab: computed(() => options.tab.value ?? undefined),
        isLocal: options.form.isLocal,
        matchesActiveLocalFilters: options.matchesActiveLocalFilters,
        isPositiveOnlyLocalView: options.isPositiveOnlyLocalView,
        onReaction: options.onReaction,
        promptDownloadedReaction: options.promptDownloadedReaction,
        onWillRemoveItemFromView: (item) => {
            if (hoveredItemId.value === item.id) {
                clearHoverState();
            }
        },
    });

    const masonryInteractions = createMasonryInteractions(
        options.items,
        options.masonry,
        handleMasonryReaction,
    );

    function cancelLoad(): void {
        options.masonry.value?.cancel?.();
    }

    async function loadNextPage(): Promise<void> {
        await options.masonry.value?.loadNextPage?.();
    }

    async function handleRemoved(payload: { items: FeedItem[]; ids: string[] }): Promise<void> {
        if (options.form.data.feed !== 'online') {
            return;
        }

        if (!options.masonry.value || options.masonry.value.isLoading) {
            return;
        }

        if (payload.ids.length === 0) {
            return;
        }

        await nextTick();

        if (options.items.value.length === 0) {
            await options.masonry.value.loadNextPage?.();
        }
    }

    const notFoundReconciliation = useTabContentNotFoundReconciliation({
        items: options.items,
        tab: options.tab,
        masonry: options.masonry,
        hoveredItemId,
        clearHover: clearHoverState,
    });

    function findNearestVideoElement(from: EventTarget | null): HTMLVideoElement | null {
        let element = from as HTMLElement | null;

        for (let index = 0; index < 8 && element; index += 1) {
            const video = element.querySelector('video');

            if (video instanceof HTMLVideoElement) {
                return video;
            }

            element = element.parentElement;
        }

        return null;
    }

    const masonryHandlers = {
        onClick(event: MouseEvent): void {
            if (event.button === 0 || (event.type === 'click' && !event.button)) {
                options.fileViewer.value?.openFromClick(event);
            }
        },
        onMouseDown(event: MouseEvent): void {
            if (!event.altKey && event.button === 1) {
                event.preventDefault();
                event.stopPropagation();
            }
        },
        async onRemoved(payload: { items: FeedItem[]; ids: string[] }): Promise<void> {
            await handleRemoved(payload);
        },
        cancelLoad,
        loadNextPage,
    };

    function clearHoverState(): void {
        hoveredItemIndex.value = null;
        hoveredItemId.value = null;
        options.clearHoveredContainer();
    }

    function getLoadedItems(): FeedItem[] {
        const source = options.loadedItems?.value ?? options.items.value;

        return source.filter((item): item is FeedItem => typeof item.id === 'number');
    }

    const loadedItemsBulkActions = createLoadedItemsBulkActions({
        getLoadedItems,
        items: options.items,
        tab: options.tab,
        isLocal: options.form.isLocal,
        masonry: options.masonry,
        matchesActiveLocalFilters: options.matchesActiveLocalFilters,
        clearHoverForRemovedItems: (removedItemIds) => {
            if (hoveredItemId.value !== null && removedItemIds.has(hoveredItemId.value)) {
                clearHoverState();
            }
        },
        onReaction: options.onReaction,
    });

    const itemHandlers = {
        onClick(event: MouseEvent, item: FeedItem): void {
            if (event.altKey) {
                masonryInteractions.handleAltClickReaction(event, item);
                return;
            }

            event.stopPropagation();
            options.fileViewer.value?.openFromClick(event);
        },
        onContextMenu(event: MouseEvent, item: FeedItem): void {
            if (event.altKey) {
                masonryInteractions.handleAltClickReaction(event, item);
            }
        },
        onMouseDown(event: MouseEvent, item: FeedItem): void {
            if (event.altKey && event.button === 1) {
                masonryInteractions.handleAltClickReaction(event, item);
            }
        },
        onAuxClick(event: MouseEvent, item: FeedItem): void {
            masonryInteractions.handleMasonryItemAuxClick(event, item);
        },
        onMouseEnter(event: MouseEvent, item: FeedItem): void {
            const itemId = item.id;
            const index = options.items.value.findIndex((candidate) => candidate.id === itemId);

            hoveredItemIndex.value = index === -1 ? null : index;
            hoveredItemId.value = itemId;

            if (item.type === 'video') {
                const video = findNearestVideoElement(event.currentTarget);

                if (video) {
                    video.muted = true;
                    safelyPlayVideoPreview(video);
                }
            }

        },
        onMouseLeave(event: MouseEvent, item: FeedItem): void {
            clearHoverState();

            if (item.type === 'video') {
                const video = findNearestVideoElement(event.currentTarget);

                if (video && !video.paused) {
                    video.pause();
                }
            }
        },
    };

    async function handleItemInViewAndLoaded(item: FeedItem): Promise<void> {
        const itemId = item.id;
        if (!itemId) {
            return;
        }

        await options.itemPreview.incrementPreviewCount(itemId);
    }

    async function resetPreviewedState(): Promise<number> {
        const resettableItems = getLoadedItems().filter((item) => {
            return typeof item.id === 'number' && !hasActiveReaction(item);
        });
        const fileIds = resettableItems.map((item) => item.id);

        if (fileIds.length === 0) {
            return 0;
        }

        await window.axios.post('/api/files/preview/reset/batch', {
            file_ids: fileIds,
        });

        options.itemPreview.clearPreviewedItems(fileIds);

        for (const item of resettableItems) {
            item.previewed_count = 0;
        }

        triggerRef(options.items);
        await nextTick();

        return fileIds.length;
    }

    const preloadHandlers = {
        reset: resetPreloadedItems,
        isItemPreloaded,
        async onItemPreloaded(item: FeedItem): Promise<void> {
            await handleItemInViewAndLoaded(item);
        },
        onBatchPreloaded(batch: FeedItem[]): void {
            markItemsPreloaded(batch);

            for (const item of batch) {
                void handleItemInViewAndLoaded(item);
            }
        },
        onBatchFailures(payloads: Array<{ item: FeedItem; error: unknown }>): void {
            notFoundReconciliation.onBatchFailures(payloads);
        },
    };

    const reactionHandlers = {
        hasActiveReaction,
        onFileReaction(item: FeedItem, type: ReactionType): void {
            void handleMasonryReaction(item, type);
        },
        onFileViewerReaction(itemId: number, type: ReactionType): void {
            const item = options.items.value.find((candidate) => candidate.id === itemId);

            if (item) {
                reactionHandlers.onFileReaction(item, type);
            }
        },
    };

    const viewerHandlers = {
        onOpen(): void {},
        onClose(): void {},
        onReaction: reactionHandlers.onFileViewerReaction,
        onPreviewFailure(item: FeedItem): void {
            notFoundReconciliation.reportItem(item);
        },
    };

    onUnmounted(() => {
        options.masonry.value?.cancel?.();
    });

    return {
        state: {
            hoveredItemId,
            hoveredItemIndex,
            getItemIndex,
            clearHover: clearHoverState,
        },
        masonry: masonryHandlers,
        item: itemHandlers,
        preload: preloadHandlers,
        reactions: reactionHandlers,
        viewer: viewerHandlers,
        resetPreviewedState,
        performLoadedItemsBulkAction: loadedItemsBulkActions.performLoadedItemsBulkAction,
    };
}

export type TabContentItemInteractions = ReturnType<typeof useTabContentItemInteractions>;
