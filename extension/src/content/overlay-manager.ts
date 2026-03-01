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
    mouseenter: (event: Event) => void;
    focus: (event: Event) => void;
};

export class OverlayManager {
    private readonly badgesByMedia = new WeakMap<MediaElement, BadgeHost>();
    private readonly shortcutListenersByMedia = new WeakMap<MediaElement, ShortcutListeners>();
    private readonly activeMedia = new Set<MediaElement>();
    private focusedMedia: MediaElement | null = null;
    private isGlobalShortcutBound = false;
    private lastPointerX: number | null = null;
    private lastPointerY: number | null = null;

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
            window.removeEventListener('mousemove', this.handleGlobalMouseMove, true);
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

    private bindShortcutListeners(media: MediaElement): void {
        if (this.shortcutListenersByMedia.has(media)) {
            return;
        }

        const mouseenter = this.handleMediaEnter;
        const focus = this.handleMediaEnter as EventListener;

        media.addEventListener('mouseenter', mouseenter);
        media.addEventListener('focus', focus);

        this.shortcutListenersByMedia.set(media, { mouseenter, focus });
    }

    private unbindShortcutListeners(media: MediaElement): void {
        const listeners = this.shortcutListenersByMedia.get(media);
        if (!listeners) {
            return;
        }
        media.removeEventListener('mouseenter', listeners.mouseenter);
        media.removeEventListener('focus', listeners.focus);
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
        window.addEventListener('mousemove', this.handleGlobalMouseMove, true);
        window.addEventListener('click', this.handleGlobalClick, true);
        window.addEventListener('contextmenu', this.handleGlobalContextmenu, true);
        window.addEventListener('mousedown', this.handleGlobalMousedown, true);
        this.isGlobalShortcutBound = true;
    }

    private readonly handleGlobalMouseMove = (event: MouseEvent): void => {
        this.lastPointerX = event.clientX;
        this.lastPointerY = event.clientY;
    };

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

        this.lastPointerX = event.clientX;
        this.lastPointerY = event.clientY;

        const media = this.findActiveMediaAtPoint(event.clientX, event.clientY);
        if (!media) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        this.focusedMedia = media;
        this.triggerReaction(media, type);
    }

    private readonly handleGlobalKeyDown = (event: KeyboardEvent): void => {
        if (event.defaultPrevented) {
            return;
        }

        const target = event.target as HTMLElement | null;
        if (target && target.closest('input, textarea, select, [contenteditable="true"]')) {
            return;
        }

        const shortcutMedia = this.resolveShortcutMedia();
        if (!shortcutMedia) {
            return;
        }

        const key = event.key.toLowerCase();
        if (key === 'l') {
            event.preventDefault();
            this.triggerReaction(shortcutMedia, 'like');
            return;
        }
        if (key === 'd') {
            event.preventDefault();
            this.triggerReaction(shortcutMedia, 'dislike');
            return;
        }
        if (key === 'f') {
            event.preventDefault();
            this.triggerReaction(shortcutMedia, 'love');
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

    private resolveShortcutMedia(): MediaElement | null {
        if (this.focusedMedia && this.isActiveConnectedMedia(this.focusedMedia)) {
            return this.focusedMedia;
        }

        const pointerMedia = this.findActiveMediaAtPoint(this.lastPointerX, this.lastPointerY);
        if (pointerMedia) {
            this.focusedMedia = pointerMedia;
            return this.focusedMedia;
        }

        return null;
    }

    private findActiveMediaAtPoint(x: number | null, y: number | null): MediaElement | null {
        if (x === null || y === null) {
            return null;
        }

        const directMedia = document.elementsFromPoint(x, y)
            .find((element) => this.isActiveConnectedMedia(element));
        if (directMedia && (directMedia instanceof HTMLImageElement || directMedia instanceof HTMLVideoElement)) {
            return directMedia;
        }

        return null;
    }

    private isActiveConnectedMedia(media: unknown): media is MediaElement {
        if (!(media instanceof HTMLImageElement || media instanceof HTMLVideoElement)) {
            return false;
        }

        return media.isConnected && this.activeMedia.has(media);
    }
}
