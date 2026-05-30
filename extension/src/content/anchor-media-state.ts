import type { MediaElement } from './media-utils';
import {
    applyAnchorBlacklistedDecoration,
    applyAnchorMatchDecoration,
    applyAnchorOpenedDecoration,
    applyAnchorSamePageDecoration,
} from './anchor-match-decoration';

export const ANCHOR_MEDIA_BORDER_ATTR = 'data-atlas-anchor-media-red-border';
export const ANCHOR_MEDIA_MATCH_ATTR = 'data-atlas-anchor-media-match';
export const ANCHOR_MEDIA_SAME_PAGE_ATTR = 'data-atlas-anchor-same-page';

export type AnchorMediaMatchResult = {
    reaction: string | null;
    downloadedAt: string | null;
    blacklistedAt: string | null;
};

export function clearAnchorMediaAttributes(media: MediaElement): void {
    media.removeAttribute(ANCHOR_MEDIA_BORDER_ATTR);
    media.removeAttribute(ANCHOR_MEDIA_MATCH_ATTR);
    media.removeAttribute('data-atlas-anchor-checking');
    media.removeAttribute('data-atlas-anchor-opened-elsewhere');
    media.removeAttribute(ANCHOR_MEDIA_SAME_PAGE_ATTR);
    media.removeAttribute('data-atlas-anchor-reaction');
    media.removeAttribute('data-atlas-anchor-downloaded-at');
    media.removeAttribute('data-atlas-anchor-blacklisted-at');
}

export function applyAnchorMediaSamePage(media: MediaElement): void {
    applyAnchorSamePageDecoration(media);
    media.setAttribute(ANCHOR_MEDIA_BORDER_ATTR, '1');
    media.setAttribute(ANCHOR_MEDIA_MATCH_ATTR, '0');
    media.removeAttribute('data-atlas-anchor-checking');
    media.removeAttribute('data-atlas-anchor-opened-elsewhere');
    media.setAttribute(ANCHOR_MEDIA_SAME_PAGE_ATTR, '1');
    media.removeAttribute('data-atlas-anchor-reaction');
    media.removeAttribute('data-atlas-anchor-downloaded-at');
    media.removeAttribute('data-atlas-anchor-blacklisted-at');
}

export function applyAnchorMediaMatch(media: MediaElement, result: AnchorMediaMatchResult): void {
    const reaction = result.reaction === 'love'
        || result.reaction === 'like'
        || result.reaction === 'funny'
        ? result.reaction
        : null;

    if (result.blacklistedAt) {
        applyAnchorBlacklistedDecoration(media);
    } else {
        applyAnchorMatchDecoration(media, reaction);
    }
    media.setAttribute(ANCHOR_MEDIA_BORDER_ATTR, '1');
    media.setAttribute(ANCHOR_MEDIA_MATCH_ATTR, '1');
    media.removeAttribute('data-atlas-anchor-checking');
    media.removeAttribute('data-atlas-anchor-opened-elsewhere');
    media.removeAttribute(ANCHOR_MEDIA_SAME_PAGE_ATTR);

    if (result.reaction) {
        media.setAttribute('data-atlas-anchor-reaction', result.reaction);
    } else {
        media.removeAttribute('data-atlas-anchor-reaction');
    }

    if (result.downloadedAt) {
        media.setAttribute('data-atlas-anchor-downloaded-at', result.downloadedAt);
    } else {
        media.removeAttribute('data-atlas-anchor-downloaded-at');
    }

    if (result.blacklistedAt) {
        media.setAttribute('data-atlas-anchor-blacklisted-at', result.blacklistedAt);
    } else {
        media.removeAttribute('data-atlas-anchor-blacklisted-at');
    }
}

export function applyAnchorMediaOpenedElsewhere(media: MediaElement): void {
    applyAnchorOpenedDecoration(media);
    media.setAttribute(ANCHOR_MEDIA_BORDER_ATTR, '1');
    media.setAttribute(ANCHOR_MEDIA_MATCH_ATTR, '0');
    media.removeAttribute('data-atlas-anchor-checking');
    media.setAttribute('data-atlas-anchor-opened-elsewhere', '1');
    media.removeAttribute(ANCHOR_MEDIA_SAME_PAGE_ATTR);
    media.removeAttribute('data-atlas-anchor-reaction');
    media.removeAttribute('data-atlas-anchor-downloaded-at');
    media.removeAttribute('data-atlas-anchor-blacklisted-at');
}
