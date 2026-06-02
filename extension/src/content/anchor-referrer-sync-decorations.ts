import { cleanupUrlQueryParams } from '../referrer-cleanup';
import {
    applyAnchorBlacklistedDecoration,
    applyAnchorCheckingDecoration,
    applyAnchorMatchDecoration,
} from './anchor-match-decoration';
import {
    ANCHOR_MEDIA_BORDER_ATTR,
    ANCHOR_MEDIA_MATCH_ATTR,
    ANCHOR_MEDIA_SAME_PAGE_ATTR,
} from './anchor-media-state';
import type { MediaElement } from './media-utils';

type KnownReaction = 'love' | 'like' | 'funny';

type AnchorReferrerSyncDecorationsOptions = {
    getReferrerCleanerQueryParams: () => string[];
    forEachMatchingReferrerMedia: (referrerUrls: string[], callback: (mediaElement: MediaElement) => void) => void;
    markReferrerSettled: (mediaElement: MediaElement, referrerKey: string) => void;
    applyAnchorMediaBorderFromCache: (mediaElement: MediaElement) => void;
};

function parseKnownReaction(value: string | null): KnownReaction | null {
    return value === 'love' || value === 'like' || value === 'funny'
        ? value
        : null;
}

export function createAnchorReferrerSyncDecorations(options: AnchorReferrerSyncDecorationsOptions) {
    function applyReactionForReferrerUrl(
        referrerUrl: string,
        reaction: KnownReaction | null | undefined,
        downloadedAt: string | null | undefined,
        blacklistedAt: string | null | undefined,
    ): void {
        const referrerCleanerQueryParams = options.getReferrerCleanerQueryParams();
        const normalizedReferrerUrl = cleanupUrlQueryParams(referrerUrl, referrerCleanerQueryParams);
        if (normalizedReferrerUrl === null) {
            return;
        }

        options.forEachMatchingReferrerMedia([normalizedReferrerUrl], (mediaElement) => {
            options.markReferrerSettled(mediaElement, normalizedReferrerUrl);

            const reactionForDecoration = reaction === undefined
                ? parseKnownReaction(mediaElement.getAttribute('data-atlas-anchor-reaction'))
                : reaction;

            const blacklistedForDecoration = blacklistedAt === undefined
                ? mediaElement.getAttribute('data-atlas-anchor-blacklisted-at')
                : blacklistedAt;

            if (blacklistedForDecoration) {
                applyAnchorBlacklistedDecoration(mediaElement);
            } else {
                applyAnchorMatchDecoration(mediaElement, reactionForDecoration);
            }

            mediaElement.setAttribute(ANCHOR_MEDIA_BORDER_ATTR, '1');
            mediaElement.setAttribute(ANCHOR_MEDIA_MATCH_ATTR, '1');
            mediaElement.removeAttribute('data-atlas-anchor-checking');
            mediaElement.removeAttribute('data-atlas-anchor-opened-elsewhere');
            mediaElement.removeAttribute(ANCHOR_MEDIA_SAME_PAGE_ATTR);

            if (reaction !== undefined) {
                if (reaction) {
                    mediaElement.setAttribute('data-atlas-anchor-reaction', reaction);
                } else {
                    mediaElement.removeAttribute('data-atlas-anchor-reaction');
                }
            }

            if (downloadedAt !== undefined) {
                if (downloadedAt) {
                    mediaElement.setAttribute('data-atlas-anchor-downloaded-at', downloadedAt);
                } else {
                    mediaElement.removeAttribute('data-atlas-anchor-downloaded-at');
                }
            }

            if (blacklistedAt !== undefined) {
                if (blacklistedAt) {
                    mediaElement.setAttribute('data-atlas-anchor-blacklisted-at', blacklistedAt);
                } else {
                    mediaElement.removeAttribute('data-atlas-anchor-blacklisted-at');
                }
            }
        });
    }

    function applyPendingForReferrerUrls(referrerUrls: string[]): void {
        options.forEachMatchingReferrerMedia(referrerUrls, (mediaElement) => {
            applyAnchorCheckingDecoration(mediaElement);
            mediaElement.setAttribute(ANCHOR_MEDIA_BORDER_ATTR, '1');
            mediaElement.setAttribute(ANCHOR_MEDIA_MATCH_ATTR, '0');
            mediaElement.setAttribute('data-atlas-anchor-checking', '1');
            mediaElement.removeAttribute('data-atlas-anchor-opened-elsewhere');
            mediaElement.removeAttribute(ANCHOR_MEDIA_SAME_PAGE_ATTR);
            mediaElement.removeAttribute('data-atlas-anchor-reaction');
            mediaElement.removeAttribute('data-atlas-anchor-downloaded-at');
            mediaElement.removeAttribute('data-atlas-anchor-blacklisted-at');
        });
    }

    function refreshReferrerUrlsFromCache(referrerUrls: string[]): void {
        options.forEachMatchingReferrerMedia(referrerUrls, (mediaElement) => {
            options.applyAnchorMediaBorderFromCache(mediaElement);
        });
    }

    return {
        applyPendingForReferrerUrls,
        applyReactionForReferrerUrl,
        refreshReferrerUrlsFromCache,
    };
}
