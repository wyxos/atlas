import type { MediaElement } from './media-utils';
import { createReactionBadgeHost } from './reaction-badge-app';

const BADGE_ATTR = 'data-atlas-media-red-badge';
const APPLIED_ATTR = 'data-atlas-media-red-applied';

type BadgeHost = {
    element: HTMLDivElement;
    unmount: () => void;
};

export class OverlayManager {
    private readonly badgesByMedia = new WeakMap<MediaElement, BadgeHost>();
    private readonly activeMedia = new Set<MediaElement>();

    apply(media: MediaElement): void {
        this.activeMedia.add(media);
        media.setAttribute(APPLIED_ATTR, '1');
        const badge = this.ensureBadge(media);
        this.syncBadgePlacement(media, badge);
    }

    remove(media: MediaElement): void {
        this.activeMedia.delete(media);
        const badgeHost = this.badgesByMedia.get(media);
        if (badgeHost) {
            badgeHost.unmount();
            badgeHost.element.remove();
            this.badgesByMedia.delete(media);
        }
        media.removeAttribute(APPLIED_ATTR);
    }

    scheduleReposition(): void {
        for (const media of Array.from(this.activeMedia)) {
            if (!media.isConnected) {
                this.remove(media);
                continue;
            }

            const badge = this.ensureBadge(media);
            this.syncBadgePlacement(media, badge);
        }
    }

    private ensureBadge(media: MediaElement): HTMLDivElement {
        const existingHost = this.badgesByMedia.get(media);
        if (existingHost) {
            this.syncBadgePlacement(media, existingHost.element);
            return existingHost.element;
        }

        const badgeHost = createReactionBadgeHost(media);
        const badge = badgeHost.element;
        badge.setAttribute(BADGE_ATTR, '1');
        badge.style.position = 'absolute';
        badge.style.left = '50%';
        badge.style.bottom = '58px';
        badge.style.transform = 'translateX(-50%)';
        badge.style.borderRadius = '8px';
        badge.style.pointerEvents = 'auto';
        badge.style.zIndex = '5';
        this.syncBadgePlacement(media, badge);
        this.badgesByMedia.set(media, badgeHost);
        return badge;
    }

    private syncBadgePlacement(media: MediaElement, badge: HTMLDivElement): void {
        const parent = media.parentElement;
        if (!parent) {
            return;
        }

        if (window.getComputedStyle(parent).position === 'static') {
            parent.style.position = 'relative';
        }

        if (badge.parentElement !== parent || badge.previousElementSibling !== media) {
            media.insertAdjacentElement('afterend', badge);
        }
        badge.style.display = 'block';
    }
}
