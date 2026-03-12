import { computed, ref, type Ref, type ShallowRef } from 'vue';
import type { MasonryInstance } from '@wyxos/vibe';
import { useContainerBadges } from './useContainerBadges';
import { useContainerPillInteractions } from './useContainerPillInteractions';
import type { BrowseFormInstance } from './useBrowseForm';
import type { FeedItem, TabData } from './useTabs';
import type { ReactionType } from '@/types/reaction';

type ContainerBlacklistDialogTarget = {
    id: number;
    type: string;
    source: string;
    source_id: string;
    referrer?: string | null;
};

type BrowseTabPayload = {
    label: string;
    params: Record<string, unknown>;
};

type ContainerTarget = {
    id: number;
    type: string;
    source?: string;
    source_id?: string;
    referrer?: string | null;
    browse_tab?: BrowseTabPayload | null;
};

type ContainerBlacklistDialogRef = {
    openBlacklistDialog: (container: ContainerBlacklistDialogTarget) => void | Promise<void>;
};

type UseTabContentContainerInteractionsOptions = {
    items: ShallowRef<FeedItem[]>;
    tab: Ref<TabData | null>;
    form: BrowseFormInstance;
    masonry: Ref<MasonryInstance | null>;
    onReaction: (fileId: number, type: ReactionType) => void;
    onOpenContainerTab?: (payload: { label: string; params: Record<string, unknown> }) => void;
};

export function useTabContentContainerInteractions(options: UseTabContentContainerInteractionsOptions) {
    const badges = useContainerBadges(options.items);
    const managerRef = ref<ContainerBlacklistDialogRef | null>(null);

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
        masonry: options.masonry,
        tabId: computed(() => options.tab.value?.id),
        isLocal: options.form.isLocal,
        onReaction: options.onReaction,
        onOpenContainerTab: handleContainerNavigation,
    });

    function clearHoveredContainer(): void {
        badges.setHoveredContainerId(null);
    }

    const pillHandlers = {
        onMouseEnter(containerId: number): void {
            badges.setHoveredContainerId(containerId);
        },
        onMouseLeave(): void {
            clearHoveredContainer();
        },
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
        onMouseDown(event: MouseEvent): void {
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
    };
}

export type TabContentContainerInteractions = ReturnType<typeof useTabContentContainerInteractions>;
