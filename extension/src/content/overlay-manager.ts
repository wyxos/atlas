import type { MediaElement } from './media-utils';
import { createReactionBadgeHost } from './reaction-badge-app';
import type { BadgeSubmitType, ReactionBadgeRefreshOptions } from './use-reaction-badge';

const BADGE_ATTR = 'data-atlas-media-red-badge';
const APPLIED_ATTR = 'data-atlas-media-red-applied';

type BadgeHost = {
    element: HTMLDivElement;
    triggerReaction: (type: BadgeSubmitType) => void;
    refreshCheck: (options?: ReactionBadgeRefreshOptions) => void;
    unmount: () => void;
};

type OverlayApplyOptions = {
    refreshCheck?: ReactionBadgeRefreshOptions;
};

export class OverlayManager {
    private readonly badgesByMedia = new WeakMap<MediaElement, BadgeHost>();
    private readonly activeMedia = new Set<MediaElement>();
    private isGlobalShortcutBound = false;

    apply(media: MediaElement, options: OverlayApplyOptions = {}): void {
        if (!this.activeMedia.has(media) && !this.resolveOverlappingMedia(media)) {
            return;
        }

        this.activeMedia.add(media);
        media.setAttribute(APPLIED_ATTR, '1');
        const existingHost = this.badgesByMedia.get(media);
        const badge = this.ensureBadge(media, options.refreshCheck);
        this.syncBadgePlacement(media, badge);
        if (existingHost && options.refreshCheck) {
            existingHost.refreshCheck(options.refreshCheck);
        }
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
            window.removeEventListener('mousedown', this.handleGlobalMousedown, true);
            window.removeEventListener('contextmenu', this.handleGlobalContextMenu, true);
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

    refreshVisibleChecks(options: ReactionBadgeRefreshOptions): number {
        let refreshedCount = 0;
        for (const media of Array.from(this.activeMedia)) {
            if (!this.isActiveConnectedMedia(media) || !this.isVisibleInViewport(media)) {
                continue;
            }

            const badgeHost = this.badgesByMedia.get(media);
            if (!badgeHost) {
                continue;
            }

            badgeHost.refreshCheck(options);
            refreshedCount += 1;
        }

        return refreshedCount;
    }

    private ensureBadge(media: MediaElement, initialRefreshOptions?: ReactionBadgeRefreshOptions): HTMLDivElement {
        const existingHost = this.badgesByMedia.get(media);
        if (existingHost) {
            this.syncBadgePlacement(media, existingHost.element);
            return existingHost.element;
        }

        const badgeHost = createReactionBadgeHost(media, initialRefreshOptions);
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

    private resolveOverlappingMedia(media: MediaElement): boolean {
        const overlappingMedia = this.findOverlappingActiveMedia(media);
        const preferredExisting = overlappingMedia.find((existingMedia) => this.shouldPreferExistingMedia(existingMedia, media));
        if (preferredExisting) {
            this.remove(media);
            return false;
        }

        overlappingMedia.forEach((existingMedia) => {
            this.remove(existingMedia);
        });

        return true;
    }

    private ensureGlobalShortcutBinding(): void {
        if (this.isGlobalShortcutBound) {
            return;
        }
        window.addEventListener('click', this.handleGlobalClick, true);
        window.addEventListener('mousedown', this.handleGlobalMousedown, true);
        window.addEventListener('contextmenu', this.handleGlobalContextMenu, true);
        this.isGlobalShortcutBound = true;
    }

    private readonly handleGlobalClick = (event: MouseEvent): void => {
        this.handleGlobalMouseShortcut(event, 'love', 0);
    };

    private readonly handleGlobalMousedown = (event: MouseEvent): void => {
        this.handleGlobalMouseShortcut(event, 'like', 1);
    };

    private readonly handleGlobalContextMenu = (event: MouseEvent): void => {
        this.handleGlobalMouseShortcut(event, 'blacklist', 2);
    };

    private handleGlobalMouseShortcut(event: MouseEvent, type: BadgeSubmitType, button: number): void {
        if (!event.altKey) {
            return;
        }

        if (event.button !== button) {
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

    private triggerReaction(media: MediaElement, type: BadgeSubmitType): void {
        const host = this.badgesByMedia.get(media);
        if (!host) {
            return;
        }
        host.triggerReaction(type);
    }

    private syncBadgePlacement(media: MediaElement, badge: HTMLDivElement): void {
        const parent = media.parentElement;
        const mediaRect = media.getBoundingClientRect();
        if (parent === null) {
            this.pinBadgeToViewport(mediaRect, badge);
            return;
        }

        const parentRect = parent.getBoundingClientRect();
        const parentIsCollapsed = parentRect.width < 2 || parentRect.height < 2;
        if (parentIsCollapsed) {
            this.pinBadgeToViewport(mediaRect, badge);
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

    private pinBadgeToViewport(mediaRect: DOMRect, badge: HTMLDivElement): void {
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
    }

    private findActiveMediaAtPoint(x: number, y: number): MediaElement | null {
        if (typeof document.elementsFromPoint !== 'function') {
            return null;
        }

        const directMedia = document.elementsFromPoint(x, y)
            .find((element) => this.isActiveConnectedMedia(element));
        if (directMedia && (directMedia instanceof HTMLImageElement || directMedia instanceof HTMLVideoElement)) {
            return directMedia;
        }

        return null;
    }

    private findOverlappingActiveMedia(candidate: MediaElement): MediaElement[] {
        const candidateRect = candidate.getBoundingClientRect();
        if (this.rectArea(candidateRect) <= 0) {
            return [];
        }

        return Array.from(this.activeMedia)
            .filter((media) => media !== candidate && this.isActiveConnectedMedia(media))
            .filter((media) => this.overlapRatio(candidateRect, media.getBoundingClientRect()) >= 0.8);
    }

    private shouldPreferExistingMedia(existingMedia: MediaElement, candidate: MediaElement): boolean {
        if (existingMedia instanceof HTMLVideoElement && candidate instanceof HTMLImageElement) {
            return true;
        }

        if (existingMedia instanceof HTMLImageElement && candidate instanceof HTMLVideoElement) {
            return false;
        }

        const existingArea = this.rectArea(existingMedia.getBoundingClientRect());
        const candidateArea = this.rectArea(candidate.getBoundingClientRect());
        if (candidateArea > existingArea * 1.05) {
            return false;
        }

        return true;
    }

    private overlapRatio(left: DOMRect, right: DOMRect): number {
        const overlapWidth = Math.max(0, Math.min(left.right, right.right) - Math.max(left.left, right.left));
        const overlapHeight = Math.max(0, Math.min(left.bottom, right.bottom) - Math.max(left.top, right.top));
        const overlapArea = overlapWidth * overlapHeight;
        const smallerArea = Math.min(this.rectArea(left), this.rectArea(right));

        return smallerArea > 0 ? overlapArea / smallerArea : 0;
    }

    private rectArea(rect: DOMRect): number {
        return Math.max(0, rect.width) * Math.max(0, rect.height);
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

    private isVisibleInViewport(media: MediaElement): boolean {
        const rect = media.getBoundingClientRect();
        const viewportWidth = window.innerWidth || document.documentElement.clientWidth;
        const viewportHeight = window.innerHeight || document.documentElement.clientHeight;

        return rect.bottom > 0
            && rect.right > 0
            && rect.top < viewportHeight
            && rect.left < viewportWidth
            && rect.width > 0
            && rect.height > 0;
    }

    private isActiveConnectedMedia(media: unknown): media is MediaElement {
        if (!(media instanceof HTMLImageElement || media instanceof HTMLVideoElement)) {
            return false;
        }

        return media.isConnected && this.activeMedia.has(media);
    }
}
