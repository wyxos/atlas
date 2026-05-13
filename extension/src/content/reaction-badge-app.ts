import { createApp } from 'vue';
import type { MediaElement } from './media-utils';
import type { BadgeSubmitType } from './use-reaction-badge';
import { AtlasReactionBadge } from './reaction-badge-component';

export type MountedBadge = {
    element: HTMLDivElement;
    triggerReaction: (type: BadgeSubmitType) => void;
    unmount: () => void;
};

export function createReactionBadgeHost(media: MediaElement): MountedBadge {
    const element = document.createElement('div');
    let triggerReactionHandler: ((type: BadgeSubmitType) => void) | null = null;
    const app = createApp(AtlasReactionBadge, {
        media,
        onShortcutReady: (handler: ((type: BadgeSubmitType) => void) | null) => {
            triggerReactionHandler = handler;
        },
    });
    app.mount(element);

    return {
        element,
        triggerReaction: (type: BadgeSubmitType) => {
            triggerReactionHandler?.(type);
        },
        unmount: () => {
            triggerReactionHandler = null;
            app.unmount();
        },
    };
}
