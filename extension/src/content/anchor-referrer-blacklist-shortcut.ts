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

function isAltRightClickShortcut(event: MouseEvent): boolean {
    return event.type === 'mousedown' && event.altKey && event.button === 2;
}

function resolveAnchorMedia(anchor: HTMLAnchorElement): MediaElement | null {
    const outlinedMedia = anchor.querySelector(`img[${ANCHOR_MEDIA_BORDER_ATTR}="1"],video[${ANCHOR_MEDIA_BORDER_ATTR}="1"]`);
    if (outlinedMedia && isMediaElement(outlinedMedia)) {
        return outlinedMedia;
    }

    const media = anchor.querySelector('img,video');
    return media && isMediaElement(media) ? media : null;
}

function resolveMediaFromElement(element: Element): MediaElement | null {
    const fromTarget = element.closest('img,video');
    if (fromTarget && isMediaElement(fromTarget)) {
        return fromTarget;
    }

    const anchor = element.closest('a[href]');
    if (anchor instanceof HTMLAnchorElement) {
        return resolveAnchorMedia(anchor);
    }

    return null;
}

function resolveMediaFromEvent(event: MouseEvent): MediaElement | null {
    const target = event.target;
    if (target instanceof Element) {
        const targetMedia = resolveMediaFromElement(target);
        if (targetMedia !== null) {
            return targetMedia;
        }
    }

    for (const element of document.elementsFromPoint(event.clientX, event.clientY)) {
        const pointMedia = resolveMediaFromElement(element);
        if (pointMedia !== null) {
            return pointMedia;
        }
    }

    return null;
}

export function handleAltRightClickReferrerBlacklist(options: ReferrerBlacklistShortcutOptions): boolean {
    if (options.isPaused() || !isAltRightClickShortcut(options.event)) {
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
                options.refreshReferrerUrlsFromCache([referrerUrl]);
            }
        })
        .catch(() => {
            options.refreshReferrerUrlsFromCache([referrerUrl]);
        });

    return true;
}
