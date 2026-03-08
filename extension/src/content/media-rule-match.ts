import { urlMatchesAnyRule, type UrlMatchRule } from '../match-rules';
import {
    isBlobBackedVideo,
    normalizeUrl,
    resolveReactionTargetUrl,
    shouldExcludeMediaOrAnchorUrl,
    type MediaElement,
} from './media-utils';

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

    // Blob-backed videos have no stable fetchable media URL, so keep the direct
    // video widget eligible the same way pages with no active domain rule do.
    if (isBlobBackedVideo(element)) {
        return true;
    }

    return urlMatchesAnyRule(mediaUrl, rules, pageHostname);
}
