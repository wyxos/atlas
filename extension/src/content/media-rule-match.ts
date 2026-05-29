import { hostMatchesRuleDomain, urlMatchesAnyRule, type UrlMatchRule } from '../match-rules';
import {
    isBlobBackedVideo,
    normalizeUrl,
    resolveReactionTargetUrl,
    shouldExcludeMediaOrAnchorUrl,
    type MediaElement,
} from './media-utils';

const BLOB_VIDEO_RULE_BYPASS_DOMAINS = ['reddit.com', 'redd.it'] as const;

function hostnameFromUrl(url: string | null): string | null {
    if (url === null) {
        return null;
    }

    try {
        return new URL(url).hostname.toLowerCase();
    } catch {
        return null;
    }
}

function shouldBypassRulesForBlobVideo(pageHostname: string | undefined, pageUrl: string | null): boolean {
    const hostname = pageHostname?.trim().toLowerCase() || hostnameFromUrl(pageUrl);
    if (hostname === null || hostname === '') {
        return false;
    }

    return BLOB_VIDEO_RULE_BYPASS_DOMAINS.some((domain) => hostMatchesRuleDomain(hostname, domain));
}

export function mediaMatchesRulesForPage(
    element: MediaElement,
    pageUrl: string | null,
    rules: UrlMatchRule[],
    pageHostname?: string,
): boolean {
    const normalizedPageUrl = normalizeUrl(pageUrl);
    const mediaUrl = resolveReactionTargetUrl(element, normalizedPageUrl);
    if (mediaUrl === null || shouldExcludeMediaOrAnchorUrl(mediaUrl)) {
        return false;
    }

    if (isBlobBackedVideo(element) && shouldBypassRulesForBlobVideo(pageHostname, normalizedPageUrl)) {
        return true;
    }

    return urlMatchesAnyRule(mediaUrl, rules, pageHostname);
}
