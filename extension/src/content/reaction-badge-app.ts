import { createApp } from 'vue';
import type { MediaElement } from './media-utils';
import type { BadgeReactionType } from './reaction-check-queue';
import { AtlasReactionBadge } from './reaction-badge-component';

export type MountedBadge = {
    element: HTMLDivElement;
    triggerReaction: (type: BadgeReactionType) => void;
    unmount: () => void;
};

export function createReactionBadgeHost(media: MediaElement): MountedBadge {
    const element = document.createElement('div');
    let triggerReactionHandler: ((type: BadgeReactionType) => void) | null = null;
    const app = createApp(AtlasReactionBadge, {
        media,
        onShortcutReady: (handler: ((type: BadgeReactionType) => void) | null) => {
            triggerReactionHandler = handler;
        },
    });
    app.mount(element);

    return {
        element,
        triggerReaction: (type: BadgeReactionType) => {
            triggerReactionHandler?.(type);
        },
        unmount: () => {
            triggerReactionHandler = null;
            app.unmount();
        },
    };
}
