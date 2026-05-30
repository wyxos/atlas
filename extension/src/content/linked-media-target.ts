import { urlMatchesAnyRule, type UrlMatchRule } from '../match-rules';
import { normalizeUrl, type MediaElement } from './media-utils';

function parseComparableUrl(value: string | null): URL | null {
    const normalizedUrl = normalizeUrl(value);
    if (normalizedUrl === null) {
        return null;
    }

    try {
        return new URL(normalizedUrl);
    } catch {
        return null;
    }
}

function normalizePathname(pathname: string): string {
    const normalized = pathname.replace(/\/+$/, '');

    return normalized === '' ? '/' : normalized;
}

function isSamePageOrChildUrl(pageUrl: URL, anchorUrl: URL): boolean {
    if (pageUrl.origin !== anchorUrl.origin) {
        return false;
    }

    const pagePath = normalizePathname(pageUrl.pathname);
    const anchorPath = normalizePathname(anchorUrl.pathname);

    return anchorPath === pagePath || anchorPath.startsWith(`${pagePath}/`);
}

function closestLinkedAnchor(media: MediaElement): HTMLAnchorElement | null {
    const anchor = media.closest('a[href]');

    return anchor instanceof HTMLAnchorElement ? anchor : null;
}

function resolveSamePageLinkedMediaTargetUrl(
    media: MediaElement,
    pageUrl: string = window.location.href,
): string | null {
    const anchor = closestLinkedAnchor(media);
    if (anchor === null) {
        return null;
    }

    const parsedPageUrl = parseComparableUrl(pageUrl);
    const parsedAnchorUrl = parseComparableUrl(anchor.href);
    if (parsedPageUrl === null || parsedAnchorUrl === null || !isSamePageOrChildUrl(parsedPageUrl, parsedAnchorUrl)) {
        return null;
    }

    return parsedAnchorUrl.href;
}

function shouldSkipLinkedMedia(
    media: MediaElement,
    pageUrl: string = window.location.href,
): boolean {
    return closestLinkedAnchor(media) !== null && resolveSamePageLinkedMediaTargetUrl(media, pageUrl) === null;
}

function samePageLinkedMediaTargetMatchesRules(
    media: MediaElement,
    pageUrl: string,
    rules: UrlMatchRule[],
    pageHostname: string,
): boolean {
    return urlMatchesAnyRule(resolveSamePageLinkedMediaTargetUrl(media, pageUrl), rules, pageHostname);
}

export {
    resolveSamePageLinkedMediaTargetUrl,
    samePageLinkedMediaTargetMatchesRules,
    shouldSkipLinkedMedia,
};
