import type { MediaCandidate } from './types';
import { urlMatchesAnyRule, type UrlMatchRule } from '../match-rules';

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

function isElementVisibleInViewport(element: Element): boolean {
    const rect = element.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) {
        return false;
    }

    if (rect.bottom < 0 || rect.right < 0 || rect.top > window.innerHeight || rect.left > window.innerWidth) {
        return false;
    }

    const style = window.getComputedStyle(element);

    return style.visibility !== 'hidden' && style.display !== 'none' && style.opacity !== '0';
}

function collectAnchoredMedia(
    seen: Set<Element>,
    candidates: MediaCandidate[],
    matchRules: UrlMatchRule[],
    pageHostname: string,
): void {
    const anchors = document.querySelectorAll('a[href]');
    for (const anchor of anchors) {
        const anchorUrl = normalizeUrl((anchor as HTMLAnchorElement).href);
        const mediaElements = anchor.querySelectorAll('img,video');

        for (const mediaElement of mediaElements) {
            if (!isMediaElement(mediaElement) || seen.has(mediaElement)) {
                continue;
            }
            if (!isElementVisibleInViewport(mediaElement)) {
                continue;
            }

            seen.add(mediaElement);
            const mediaUrl = normalizeUrl(resolveMediaUrl(mediaElement));
            const validMediaUrl = urlMatchesAnyRule(mediaUrl, matchRules, pageHostname) ? mediaUrl : null;
            const validAnchorUrl = urlMatchesAnyRule(anchorUrl, matchRules, pageHostname) ? anchorUrl : null;
            candidates.push({
                element: mediaElement,
                id: '',
                anchorUrl: validAnchorUrl,
                mediaUrl: validMediaUrl,
            });
        }
    }
}

function collectStandaloneMedia(
    seen: Set<Element>,
    candidates: MediaCandidate[],
    matchRules: UrlMatchRule[],
    pageHostname: string,
): void {
    const mediaElements = document.querySelectorAll('img,video');
    for (const mediaElement of mediaElements) {
        if (!isMediaElement(mediaElement) || seen.has(mediaElement)) {
            continue;
        }

        if (mediaElement.closest('a[href]') !== null) {
            continue;
        }
        if (!isElementVisibleInViewport(mediaElement)) {
            continue;
        }

        seen.add(mediaElement);
        const mediaUrl = normalizeUrl(resolveMediaUrl(mediaElement));
        const validMediaUrl = urlMatchesAnyRule(mediaUrl, matchRules, pageHostname) ? mediaUrl : null;
        candidates.push({
            element: mediaElement,
            id: '',
            anchorUrl: null,
            mediaUrl: validMediaUrl,
        });
    }
}

export function scanMediaCandidates(limit = 300, matchRules: UrlMatchRule[] = []): MediaCandidate[] {
    const pageHostname = window.location.hostname;
    const seen = new Set<Element>();
    const candidates: MediaCandidate[] = [];

    collectAnchoredMedia(seen, candidates, matchRules, pageHostname);
    collectStandaloneMedia(seen, candidates, matchRules, pageHostname);

    return candidates
        .filter((candidate) => candidate.mediaUrl !== null || candidate.anchorUrl !== null)
        .slice(0, limit)
        .map((candidate, index) => ({
            ...candidate,
            id: `atlas-${index}`,
        }));
}
