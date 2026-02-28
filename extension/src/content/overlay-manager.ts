import type { MediaElement } from './media-utils';

const BADGE_ATTR = 'data-atlas-media-red-badge';
const APPLIED_ATTR = 'data-atlas-media-red-applied';

export class OverlayManager {
    private readonly badgesByMedia = new WeakMap<MediaElement, HTMLDivElement>();
    private readonly activeMedia = new Set<MediaElement>();

    apply(media: MediaElement): void {
        this.activeMedia.add(media);
        media.setAttribute(APPLIED_ATTR, '1');
        const badge = this.ensureBadge(media);
        this.syncBadgePlacement(media, badge);
    }

    remove(media: MediaElement): void {
        this.activeMedia.delete(media);
        const badge = this.badgesByMedia.get(media);
        if (badge) {
            badge.remove();
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
        const existing = this.badgesByMedia.get(media);
        if (existing) {
            this.syncBadgePlacement(media, existing);
            return existing;
        }

        const badge = document.createElement('div');
        badge.setAttribute(BADGE_ATTR, '1');
        badge.style.position = 'absolute';
        badge.style.left = '50%';
        badge.style.bottom = '8px';
        badge.style.transform = 'translateX(-50%)';
        badge.style.width = '320px';
        badge.style.height = '40px';
        badge.style.background = '#dc2626';
        badge.style.border = '2px solid #ef4444';
        badge.style.borderRadius = '8px';
        badge.style.boxSizing = 'border-box';
        badge.style.pointerEvents = 'none';
        badge.style.zIndex = '10';
        this.syncBadgePlacement(media, badge);
        this.badgesByMedia.set(media, badge);
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
