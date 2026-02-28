import type { MediaCandidate } from './types';

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

function collectAnchoredMedia(seen: Set<Element>, candidates: MediaCandidate[], pageUrl: string): void {
    const anchors = document.querySelectorAll('a[href]');
    for (const anchor of anchors) {
        const anchorUrl = normalizeUrl((anchor as HTMLAnchorElement).href);
        const mediaElements = anchor.querySelectorAll('img,video');

        for (const mediaElement of mediaElements) {
            if (!isMediaElement(mediaElement) || seen.has(mediaElement)) {
                continue;
            }

            seen.add(mediaElement);

            candidates.push({
                element: mediaElement,
                payload: {
                    id: '',
                    anchor_url: anchorUrl,
                    media_url: normalizeUrl(resolveMediaUrl(mediaElement)),
                    page_url: pageUrl,
                },
            });
        }
    }
}

function collectStandaloneMedia(seen: Set<Element>, candidates: MediaCandidate[], pageUrl: string): void {
    const mediaElements = document.querySelectorAll('img,video');
    for (const mediaElement of mediaElements) {
        if (!isMediaElement(mediaElement) || seen.has(mediaElement)) {
            continue;
        }

        if (mediaElement.closest('a[href]') !== null) {
            continue;
        }

        seen.add(mediaElement);

        candidates.push({
            element: mediaElement,
            payload: {
                id: '',
                anchor_url: null,
                media_url: normalizeUrl(resolveMediaUrl(mediaElement)),
                page_url: pageUrl,
            },
        });
    }
}

export function scanMediaCandidates(limit = 300): MediaCandidate[] {
    const pageUrl = normalizeUrl(window.location.href) ?? window.location.href;
    const seen = new Set<Element>();
    const candidates: MediaCandidate[] = [];

    collectAnchoredMedia(seen, candidates, pageUrl);
    collectStandaloneMedia(seen, candidates, pageUrl);

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
