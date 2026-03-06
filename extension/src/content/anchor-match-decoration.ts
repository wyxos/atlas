import type { MediaElement } from './media-utils';

type ReactionType = 'love' | 'like' | 'dislike' | 'funny' | null;
type AnchorDecorationKind = 'checking' | 'reaction' | 'opened';

const BADGE_ATTR = 'data-atlas-anchor-reaction-badge';
const badgeByMedia = new WeakMap<MediaElement, HTMLDivElement>();

const paletteByReaction: Record<Exclude<ReactionType, null>, { color: string }> = {
    love: { color: '#ef4444' },
    like: { color: '#0466c8' },
    dislike: { color: '#6b7280' },
    funny: { color: '#eab308' },
};

function reactionColor(reaction: ReactionType): string {
    if (reaction === null) {
        return '#22c55e';
    }

    return paletteByReaction[reaction].color;
}

function iconMarkup(reaction: ReactionType, kind: AnchorDecorationKind): string {
    if (kind === 'checking') {
        return '<g><circle cx="12" cy="12" r="8" opacity="0.25"></circle><path d="M20 12a8 8 0 0 0-8-8"></path><animateTransform attributeName="transform" dur="0.9s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"></animateTransform></g>';
    }

    if (kind === 'opened') {
        return '<path d="M14 3h7v7"></path><path d="M10 14 21 3"></path><path d="M21 14v7h-7"></path><path d="M3 10 14 21"></path>';
    }

    if (reaction === 'love') {
        return '<path d="M19 14c1.49-1.46 2-3.24 2-5a5 5 0 0 0-5-5c-1.54 0-3.04.99-4 2.36C11.04 4.99 9.54 4 8 4a5 5 0 0 0-5 5c0 1.76.5 3.54 2 5l7 7Z"></path>';
    }

    if (reaction === 'like') {
        return '<path d="M7 10v12"></path><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.94 2.5l-1.46 5A2 2 0 0 1 18.4 19H7a2 2 0 0 1-2-2v-7.31a2 2 0 0 1 .59-1.42l3.7-3.7a1 1 0 0 1 1.71.71V10"></path>';
    }

    if (reaction === 'dislike') {
        return '<path d="M17 14V2"></path><path d="M9 18.12 10 14H4.17a2 2 0 0 1-1.94-2.5l1.46-5A2 2 0 0 1 5.6 5H17a2 2 0 0 1 2 2v7.31a2 2 0 0 1-.59 1.42l-3.7 3.7a1 1 0 0 1-1.71-.71V14"></path>';
    }

    if (reaction === 'funny') {
        return '<circle cx="12" cy="12" r="10"></circle><path d="M8 14s1.5 2 4 2 4-2 4-2"></path><line x1="9" y1="9" x2="9.01" y2="9"></line><line x1="15" y1="9" x2="15.01" y2="9"></line>';
    }

    return '<path d="M20 6 9 17l-5-5"></path>';
}

function ensureBadge(media: MediaElement, reaction: ReactionType, color: string, kind: AnchorDecorationKind): void {
    const parent = media.parentElement;
    if (!parent) {
        return;
    }

    if (window.getComputedStyle(parent).position === 'static') {
        parent.style.position = 'relative';
    }

    let badge = badgeByMedia.get(media) ?? null;
    if (!badge) {
        badge = document.createElement('div');
        badge.setAttribute(BADGE_ATTR, '1');
        badgeByMedia.set(media, badge);
    }

    badge.style.position = 'absolute';
    badge.setAttribute('data-atlas-anchor-badge-kind', kind);
    badge.style.right = '8px';
    badge.style.bottom = '8px';
    badge.style.width = '50px';
    badge.style.height = '50px';
    badge.style.borderRadius = '12px';
    badge.style.background = 'rgba(255, 255, 255, 0.92)';
    badge.style.border = `2px solid ${color}`;
    badge.style.display = 'flex';
    badge.style.alignItems = 'center';
    badge.style.justifyContent = 'center';
    badge.style.pointerEvents = 'none';
    badge.style.zIndex = '6';
    badge.style.boxShadow = '0 8px 16px rgba(0, 0, 0, 0.35)';
    badge.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="${color}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${iconMarkup(reaction, kind)}</svg>`;

    if (badge.parentElement !== parent || badge.previousElementSibling !== media) {
        media.insertAdjacentElement('afterend', badge);
    }
}

export function clearAnchorMatchDecoration(media: MediaElement): void {
    media.style.outline = '';
    media.style.outlineOffset = '';
    media.style.opacity = '';

    const badge = badgeByMedia.get(media);
    if (badge) {
        badge.remove();
        badgeByMedia.delete(media);
    }
}

export function applyAnchorMatchDecoration(media: MediaElement, reaction: ReactionType): void {
    const color = reactionColor(reaction);

    media.style.outline = `4px solid ${color}`;
    media.style.outlineOffset = '-4px';
    media.style.opacity = '0.25';

    ensureBadge(media, reaction, color, 'reaction');
}

export function applyAnchorCheckingDecoration(media: MediaElement): void {
    const color = '#38bdf8';
    media.style.outline = `4px solid ${color}`;
    media.style.outlineOffset = '-4px';
    media.style.opacity = '0.35';

    ensureBadge(media, null, color, 'checking');
}

export function applyAnchorOpenedDecoration(media: MediaElement): void {
    const color = '#22c55e';
    media.style.outline = `4px solid ${color}`;
    media.style.outlineOffset = '-4px';
    media.style.opacity = '0.25';

    ensureBadge(media, null, color, 'opened');
}
