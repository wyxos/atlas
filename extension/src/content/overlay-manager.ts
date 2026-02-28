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

type ShortcutListeners = {
    click: (event: MouseEvent) => void;
    contextmenu: (event: MouseEvent) => void;
    mousedown: (event: MouseEvent) => void;
};

export class OverlayManager {
    private readonly badgesByMedia = new WeakMap<MediaElement, BadgeHost>();
    private readonly shortcutListenersByMedia = new WeakMap<MediaElement, ShortcutListeners>();
    private readonly activeMedia = new Set<MediaElement>();
    private focusedMedia: MediaElement | null = null;
    private isGlobalShortcutBound = false;

    apply(media: MediaElement): void {
        this.activeMedia.add(media);
        media.setAttribute(APPLIED_ATTR, '1');
        const badge = this.ensureBadge(media);
        this.syncBadgePlacement(media, badge);
        this.bindShortcutListeners(media);
        this.ensureGlobalShortcutBinding();
    }

    remove(media: MediaElement): void {
        this.activeMedia.delete(media);
        this.unbindShortcutListeners(media);
        const badgeHost = this.badgesByMedia.get(media);
        if (badgeHost) {
            badgeHost.unmount();
            badgeHost.element.remove();
            this.badgesByMedia.delete(media);
        }
        media.removeAttribute(APPLIED_ATTR);
        if (this.focusedMedia === media) {
            this.focusedMedia = null;
        }
        if (this.activeMedia.size === 0 && this.isGlobalShortcutBound) {
            window.removeEventListener('keydown', this.handleGlobalKeyDown, true);
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

    private bindShortcutListeners(media: MediaElement): void {
        if (this.shortcutListenersByMedia.has(media)) {
            return;
        }

        const click = (event: MouseEvent): void => {
            if (!event.altKey || event.button !== 0) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            this.focusedMedia = media;
            this.triggerReaction(media, 'like');
        };

        const contextmenu = (event: MouseEvent): void => {
            if (!event.altKey) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            this.focusedMedia = media;
            this.triggerReaction(media, 'dislike');
        };

        const mousedown = (event: MouseEvent): void => {
            if (!event.altKey || event.button !== 1) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            this.focusedMedia = media;
            this.triggerReaction(media, 'love');
        };

        media.addEventListener('mouseenter', this.handleMediaEnter);
        media.addEventListener('focus', this.handleMediaEnter as EventListener);
        media.addEventListener('click', click as EventListener, true);
        media.addEventListener('contextmenu', contextmenu as EventListener, true);
        media.addEventListener('mousedown', mousedown as EventListener, true);

        this.shortcutListenersByMedia.set(media, { click, contextmenu, mousedown });
    }

    private unbindShortcutListeners(media: MediaElement): void {
        media.removeEventListener('mouseenter', this.handleMediaEnter);
        media.removeEventListener('focus', this.handleMediaEnter as EventListener);
        const listeners = this.shortcutListenersByMedia.get(media);
        if (!listeners) {
            return;
        }
        media.removeEventListener('click', listeners.click as EventListener, true);
        media.removeEventListener('contextmenu', listeners.contextmenu as EventListener, true);
        media.removeEventListener('mousedown', listeners.mousedown as EventListener, true);
        this.shortcutListenersByMedia.delete(media);
    }

    private readonly handleMediaEnter = (event: Event): void => {
        const media = event.currentTarget;
        if (media instanceof HTMLImageElement || media instanceof HTMLVideoElement) {
            this.focusedMedia = media;
        }
    };

    private ensureGlobalShortcutBinding(): void {
        if (this.isGlobalShortcutBound) {
            return;
        }
        window.addEventListener('keydown', this.handleGlobalKeyDown, true);
        this.isGlobalShortcutBound = true;
    }

    private readonly handleGlobalKeyDown = (event: KeyboardEvent): void => {
        if (event.defaultPrevented) {
            return;
        }

        const target = event.target as HTMLElement | null;
        if (target && target.closest('input, textarea, select, [contenteditable="true"]')) {
            return;
        }

        if (!this.focusedMedia || !this.activeMedia.has(this.focusedMedia)) {
            return;
        }

        const key = event.key.toLowerCase();
        if (key === 'l') {
            event.preventDefault();
            this.triggerReaction(this.focusedMedia, 'like');
            return;
        }
        if (key === 'd') {
            event.preventDefault();
            this.triggerReaction(this.focusedMedia, 'dislike');
            return;
        }
        if (key === 'f') {
            event.preventDefault();
            this.triggerReaction(this.focusedMedia, 'love');
        }
    };

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

        if (window.getComputedStyle(parent).position === 'static') {
            parent.style.position = 'relative';
        }

        if (badge.parentElement !== parent || badge.previousElementSibling !== media) {
            media.insertAdjacentElement('afterend', badge);
        }
        badge.style.display = 'block';
    }
}
