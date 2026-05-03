import { computed, getCurrentInstance, onUnmounted, ref, watch, type Ref } from 'vue';
import { useContainerBadges } from './useContainerBadges';
import { useContainerPillInteractions, type ContainerPillTarget } from './useContainerPillInteractions';
import type { BrowseFormInstance } from './useBrowseForm';
import type { FeedItem, TabData } from './useTabs';
import type { ReactionType } from '@/types/reaction';
import type { BrowseFeedHandle } from '@/types/browse';

type ContainerBlacklistDialogTarget = {
    id: number;
    type: string;
    source: string;
    source_id: string;
    referrer?: string | null;
};

type ContainerTarget = ContainerPillTarget;

type ContainerBlacklistDialogRef = {
    openBlacklistDialog: (container: ContainerBlacklistDialogTarget) => void | Promise<void>;
};

type UseTabContentContainerInteractionsOptions = {
    items: Ref<FeedItem[]>;
    getItems?: () => FeedItem[];
    visibleItems?: Ref<FeedItem[]>;
    tab: Ref<TabData | null>;
    form: BrowseFormInstance;
    masonry: Ref<BrowseFeedHandle | null>;
    matchesActiveLocalFilters?: (item: FeedItem) => boolean;
    onReaction: (fileId: number, type: ReactionType) => void;
    onOpenContainerTab?: (payload: { label: string; params: Record<string, unknown> }) => void;
};

type DrawerOpenReason = 'hover' | 'click' | null;

function isContainerHoverTarget(target: EventTarget | null): boolean {
    if (!(target instanceof Element)) {
        return false;
    }

    return target.closest('[data-container-pill-trigger], [data-container-pill-drawer]') !== null;
}

export function useTabContentContainerInteractions(options: UseTabContentContainerInteractionsOptions) {
    const HOVER_OPEN_DELAY_MS = 700;
    const visibleItems = computed(() => options.getItems?.() ?? options.visibleItems?.value ?? options.items.value);
    const badges = useContainerBadges(visibleItems);
    const managerRef = ref<ContainerBlacklistDialogRef | null>(null);
    const selectedContainerId = ref<number | null>(null);
    const isDrawerOpen = ref(false);
    const drawerOpenReason = ref<DrawerOpenReason>(null);
    let pendingHoverOpenTimer: ReturnType<typeof setTimeout> | null = null;
    let pendingHoverContainerId: number | null = null;

    function getVisibleSiblingItems(containerId: number): FeedItem[] {
        return visibleItems.value.filter((item) => (
            pillInteractions.getContainersForItem(item).some((container) => container.id === containerId)
        ));
    }

    function getVisibleContainer(containerId: number): ContainerTarget | null {
        for (const item of visibleItems.value) {
            const container = pillInteractions.getContainersForItem(item).find((candidate) => candidate.id === containerId);
            if (container) {
                return container;
            }
        }

        return null;
    }

    function openExternal(url: string | null | undefined): void {
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
    }

    function isBlacklistable(container: { type: string; source?: string }): boolean {
        if (container.source === 'CivitAI') {
            return container.type === 'User';
        }

        return false;
    }

    function handleContainerNavigation(container: ContainerTarget): void {
        if (!options.onOpenContainerTab) {
            openExternal(container.referrer ?? null);
            return;
        }

        const payload = container.browse_tab ?? null;
        if (!payload) {
            openExternal(container.referrer ?? null);
            return;
        }

        options.onOpenContainerTab(payload);
    }

    function dismissContainer(container: ContainerTarget): void {
        if (managerRef.value && container.source && container.source_id) {
            managerRef.value.openBlacklistDialog({
                id: container.id,
                type: container.type,
                source: container.source,
                source_id: container.source_id,
                referrer: container.referrer,
            });
        }
    }

    const pillInteractions = useContainerPillInteractions({
        items: options.items,
        getItems: () => visibleItems.value,
        masonry: options.masonry,
        tabId: computed(() => options.tab.value?.id),
        isLocal: options.form.isLocal,
        matchesActiveLocalFilters: options.matchesActiveLocalFilters,
        onReaction: options.onReaction,
        onOpenContainerTab: handleContainerNavigation,
        onPlainLeftClick: toggleDrawerFromClick,
    });
    const selectedContainer = computed(() => (
        selectedContainerId.value === null
            ? null
            : getVisibleContainer(selectedContainerId.value)
    ));
    const relatedItems = computed(() => (
        selectedContainerId.value === null
            ? []
            : getVisibleSiblingItems(selectedContainerId.value)
    ));
    const highlightedItemIds = computed(() => {
        if (!isDrawerOpen.value || relatedItems.value.length <= 1) {
            return new Set<number>();
        }

        return new Set(relatedItems.value.map((item) => item.id));
    });

    function cancelPendingHoverOpen(): void {
        if (pendingHoverOpenTimer) {
            clearTimeout(pendingHoverOpenTimer);
            pendingHoverOpenTimer = null;
        }

        pendingHoverContainerId = null;
    }

    function closeDrawer(): void {
        cancelPendingHoverOpen();
        isDrawerOpen.value = false;
        drawerOpenReason.value = null;
        selectedContainerId.value = null;
    }

    function setDrawerOpen(open: boolean): void {
        if (!open) {
            closeDrawer();
            return;
        }

        if (selectedContainer.value && relatedItems.value.length > 1) {
            isDrawerOpen.value = true;
            return;
        }

        closeDrawer();
    }

    function openDrawer(container: ContainerTarget, reason: Exclude<DrawerOpenReason, null>): void {
        cancelPendingHoverOpen();

        const siblings = getVisibleSiblingItems(container.id);
        if (siblings.length <= 1) {
            closeDrawer();
            return;
        }

        selectedContainerId.value = container.id;
        isDrawerOpen.value = true;
        drawerOpenReason.value = reason;
    }

    function toggleDrawerFromClick(container: ContainerTarget): void {
        cancelPendingHoverOpen();

        if (isDrawerOpen.value && selectedContainerId.value === container.id) {
            closeDrawer();
            return;
        }

        openDrawer(container, 'click');
    }

    function openDrawerFromHover(containerId: number): void {
        if (drawerOpenReason.value === 'click') {
            return;
        }

        const container = pillInteractions.getContainer(containerId);
        if (!container) {
            closeDrawer();
            return;
        }

        openDrawer(container, 'hover');
    }

    function handlePillMouseEnter(containerId: number): void {
        if (drawerOpenReason.value === 'click') {
            return;
        }

        cancelPendingHoverOpen();
        pendingHoverContainerId = containerId;
        pendingHoverOpenTimer = setTimeout(() => {
            pendingHoverOpenTimer = null;

            if (pendingHoverContainerId !== containerId) {
                return;
            }

            openDrawerFromHover(containerId);
        }, HOVER_OPEN_DELAY_MS);
    }

    function handlePillMouseLeave(containerId: number): void {
        if (pendingHoverContainerId === containerId) {
            cancelPendingHoverOpen();
        }

        if (drawerOpenReason.value === 'hover' && selectedContainerId.value === containerId) {
            closeDrawer();
        }
    }

    function clearHoveredContainer(): void {
        cancelPendingHoverOpen();

        if (drawerOpenReason.value === 'hover') {
            closeDrawer();
        }
    }

    function syncHoverTarget(target: EventTarget | null): void {
        if (drawerOpenReason.value === 'click') {
            return;
        }

        if (!isContainerHoverTarget(target)) {
            if (pendingHoverContainerId !== null) {
                cancelPendingHoverOpen();
            }

            if (drawerOpenReason.value === 'hover' && isDrawerOpen.value) {
                closeDrawer();
            }
        }
    }

    watch([selectedContainer, relatedItems], ([container, items]) => {
        if (isDrawerOpen.value && (!container || items.length <= 1)) {
            closeDrawer();
        }
    });

    if (getCurrentInstance()) {
        onUnmounted(() => {
            cancelPendingHoverOpen();
        });
    }

    const pillHandlers = {
        onClick(containerId: number, event: MouseEvent): void {
            pillInteractions.handlePillClick(containerId, event);
        },
        onDoubleClick(containerId: number, event: MouseEvent): void {
            pillInteractions.handlePillClick(containerId, event, true);
        },
        onContextMenu(containerId: number, event: MouseEvent): void {
            event.preventDefault();
            pillInteractions.handlePillClick(containerId, event);
        },
        onAuxClick(containerId: number, event: MouseEvent): void {
            pillInteractions.handlePillAuxClick(containerId, event);
        },
        onMouseEnter(containerId: number): void {
            handlePillMouseEnter(containerId);
        },
        onMouseLeave(containerId: number): void {
            handlePillMouseLeave(containerId);
        },
        onMouseDown(event: MouseEvent): void {
            cancelPendingHoverOpen();

            if (event.button === 1) {
                event.preventDefault();
            }
        },
        onDismiss(container: ContainerTarget): void {
            dismissContainer(container);
        },
    };

    return {
        badges,
        managerRef,
        pillInteractions,
        pillHandlers,
        clearHoveredContainer,
        isBlacklistable,
        drawer: {
            state: {
                isOpen: isDrawerOpen,
                openReason: drawerOpenReason,
            },
            derived: {
                container: selectedContainer,
                highlightedItemIds,
                items: relatedItems,
            },
            actions: {
                close: closeDrawer,
                setOpen: setDrawerOpen,
                syncHoverTarget,
            },
        },
    };
}

export type TabContentContainerInteractions = ReturnType<typeof useTabContentContainerInteractions>;
