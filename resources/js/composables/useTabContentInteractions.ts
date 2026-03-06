import { computed, nextTick, onUnmounted, ref, triggerRef, type ComputedRef, type Ref, type ShallowRef } from 'vue';
import type { MasonryInstance } from '@wyxos/vibe';
import { useContainerBadges } from './useContainerBadges';
import { useContainerPillInteractions } from './useContainerPillInteractions';
import { usePromptData } from './usePromptData';
import { createMasonryInteractions } from '@/utils/masonryInteractions';
import { useMasonryReactionHandler } from './useMasonryReactionHandler';
import { useAutoDislikeQueue } from './useAutoDislikeQueue';
import type { BrowseFormInstance } from './useBrowseForm';
import type { ServiceOption } from './useBrowseService';
import type { FeedItem, TabData } from './useTabs';
import type { ReactionType } from '@/types/reaction';
import { appendBrowseServiceFilters } from '@/utils/browseQuery';

type ContainerBlacklistDialogTarget = {
    id: number;
    type: string;
    source: string;
    source_id: string;
    referrer?: string | null;
};

type ContainerTarget = {
    id: number;
    type: string;
    source?: string;
    source_id?: string;
    referrer?: string | null;
};

type ContainerBlacklistDialogRef = {
    openBlacklistDialog: (container: ContainerBlacklistDialogTarget) => void | Promise<void>;
};

type FileViewerRef = {
    openFromClick: (event: MouseEvent) => void;
};

type UseTabContentInteractionsOptions = {
    items: ShallowRef<FeedItem[]>;
    tab: Ref<TabData | null>;
    form: BrowseFormInstance;
    masonry: Ref<MasonryInstance | null>;
    fileViewer: Ref<FileViewerRef | null>;
    availableServices: ComputedRef<ServiceOption[]>;
    itemPreview: {
        incrementPreviewCount: (fileId: number) => Promise<{ will_auto_dislike: boolean } | null>;
    };
    formatTabLabel: (serviceLabel: string, pageToken: number | string, containerLabel?: string | null) => string;
    onReaction: (fileId: number, type: ReactionType) => void;
    onOpenContainerTab?: (payload: { label: string; params: Record<string, unknown> }) => void;
};

export function useTabContentInteractions(options: UseTabContentInteractionsOptions) {
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

    const containerBadges = useContainerBadges(options.items);
    const containerBlacklistManager = ref<ContainerBlacklistDialogRef | null>(null);

    function isContainerBlacklistable(container: { type: string; source?: string }): boolean {
        if (container.source === 'CivitAI') {
            return container.type === 'User';
        }

        return false;
    }

    function handleContainerBan(container: ContainerTarget): void {
        if (containerBlacklistManager.value && container.source && container.source_id) {
            containerBlacklistManager.value.openBlacklistDialog({
                id: container.id,
                type: container.type,
                source: container.source,
                source_id: container.source_id,
                referrer: container.referrer,
            });
        }
    }

    const containerPillInteractions = useContainerPillInteractions(
        options.items,
        options.masonry,
        computed(() => options.tab.value?.id),
        options.onReaction,
        (container) => {
            const openExternal = (url: string | null | undefined): void => {
                if (!url) {
                    return;
                }

                try {
                    const newWindow = window.open(url, '_blank', 'noopener,noreferrer');

                    if (newWindow) {
                        newWindow.blur();
                        window.focus();
                    }
                } catch {
                    // Ignore blocked popup / browser focus errors.
                }
            };

            if (!options.onOpenContainerTab) {
                openExternal(container.referrer ?? null);

                return;
            }

            const serviceKey = (() => {
                if (options.form.data.feed === 'online' && options.form.data.service) {
                    return options.form.data.service;
                }

                if (options.form.data.feed === 'local' && container.source) {
                    const match = options.availableServices.value.find((service) => (
                        service.source === container.source || service.key === container.source
                    ));

                    return match?.key ?? null;
                }

                return null;
            })();

            if (!serviceKey) {
                openExternal(container.referrer ?? null);

                return;
            }

            const serviceLabel = options.availableServices.value.find((service) => service.key === serviceKey)?.label ?? serviceKey;
            const containerValue = container.source_id ?? container.id;
            const params: Record<string, unknown> = {
                feed: 'online',
                service: serviceKey,
                page: 1,
                limit: options.form.data.limit,
            };

            if (options.form.data.feed === 'online') {
                appendBrowseServiceFilters(params, options.form.data.serviceFilters);
            }

            let hasContainerFilter = false;

            if (serviceKey === 'civit-ai-images' && container.source === 'CivitAI') {
                if (container.type === 'User' && container.source_id) {
                    params.username = container.source_id;
                    hasContainerFilter = true;
                }

                if (container.type === 'Post' && container.source_id) {
                    params.postId = container.source_id;
                    hasContainerFilter = true;
                }
            }

            if (!hasContainerFilter) {
                openExternal(container.referrer ?? null);

                return;
            }

            const containerLabel = `${container.type} ${containerValue}`;
            options.onOpenContainerTab({
                label: options.formatTabLabel(serviceLabel, 1, containerLabel),
                params,
            });
        },
    );

    const promptData = usePromptData(options.items);

    const { handleMasonryReaction } = useMasonryReactionHandler(
        options.items,
        options.masonry as Ref<InstanceType<typeof import('@wyxos/vibe').Masonry> | null>,
        computed(() => options.tab.value ?? undefined),
        options.onReaction,
    );

    const masonryInteractions = createMasonryInteractions(
        options.items,
        options.masonry,
        handleMasonryReaction,
    );

    function handleResetFilters(): void {
        options.form.reset();
    }

    function handleModerationRulesChanged(): void {
        // TODO: Implement moderation rules changed logic.
    }

    function cancelMasonryLoad(): void {
        options.masonry.value?.cancel?.();
    }

    async function loadNextPage(): Promise<void> {
        await options.masonry.value?.loadNextPage?.();
    }

    async function handleMasonryRemoved(payload: { items: FeedItem[]; ids: string[] }): Promise<void> {
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

    const autoDislikeQueue = useAutoDislikeQueue(options.items, options.masonry as Ref<InstanceType<typeof import('@wyxos/vibe').Masonry> | null>);

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

    function onMasonryClick(event: MouseEvent): void {
        if (event.button === 0 || (event.type === 'click' && !event.button)) {
            options.fileViewer.value?.openFromClick(event);
        }
    }

    function onMasonryMouseDown(event: MouseEvent): void {
        if (!event.altKey && event.button === 1) {
            event.preventDefault();
            event.stopPropagation();
        }
    }

    function handleMasonryItemClick(event: MouseEvent, item: FeedItem): void {
        if (event.altKey) {
            masonryInteractions.handleAltClickReaction(event, item);

            return;
        }

        event.stopPropagation();
        options.fileViewer.value?.openFromClick(event);
    }

    function handleMasonryItemContextMenu(event: MouseEvent, item: FeedItem): void {
        if (event.altKey) {
            masonryInteractions.handleAltClickReaction(event, item);
        }
    }

    function handleMasonryItemMouseDown(event: MouseEvent, item: FeedItem): void {
        if (event.altKey && event.button === 1) {
            masonryInteractions.handleAltClickReaction(event, item);
        }
    }

    function handleMasonryItemAuxClick(event: MouseEvent, item: FeedItem): void {
        masonryInteractions.handleMasonryItemAuxClick(event, item);
    }

    function handleMasonryItemMouseEnter(event: MouseEvent, item: FeedItem): void {
        const itemId = item.id;
        const index = options.items.value.findIndex((candidate) => candidate.id === itemId);

        hoveredItemIndex.value = index === -1 ? null : index;
        hoveredItemId.value = itemId;

        if (item.type === 'video') {
            const video = findNearestVideoElement(event.currentTarget);

            if (video) {
                video.muted = true;
                void video.play().catch(() => {
                    // Ignore autoplay policy failures.
                });
            }
        }

        if (autoDislikeQueue.hasActiveCountdown(itemId)) {
            autoDislikeQueue.freezeAll();
        }
    }

    function handleMasonryItemMouseLeave(event: MouseEvent, item: FeedItem): void {
        const itemId = hoveredItemId.value;
        const wasHoveringCountdown = itemId !== null && autoDislikeQueue.hasActiveCountdown(itemId);

        hoveredItemIndex.value = null;
        hoveredItemId.value = null;
        containerBadges.setHoveredContainerId(null);

        if (wasHoveringCountdown) {
            autoDislikeQueue.unfreezeAll();
        }

        if (item.type === 'video') {
            const video = findNearestVideoElement(event.currentTarget);

            if (video && !video.paused) {
                video.pause();
            }
        }
    }

    function handleFileViewerOpen(): void {
        autoDislikeQueue.freezeAutoDislikeOnly();
    }

    function handleFileViewerClose(): void {
        autoDislikeQueue.unfreezeAutoDislikeOnly();
    }

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

    async function handleItemPreloaded(item: FeedItem): Promise<void> {
        await handleItemInViewAndLoaded(item);
    }

    function handleBatchPreloaded(batch: FeedItem[]): void {
        markItemsPreloaded(batch);

        for (const item of batch) {
            void handleItemPreloaded(item);
        }
    }

    function handleBatchFailures(payloads: Array<{ item: FeedItem; error: unknown }>): void {
        void payloads;
    }

    function handleContainerPillMouseEnter(containerId: number): void {
        containerBadges.setHoveredContainerId(containerId);
    }

    function handleContainerPillMouseLeave(): void {
        containerBadges.setHoveredContainerId(null);
    }

    function handleContainerPillClick(containerId: number, event: MouseEvent): void {
        containerPillInteractions.handlePillClick(containerId, event);
    }

    function handleContainerPillDblClick(containerId: number, event: MouseEvent): void {
        containerPillInteractions.handlePillClick(containerId, event, true);
    }

    function handleContainerPillContextMenu(containerId: number, event: MouseEvent): void {
        event.preventDefault();
        containerPillInteractions.handlePillClick(containerId, event);
    }

    function handleContainerPillAuxClick(containerId: number, event: MouseEvent): void {
        containerPillInteractions.handlePillAuxClick(containerId, event);
    }

    function handleContainerPillMouseDown(event: MouseEvent): void {
        if (event.button === 1) {
            event.preventDefault();
        }
    }

    function handlePillDismiss(container: ContainerTarget): void {
        handleContainerBan(container);
    }

    function handlePromptDialogClick(item: FeedItem): void {
        void promptData.openPromptDialog(item);
    }

    function handleFileReaction(item: FeedItem, type: ReactionType): void {
        autoDislikeQueue.cancelAutoDislikeCountdown(item.id);
        void handleMasonryReaction(item, type);
    }

    function handleFileViewerReaction(itemId: number, type: ReactionType): void {
        const item = options.items.value.find((candidate) => candidate.id === itemId);

        if (item) {
            handleFileReaction(item, type);
        }
    }

    function handleCopyPromptClick(): void {
        if (promptData.currentPromptData.value) {
            void promptData.copyPromptToClipboard(promptData.currentPromptData.value);
        }
    }

    function handleTestPromptClick(): void {
        if (!promptData.currentPromptData.value) {
            return;
        }

        const params = new URLSearchParams();
        params.set('text', promptData.currentPromptData.value);

        window.open(`/moderation/test?${params.toString()}`, '_blank', 'noopener,noreferrer');
    }

    function handlePromptDialogUpdate(value: boolean): void {
        if (!value) {
            promptData.closePromptDialog();
        }
    }

    onUnmounted(() => {
        options.masonry.value?.cancel?.();
        autoDislikeQueue.clearAutoDislikeCountdowns();
    });

    return {
        hoveredItemIndex,
        hoveredItemId,
        containerBadges,
        containerBlacklistManager,
        promptData,
        autoDislikeQueue,
        containerPillInteractions,
        getItemIndex,
        isItemPreloaded,
        hasActiveReaction,
        resetPreloadedItems,
        onMasonryClick,
        onMasonryMouseDown,
        handleResetFilters,
        handleModerationRulesChanged,
        cancelMasonryLoad,
        loadNextPage,
        handleMasonryRemoved,
        handleMasonryItemClick,
        handleMasonryItemContextMenu,
        handleMasonryItemMouseDown,
        handleMasonryItemAuxClick,
        handleMasonryItemMouseEnter,
        handleMasonryItemMouseLeave,
        handleFileViewerOpen,
        handleFileViewerClose,
        handleBatchPreloaded,
        handleBatchFailures,
        handleItemPreloaded,
        handleContainerPillMouseEnter,
        handleContainerPillMouseLeave,
        handleContainerPillClick,
        handleContainerPillDblClick,
        handleContainerPillContextMenu,
        handleContainerPillAuxClick,
        handleContainerPillMouseDown,
        handlePillDismiss,
        handlePromptDialogClick,
        handleFileReaction,
        handleFileViewerReaction,
        handleCopyPromptClick,
        handleTestPromptClick,
        handlePromptDialogUpdate,
        isContainerBlacklistable,
    };
}
