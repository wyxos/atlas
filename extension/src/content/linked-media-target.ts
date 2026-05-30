import { urlMatchesAnyRule, type UrlMatchRule } from '../match-rules';
import { normalizeUrl, type MediaElement } from './media-utils';

type XStatusTarget = {
    id: string;
    url: string;
};

function isXStatusHost(hostname: string): boolean {
    const normalized = hostname.toLowerCase().replace(/^www\./, '').replace(/^mobile\./, '');

    return normalized === 'x.com' || normalized === 'twitter.com';
}

function extractXStatusTarget(value: string | null): XStatusTarget | null {
    const normalizedUrl = normalizeUrl(value);
    if (normalizedUrl === null) {
        return null;
    }

    try {
        const parsed = new URL(normalizedUrl);
        if (!isXStatusHost(parsed.hostname)) {
            return null;
        }

        const segments = parsed.pathname.split('/').filter((segment) => segment !== '');
        const statusIndex = segments.findIndex((segment) => segment === 'status');
        const statusId = statusIndex >= 0 ? segments[statusIndex + 1] : null;
        if (typeof statusId !== 'string' || !/^\d+$/.test(statusId)) {
            return null;
        }

        return {
            id: statusId,
            url: normalizedUrl,
        };
    } catch {
        return null;
    }
}

function closestLinkedAnchor(media: MediaElement): HTMLAnchorElement | null {
    const anchor = media.closest('a[href]');

    return anchor instanceof HTMLAnchorElement ? anchor : null;
}

function resolveSameStatusLinkedMediaTargetUrl(
    media: MediaElement,
    pageUrl: string = window.location.href,
): string | null {
    const anchor = closestLinkedAnchor(media);
    if (anchor === null) {
        return null;
    }

    const pageTarget = extractXStatusTarget(pageUrl);
    const anchorTarget = extractXStatusTarget(anchor.href);
    if (pageTarget === null || anchorTarget === null || pageTarget.id !== anchorTarget.id) {
        return null;
    }

    return anchorTarget.url;
}

function shouldSkipLinkedMedia(
    media: MediaElement,
    pageUrl: string = window.location.href,
): boolean {
    return closestLinkedAnchor(media) !== null && resolveSameStatusLinkedMediaTargetUrl(media, pageUrl) === null;
}

function sameStatusLinkedMediaTargetMatchesRules(
    media: MediaElement,
    pageUrl: string,
    rules: UrlMatchRule[],
    pageHostname: string,
): boolean {
    return urlMatchesAnyRule(resolveSameStatusLinkedMediaTargetUrl(media, pageUrl), rules, pageHostname);
}

export {
    resolveSameStatusLinkedMediaTargetUrl,
    sameStatusLinkedMediaTargetMatchesRules,
    shouldSkipLinkedMedia,
};
