import { computed, ref, type ComputedRef, type Ref, type ShallowRef } from 'vue';
import type { MasonryInstance } from '@wyxos/vibe';
import { useContainerBadges } from './useContainerBadges';
import { useContainerPillInteractions } from './useContainerPillInteractions';
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

type UseTabContentContainerInteractionsOptions = {
    items: ShallowRef<FeedItem[]>;
    tab: Ref<TabData | null>;
    form: BrowseFormInstance;
    masonry: Ref<MasonryInstance | null>;
    availableServices: ComputedRef<ServiceOption[]>;
    formatTabLabel: (serviceLabel: string, pageToken: number | string, containerLabel?: string | null) => string;
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

    function resolveOnlineServiceKey(container: ContainerTarget): string | null {
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
    }

    function buildContainerTabPayload(container: ContainerTarget): { label: string; params: Record<string, unknown> } | null {
        const serviceKey = resolveOnlineServiceKey(container);
        if (!serviceKey) {
            return null;
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
            return null;
        }

        const containerLabel = `${container.type} ${containerValue}`;

        return {
            label: options.formatTabLabel(serviceLabel, 1, containerLabel),
            params,
        };
    }

    function handleContainerNavigation(container: ContainerTarget): void {
        if (!options.onOpenContainerTab) {
            openExternal(container.referrer ?? null);
            return;
        }

        const payload = buildContainerTabPayload(container);
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

    const pillInteractions = useContainerPillInteractions(
        options.items,
        options.masonry,
        computed(() => options.tab.value?.id),
        options.onReaction,
        handleContainerNavigation,
    );

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
