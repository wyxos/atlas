import { computed, nextTick, onUnmounted, ref, triggerRef, type Ref, type ShallowRef } from 'vue';
import type { MasonryInstance } from '@wyxos/vibe';
import { createMasonryInteractions } from '@/utils/masonryInteractions';
import type { DownloadedReactionChoice } from './useDownloadedReactionPrompt';
import { useMasonryReactionHandler } from './useMasonryReactionHandler';
import { useAutoDislikeQueue } from './useAutoDislikeQueue';
import type { BrowseFormInstance } from './useBrowseForm';
import type { FeedItem, TabData } from './useTabs';
import type { ReactionType } from '@/types/reaction';
import { applyExactLocalReactionState } from '@/utils/localReactionState';

export type LoadedItemsBulkAction =
    | 'love'
    | 'like'
    | 'funny'
    | 'dislike'
    | 'blacklist'
    | 'increment-preview-4';

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
    isPositiveOnlyLocalView?: () => boolean;
    itemPreview: {
        incrementPreviewCount: (fileId: number) => Promise<{ will_auto_dislike: boolean } | null>;
        clearPreviewedItems: (fileIds?: number[]) => void;
    };
    onReaction: (fileId: number, type: ReactionType) => void;
    promptDownloadedReaction: () => Promise<DownloadedReactionChoice>;
    clearHoveredContainer: () => void;
};

type BatchPreviewResponse = {
    results?: Array<{
        id: number;
        previewed_count: number;
        will_auto_dislike: boolean;
    }>;
};

type BatchBlacklistResponse = {
    results?: Array<{
        id: number;
        blacklisted_at: string;
        blacklist_reason: string;
    }>;
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
        isPositiveOnlyLocalView: options.isPositiveOnlyLocalView,
        onReaction: options.onReaction,
        promptDownloadedReaction: options.promptDownloadedReaction,
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

    function clearHoverState(): void {
        const itemId = hoveredItemId.value;
        const wasHoveringCountdown = itemId !== null && autoDislikeQueue.hasActiveCountdown(itemId);

        hoveredItemIndex.value = null;
        hoveredItemId.value = null;
        options.clearHoveredContainer();

        if (wasHoveringCountdown) {
            autoDislikeQueue.unfreezeAll();
        }
    }

    function getLoadedItems(): FeedItem[] {
        return options.items.value.filter((item): item is FeedItem => typeof item.id === 'number');
    }

    function getItemsToRemoveAfterLocalMutation(mutatedItems: FeedItem[]): FeedItem[] {
        if (!options.matchesActiveLocalFilters) {
            return [];
        }

        return mutatedItems.filter((item) => !options.matchesActiveLocalFilters?.(item));
    }

    async function removeItemsFromView(itemsToRemove: FeedItem[]): Promise<void> {
        if (itemsToRemove.length === 0) {
            return;
        }

        const removedItemIds = new Set(itemsToRemove.map((item) => item.id));
        if (hoveredItemId.value !== null && removedItemIds.has(hoveredItemId.value)) {
            clearHoverState();
        }

        if (options.masonry.value) {
            for (const item of itemsToRemove) {
                await options.masonry.value.remove(String(item.id));
            }

            return;
        }

        options.items.value = options.items.value.filter((item) => !removedItemIds.has(item.id));
    }

    async function syncLocalMutationView(mutatedItems: FeedItem[]): Promise<void> {
        const itemsToRemove = getItemsToRemoveAfterLocalMutation(mutatedItems);

        if (itemsToRemove.length > 0) {
            await removeItemsFromView(itemsToRemove);
        } else {
            triggerRef(options.items);
        }

        await nextTick();
    }

    async function applyBatchReaction(type: ReactionType): Promise<number> {
        const loadedItems = getLoadedItems();
        if (loadedItems.length === 0) {
            return 0;
        }

        const fileIds = loadedItems.map((item) => item.id);
        await window.axios.post('/api/files/reactions/batch/store', {
            reactions: fileIds.map((fileId) => ({
                file_id: fileId,
                type,
            })),
        });

        if (options.form.isLocal.value) {
            for (const item of loadedItems) {
                autoDislikeQueue.cancelAutoDislikeCountdown(item.id);
                applyExactLocalReactionState(item, type);
            }

            await syncLocalMutationView(loadedItems);
        } else {
            for (const item of loadedItems) {
                autoDislikeQueue.cancelAutoDislikeCountdown(item.id);
            }

            await removeItemsFromView(loadedItems);
        }

        for (const fileId of fileIds) {
            options.onReaction(fileId, type);
        }

        return fileIds.length;
    }

    async function batchBlacklistLoadedItems(): Promise<number> {
        const loadedItems = getLoadedItems();
        if (loadedItems.length === 0) {
            return 0;
        }

        const fileIds = loadedItems.map((item) => item.id);
        const { data } = await window.axios.post<BatchBlacklistResponse>('/api/files/blacklist/batch', {
            file_ids: fileIds,
        });
        const results = Array.isArray(data.results) ? data.results : [];
        const resultMap = new Map(results.map((result) => [result.id, result]));
        const mutatedItems = loadedItems.filter((item) => resultMap.has(item.id));

        for (const item of mutatedItems) {
            const result = resultMap.get(item.id);
            if (!result) {
                continue;
            }

            autoDislikeQueue.cancelAutoDislikeCountdown(item.id);
            item.blacklisted_at = result.blacklisted_at;
            item.blacklist_reason = result.blacklist_reason;
            item.blacklist_type = 'manual';
            item.blacklist_rule = null;
            item.will_auto_dislike = false;
        }

        if (mutatedItems.length === 0) {
            return 0;
        }

        if (options.form.isLocal.value) {
            await syncLocalMutationView(mutatedItems);
        } else {
            await removeItemsFromView(mutatedItems);
        }

        return mutatedItems.length;
    }

    async function incrementLoadedPreviewCounts(increments: number): Promise<number> {
        const loadedItems = getLoadedItems();
        if (loadedItems.length === 0) {
            return 0;
        }

        const fileIds = loadedItems.map((item) => item.id);
        const { data } = await window.axios.post<BatchPreviewResponse>('/api/files/preview/batch', {
            file_ids: fileIds,
            increments,
        });
        const results = Array.isArray(data.results) ? data.results : [];
        const resultMap = new Map(results.map((result) => [result.id, result]));
        const mutatedItems = loadedItems.filter((item) => resultMap.has(item.id));

        for (const item of mutatedItems) {
            const result = resultMap.get(item.id);
            if (!result) {
                continue;
            }

            item.previewed_count = result.previewed_count;
            item.will_auto_dislike = result.will_auto_dislike;

            if (result.will_auto_dislike) {
                autoDislikeQueue.startAutoDislikeCountdown(item.id, item);
            } else {
                autoDislikeQueue.cancelAutoDislikeCountdown(item.id);
            }
        }

        if (mutatedItems.length === 0) {
            return 0;
        }

        if (options.form.isLocal.value) {
            await syncLocalMutationView(mutatedItems);
        } else {
            triggerRef(options.items);
            await nextTick();
        }

        return mutatedItems.length;
    }

    async function performLoadedItemsBulkAction(action: LoadedItemsBulkAction): Promise<number> {
        if (action === 'blacklist') {
            return batchBlacklistLoadedItems();
        }

        if (action === 'increment-preview-4') {
            return incrementLoadedPreviewCounts(4);
        }

        return applyBatchReaction(action);
    }

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
            clearHover: clearHoverState,
        },
        masonry: masonryHandlers,
        item: itemHandlers,
        preload: preloadHandlers,
        reactions: reactionHandlers,
        viewer: viewerHandlers,
        autoDislikeQueue,
        resetPreviewedState,
        performLoadedItemsBulkAction,
    };
}

export type TabContentItemInteractions = ReturnType<typeof useTabContentItemInteractions>;
