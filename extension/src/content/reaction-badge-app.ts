import { createApp } from 'vue';
import type { MediaElement } from './media-utils';
import type { BadgeSubmitType, ReactionBadgeRefreshOptions } from './use-reaction-badge';
import { AtlasReactionBadge } from './reaction-badge-component';

export type MountedBadge = {
    element: HTMLDivElement;
    triggerReaction: (type: BadgeSubmitType) => void;
    refreshCheck: (options?: ReactionBadgeRefreshOptions) => void;
    unmount: () => void;
};

export function createReactionBadgeHost(
    media: MediaElement,
    initialRefreshOptions?: ReactionBadgeRefreshOptions,
): MountedBadge {
    const element = document.createElement('div');
    let triggerReactionHandler: ((type: BadgeSubmitType) => void) | null = null;
    let refreshCheckHandler: ((options?: ReactionBadgeRefreshOptions) => void) | null = null;
    const app = createApp(AtlasReactionBadge, {
        media,
        initialRefreshOptions,
        onShortcutReady: (handler: ((type: BadgeSubmitType) => void) | null) => {
            triggerReactionHandler = handler;
        },
        onRefreshReady: (handler: ((options?: ReactionBadgeRefreshOptions) => void) | null) => {
            refreshCheckHandler = handler;
        },
    });
    app.mount(element);

    return {
        element,
        triggerReaction: (type: BadgeSubmitType) => {
            triggerReactionHandler?.(type);
        },
        refreshCheck: (options?: ReactionBadgeRefreshOptions) => {
            refreshCheckHandler?.(options);
        },
        unmount: () => {
            triggerReactionHandler = null;
            refreshCheckHandler = null;
            app.unmount();
        },
    };
}
