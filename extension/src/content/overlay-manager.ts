import type { MediaElement } from './media-utils';

const BADGE_ATTR = 'data-atlas-media-red-badge';
const APPLIED_ATTR = 'data-atlas-media-red-applied';

export class OverlayManager {
    private readonly badgesByMedia = new WeakMap<MediaElement, HTMLDivElement>();
    private readonly activeMedia = new Set<MediaElement>();
    private repositionQueued = false;

    apply(media: MediaElement): void {
        this.activeMedia.add(media);
        media.setAttribute(APPLIED_ATTR, '1');
        this.position(media);
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
        if (this.repositionQueued) {
            return;
        }

        this.repositionQueued = true;
        window.requestAnimationFrame(() => {
            this.repositionQueued = false;

            for (const media of Array.from(this.activeMedia)) {
                if (!media.isConnected) {
                    this.remove(media);
                    continue;
                }

                this.position(media);
            }
        });
    }

    private ensureBadge(media: MediaElement): HTMLDivElement {
        const existing = this.badgesByMedia.get(media);
        if (existing) {
            return existing;
        }

        const badge = document.createElement('div');
        badge.setAttribute(BADGE_ATTR, '1');
        badge.style.position = 'absolute';
        badge.style.width = '320px';
        badge.style.height = '40px';
        badge.style.background = '#dc2626';
        badge.style.border = '2px solid #ef4444';
        badge.style.borderRadius = '8px';
        badge.style.boxSizing = 'border-box';
        badge.style.pointerEvents = 'none';
        badge.style.zIndex = '2147483647';
        document.body.appendChild(badge);
        this.badgesByMedia.set(media, badge);
        return badge;
    }

    private position(media: MediaElement): void {
        const badge = this.ensureBadge(media);
        const rect = media.getBoundingClientRect();
        const style = window.getComputedStyle(media);
        const isHidden = style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) === 0;
        const hasSize = rect.width > 0 && rect.height > 0;
        const isOnScreen = rect.bottom >= 0 && rect.right >= 0 && rect.top <= window.innerHeight && rect.left <= window.innerWidth;

        if (isHidden || !hasSize || !isOnScreen) {
            badge.style.display = 'none';
            return;
        }

        const left = window.scrollX + rect.left + ((rect.width - 320) / 2);
        const top = window.scrollY + rect.top + rect.height - 48;
        badge.style.display = 'block';
        badge.style.left = `${Math.round(left)}px`;
        badge.style.top = `${Math.round(top)}px`;
    }
}
