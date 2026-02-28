import type { MediaCandidate } from './types';
import type { ContentMatchRule } from './storage';

function isMediaElement(element: Element): element is HTMLImageElement | HTMLVideoElement {
    return element instanceof HTMLImageElement || element instanceof HTMLVideoElement;
}

function resolveMediaUrl(element: HTMLImageElement | HTMLVideoElement): string | null {
    if (element instanceof HTMLImageElement) {
        return element.currentSrc || element.src || element.getAttribute('src') || null;
    }

    return element.currentSrc || element.src || element.poster || element.getAttribute('src') || null;
}

function normalizeUrl(value: string | null | undefined): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }

    return trimmed.replace(/#.*$/, '');
}

function isHttpUrl(url: string | null): url is string {
    return typeof url === 'string' && /^https?:\/\//i.test(url);
}

function hostMatchesDomain(hostname: string, domain: string): boolean {
    const normalizedHost = hostname.toLowerCase();
    const normalizedDomain = domain.toLowerCase();

    return normalizedHost === normalizedDomain || normalizedHost.endsWith(`.${normalizedDomain}`);
}

function urlMatchesRules(url: string | null, rules: ContentMatchRule[]): boolean {
    if (!isHttpUrl(url)) {
        return false;
    }

    if (rules.length === 0) {
        return true;
    }

    let parsed: URL;
    try {
        parsed = new URL(url);
    } catch {
        return false;
    }

    const matchedDomainRules = rules.filter((rule) => hostMatchesDomain(parsed.hostname, rule.domain));
    if (matchedDomainRules.length === 0) {
        return false;
    }

    return matchedDomainRules.some((rule) =>
        rule.regexes.some((pattern) => {
            try {
                return new RegExp(pattern, 'i').test(url);
            } catch {
                return false;
            }
        }),
    );
}

function collectAnchoredMedia(
    seen: Set<Element>,
    candidates: MediaCandidate[],
    pageUrl: string,
    matchRules: ContentMatchRule[],
): void {
    const anchors = document.querySelectorAll('a[href]');
    for (const anchor of anchors) {
        const anchorUrl = normalizeUrl((anchor as HTMLAnchorElement).href);
        const mediaElements = anchor.querySelectorAll('img,video');

        for (const mediaElement of mediaElements) {
            if (!isMediaElement(mediaElement) || seen.has(mediaElement)) {
                continue;
            }

            seen.add(mediaElement);
            const mediaUrl = normalizeUrl(resolveMediaUrl(mediaElement));
            const validMediaUrl = urlMatchesRules(mediaUrl, matchRules) ? mediaUrl : null;
            const validAnchorUrl = urlMatchesRules(anchorUrl, matchRules) ? anchorUrl : null;
            const validPageUrl = urlMatchesRules(pageUrl, matchRules) ? pageUrl : null;

            candidates.push({
                element: mediaElement,
                payload: {
                    id: '',
                    anchor_url: validAnchorUrl,
                    media_url: validMediaUrl,
                    page_url: validPageUrl,
                },
            });
        }
    }
}

function collectStandaloneMedia(
    seen: Set<Element>,
    candidates: MediaCandidate[],
    pageUrl: string,
    matchRules: ContentMatchRule[],
): void {
    const mediaElements = document.querySelectorAll('img,video');
    for (const mediaElement of mediaElements) {
        if (!isMediaElement(mediaElement) || seen.has(mediaElement)) {
            continue;
        }

        if (mediaElement.closest('a[href]') !== null) {
            continue;
        }

        seen.add(mediaElement);
        const mediaUrl = normalizeUrl(resolveMediaUrl(mediaElement));
        const validMediaUrl = urlMatchesRules(mediaUrl, matchRules) ? mediaUrl : null;
        const validPageUrl = urlMatchesRules(pageUrl, matchRules) ? pageUrl : null;

        candidates.push({
            element: mediaElement,
            payload: {
                id: '',
                anchor_url: null,
                media_url: validMediaUrl,
                page_url: validPageUrl,
            },
        });
    }
}

export function scanMediaCandidates(limit = 300, matchRules: ContentMatchRule[] = []): MediaCandidate[] {
    const pageUrl = normalizeUrl(window.location.href) ?? window.location.href;
    const seen = new Set<Element>();
    const candidates: MediaCandidate[] = [];

    collectAnchoredMedia(seen, candidates, pageUrl, matchRules);
    collectStandaloneMedia(seen, candidates, pageUrl, matchRules);

    return candidates
        .filter((candidate) => candidate.payload.media_url !== null || candidate.payload.anchor_url !== null)
        .slice(0, limit)
        .map((candidate, index) => ({
            ...candidate,
            payload: {
                ...candidate.payload,
                id: `atlas-${index}`,
            },
        }));
}
