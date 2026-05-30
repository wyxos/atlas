import { isMediaElement, type MediaElement } from './media-utils';
import { ANCHOR_MEDIA_BORDER_ATTR, applyAnchorMediaMatch } from './anchor-media-state';
import { upsertReferrerCheckCache } from './referrer-check-queue';
import { submitBadgeReaction } from './reaction-submit';

type ReferrerBlacklistShortcutOptions = {
    event: MouseEvent;
    isPaused: () => boolean;
    resolveEligibleAnchorReferrerUrl: (
        anchor: HTMLAnchorElement,
        referrerCleanerQueryParams: string[],
    ) => string | null;
    getReferrerCleanerQueryParams: () => string[];
    applyPendingForReferrerUrls: (referrerUrls: string[]) => void;
    refreshReferrerUrlsFromCache: (referrerUrls: string[]) => void;
};

function resolveMediaFromEvent(event: MouseEvent): MediaElement | null {
    const target = event.target;
    if (target instanceof Element) {
        const fromTarget = target.closest('img,video');
        if (fromTarget && isMediaElement(fromTarget)) {
            return fromTarget;
        }
    }

    for (const element of document.elementsFromPoint(event.clientX, event.clientY)) {
        if (isMediaElement(element)) {
            return element;
        }
    }

    return null;
}

export function handleAltRightClickReferrerBlacklist(options: ReferrerBlacklistShortcutOptions): boolean {
    if (options.isPaused() || options.event.button !== 2 || !options.event.altKey) {
        return false;
    }

    const media = resolveMediaFromEvent(options.event);
    if (!media || media.getAttribute(ANCHOR_MEDIA_BORDER_ATTR) !== '1') {
        return false;
    }

    const anchor = media.closest('a[href]');
    if (!(anchor instanceof HTMLAnchorElement)) {
        return false;
    }

    const referrerCleanerQueryParams = options.getReferrerCleanerQueryParams();
    const referrerUrl = options.resolveEligibleAnchorReferrerUrl(anchor, referrerCleanerQueryParams);
    if (referrerUrl === null) {
        return false;
    }

    options.applyPendingForReferrerUrls([referrerUrl]);

    void submitBadgeReaction(media, 'blacklist', { referrerUrlOverride: referrerUrl })
        .then((result) => {
            if (!result.ok) {
                options.refreshReferrerUrlsFromCache([referrerUrl]);
                return;
            }

            const blacklistedAt = result.blacklistedAt ?? new Date().toISOString();
            upsertReferrerCheckCache(referrerUrl, {
                exists: true,
                reaction: null,
                reactedAt: null,
                downloadedAt: null,
                blacklistedAt,
            }, referrerCleanerQueryParams);

            if (!options.isPaused()) {
                applyAnchorMediaMatch(media, { reaction: null, downloadedAt: null, blacklistedAt });
            }
        })
        .catch(() => {
            options.refreshReferrerUrlsFromCache([referrerUrl]);
        });

    return true;
}
