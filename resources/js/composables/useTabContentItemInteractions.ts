import { computed, nextTick, onUnmounted, ref, triggerRef, type Ref, type ShallowRef } from 'vue';
import type { MasonryInstance } from '@wyxos/vibe';
import { createMasonryInteractions } from '@/utils/masonryInteractions';
import { useMasonryReactionHandler } from './useMasonryReactionHandler';
import { useAutoDislikeQueue } from './useAutoDislikeQueue';
import type { BrowseFormInstance } from './useBrowseForm';
import type { FeedItem, TabData } from './useTabs';
import type { ReactionType } from '@/types/reaction';

type FileViewerRef = {
    openFromClick: (event: MouseEvent) => void;
};

type UseTabContentItemInteractionsOptions = {
    items: ShallowRef<FeedItem[]>;
    tab: Ref<TabData | null>;
    form: BrowseFormInstance;
    masonry: Ref<MasonryInstance | null>;
    fileViewer: Ref<FileViewerRef | null>;
    matchesActiveLocalFilters?: (item: FeedItem) => boolean;
    itemPreview: {
        incrementPreviewCount: (fileId: number) => Promise<{ will_auto_dislike: boolean } | null>;
        clearPreviewedItems: (fileIds?: number[]) => void;
    };
    onReaction: (fileId: number, type: ReactionType) => void;
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
        masonry: options.masonry as Ref<InstanceType<typeof import('@wyxos/vibe').Masonry> | null>,
        tab: computed(() => options.tab.value ?? undefined),
        isLocal: options.form.isLocal,
        matchesActiveLocalFilters: options.matchesActiveLocalFilters,
        onReaction: options.onReaction,
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

        if (!options.masonry.value || options.masonry.value.isLoading || options.masonry.value.hasReachedEnd) {
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

    const autoDislikeQueue = useAutoDislikeQueue({
        items: options.items,
        masonry: options.masonry as Ref<InstanceType<typeof import('@wyxos/vibe').Masonry> | null>,
        isLocal: options.form.isLocal,
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

            if (autoDislikeQueue.hasActiveCountdown(itemId)) {
                autoDislikeQueue.freezeAll();
            }
        },
        onMouseLeave(event: MouseEvent, item: FeedItem): void {
            const itemId = hoveredItemId.value;
            const wasHoveringCountdown = itemId !== null && autoDislikeQueue.hasActiveCountdown(itemId);

            hoveredItemIndex.value = null;
            hoveredItemId.value = null;
            options.clearHoveredContainer();

            if (wasHoveringCountdown) {
                autoDislikeQueue.unfreezeAll();
            }

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

        const result = await options.itemPreview.incrementPreviewCount(itemId);
        const isModerationFlagged = item.will_auto_dislike === true;
        const alreadyReacted = Boolean(item.reaction?.type);
        const shouldAutoDislike = !alreadyReacted && (isModerationFlagged || result?.will_auto_dislike === true);

        if (shouldAutoDislike) {
            autoDislikeQueue.startAutoDislikeCountdown(itemId, item);
            triggerRef(options.items);
            await nextTick();
        }
    }

    async function resetPreviewedState(): Promise<number> {
        const resettableItems = options.items.value.filter((item) => {
            return typeof item.id === 'number' && !hasActiveReaction(item);
        });
        const fileIds = resettableItems.map((item) => item.id);

        if (fileIds.length === 0) {
            return 0;
        }

        await window.axios.post('/api/files/preview/reset/batch', {
            file_ids: fileIds,
        });

        autoDislikeQueue.clearAutoDislikeCountdowns();
        options.itemPreview.clearPreviewedItems(fileIds);

        for (const item of resettableItems) {
            item.previewed_count = 0;
            item.will_auto_dislike = false;
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
            void payloads;
        },
    };

    const reactionHandlers = {
        hasActiveReaction,
        onFileReaction(item: FeedItem, type: ReactionType): void {
            autoDislikeQueue.cancelAutoDislikeCountdown(item.id);
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
        onOpen(): void {
            autoDislikeQueue.freezeAutoDislikeOnly();
        },
        onClose(): void {
            autoDislikeQueue.unfreezeAutoDislikeOnly();
        },
        onReaction: reactionHandlers.onFileViewerReaction,
    };

    onUnmounted(() => {
        options.masonry.value?.cancel?.();
        autoDislikeQueue.clearAutoDislikeCountdowns();
    });

    return {
        state: {
            hoveredItemId,
            hoveredItemIndex,
            getItemIndex,
        },
        masonry: masonryHandlers,
        item: itemHandlers,
        preload: preloadHandlers,
        reactions: reactionHandlers,
        viewer: viewerHandlers,
        autoDislikeQueue,
        resetPreviewedState,
    };
}

export type TabContentItemInteractions = ReturnType<typeof useTabContentItemInteractions>;
