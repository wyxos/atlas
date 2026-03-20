import type { MediaElement } from './media-utils';
import { createReactionBadgeHost } from './reaction-badge-app';
import type { BadgeReactionType } from './reaction-check-queue';

const BADGE_ATTR = 'data-atlas-media-red-badge';
const APPLIED_ATTR = 'data-atlas-media-red-applied';

type BadgeHost = {
    element: HTMLDivElement;
    triggerReaction: (type: BadgeReactionType) => void;
    unmount: () => void;
};

export class OverlayManager {
    private readonly badgesByMedia = new WeakMap<MediaElement, BadgeHost>();
    private readonly activeMedia = new Set<MediaElement>();
    private isGlobalShortcutBound = false;

    apply(media: MediaElement): void {
        this.activeMedia.add(media);
        media.setAttribute(APPLIED_ATTR, '1');
        const badge = this.ensureBadge(media);
        this.syncBadgePlacement(media, badge);
        this.ensureGlobalShortcutBinding();
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
        if (this.activeMedia.size === 0 && this.isGlobalShortcutBound) {
            window.removeEventListener('click', this.handleGlobalClick, true);
            window.removeEventListener('contextmenu', this.handleGlobalContextmenu, true);
            window.removeEventListener('mousedown', this.handleGlobalMousedown, true);
            this.isGlobalShortcutBound = false;
        }
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

    private ensureGlobalShortcutBinding(): void {
        if (this.isGlobalShortcutBound) {
            return;
        }
        window.addEventListener('click', this.handleGlobalClick, true);
        window.addEventListener('contextmenu', this.handleGlobalContextmenu, true);
        window.addEventListener('mousedown', this.handleGlobalMousedown, true);
        this.isGlobalShortcutBound = true;
    }

    private readonly handleGlobalClick = (event: MouseEvent): void => {
        this.handleGlobalMouseShortcut(event, 'like', 0);
    };

    private readonly handleGlobalContextmenu = (event: MouseEvent): void => {
        this.handleGlobalMouseShortcut(event, 'dislike');
    };

    private readonly handleGlobalMousedown = (event: MouseEvent): void => {
        this.handleGlobalMouseShortcut(event, 'love', 1);
    };

    private handleGlobalMouseShortcut(event: MouseEvent, type: BadgeReactionType, button?: number): void {
        if (!event.altKey) {
            return;
        }

        if (button !== undefined && event.button !== button) {
            return;
        }

        if (button === undefined && event.type !== 'contextmenu') {
            return;
        }

        const media = this.resolveMouseShortcutMedia(event);
        if (!media) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        this.triggerReaction(media, type);
    }

    private resolveMouseShortcutMedia(event: MouseEvent): MediaElement | null {
        const x = event.clientX;
        const y = event.clientY;
        const pointCandidates = this.findActiveMediaCandidatesAtPoint(x, y);
        if (pointCandidates.length === 0) {
            return this.findActiveMediaAtPoint(x, y);
        }

        const dialogCandidates = pointCandidates.filter((media) => media.closest('[role="dialog"]'));
        if (dialogCandidates.length > 0) {
            return this.pickNearestMediaCandidate(dialogCandidates, x, y);
        }

        return this.pickNearestMediaCandidate(pointCandidates, x, y);
    }
    private triggerReaction(media: MediaElement, type: BadgeReactionType): void {
        const host = this.badgesByMedia.get(media);
        if (!host) {
            return;
        }
        host.triggerReaction(type);
    }

    private syncBadgePlacement(media: MediaElement, badge: HTMLDivElement): void {
        const parent = media.parentElement;
        if (!parent) {
            return;
        }

        const parentRect = parent.getBoundingClientRect();
        const mediaRect = media.getBoundingClientRect();
        const parentIsCollapsed = parentRect.width < 2 || parentRect.height < 2;
        if (parentIsCollapsed) {
            if (badge.parentElement !== document.body) {
                document.body.appendChild(badge);
            }

            const centerX = mediaRect.left + (mediaRect.width / 2);
            const bottomOffset = Math.max(0, window.innerHeight - mediaRect.bottom + 58);

            badge.style.position = 'fixed';
            badge.style.left = `${centerX}px`;
            badge.style.bottom = `${bottomOffset}px`;
            badge.style.transform = 'translateX(-50%)';
            badge.style.display = 'block';

            return;
        }

        badge.style.position = 'absolute';
        badge.style.left = '50%';
        badge.style.bottom = '58px';
        badge.style.transform = 'translateX(-50%)';

        if (window.getComputedStyle(parent).position === 'static') {
            parent.style.position = 'relative';
        }

        if (badge.parentElement !== parent || badge.previousElementSibling !== media) {
            media.insertAdjacentElement('afterend', badge);
        }
        badge.style.display = 'block';
    }

    private findActiveMediaCandidatesAtPoint(x: number, y: number): MediaElement[] {
        return Array.from(this.activeMedia)
            .filter((media) => this.isActiveConnectedMedia(media))
            .filter((media) => this.isPointInsideRect(x, y, media.getBoundingClientRect()));
    }

    private pickNearestMediaCandidate(candidates: MediaElement[], x: number, y: number): MediaElement | null {
        if (candidates.length === 0) {
            return null;
        }

        if (candidates.length === 1) {
            return candidates[0];
        }

        let selected = candidates[0];
        let selectedDistance = Number.POSITIVE_INFINITY;

        for (const candidate of candidates) {
            const rect = candidate.getBoundingClientRect();
            const centerX = rect.left + (rect.width / 2);
            const centerY = rect.top + (rect.height / 2);
            const distance = ((centerX - x) ** 2) + ((centerY - y) ** 2);

            if (distance < selectedDistance) {
                selected = candidate;
                selectedDistance = distance;
            }
        }

        return selected;
    }

    private isPointInsideRect(x: number, y: number, rect: DOMRect): boolean {
        return x >= rect.left && x <= rect.right && y >= rect.top && y <= rect.bottom;
    }

    private isActiveConnectedMedia(media: unknown): media is MediaElement {
        if (!(media instanceof HTMLImageElement || media instanceof HTMLVideoElement)) {
            return false;
        }

        return media.isConnected && this.activeMedia.has(media);
    }
}
