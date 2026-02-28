export type MediaElement = HTMLImageElement | HTMLVideoElement;

export type MediaResolution = {
    width: number;
    height: number;
};

export function isMediaElement(element: Element): element is MediaElement {
    return element instanceof HTMLImageElement || element instanceof HTMLVideoElement;
}

export function resolveMediaUrl(element: MediaElement): string | null {
    if (element instanceof HTMLImageElement) {
        return element.currentSrc || element.src || element.getAttribute('src') || null;
    }

    return element.currentSrc || element.src || element.poster || element.getAttribute('src') || null;
}

function resolveSourceUrl(source: HTMLSourceElement): string | null {
    return source.src || source.getAttribute('src') || null;
}

function isLikelyMp4Source(source: HTMLSourceElement, sourceUrl: string): boolean {
    const type = source.type?.toLowerCase() ?? '';
    if (type.includes('mp4')) {
        return true;
    }

    return /\.mp4(?:$|[?#])/i.test(sourceUrl);
}

export function resolveReactionMediaUrl(element: MediaElement): string | null {
    if (element instanceof HTMLImageElement) {
        return resolveMediaUrl(element);
    }

    const sources = Array.from(element.querySelectorAll('source'))
        .map((source) => {
            const sourceUrl = resolveSourceUrl(source);
            return sourceUrl === null ? null : { source, sourceUrl };
        })
        .filter((entry): entry is { source: HTMLSourceElement; sourceUrl: string } => entry !== null);

    const mp4Source = sources.find(({ source, sourceUrl }) => isLikelyMp4Source(source, sourceUrl));
    if (mp4Source) {
        return mp4Source.sourceUrl;
    }

    if (sources.length > 0) {
        return sources[0].sourceUrl;
    }

    return element.currentSrc || element.src || element.getAttribute('src') || element.poster || null;
}

export function resolveMediaResolution(element: MediaElement): MediaResolution | null {
    if (element instanceof HTMLImageElement) {
        const width = element.naturalWidth || element.width;
        const height = element.naturalHeight || element.height;

        return width > 0 && height > 0 ? { width, height } : null;
    }

    const width = element.videoWidth || element.clientWidth;
    const height = element.videoHeight || element.clientHeight;

    return width > 0 && height > 0 ? { width, height } : null;
}

export function normalizeUrl(value: string | null | undefined): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }

    if (!/^https?:\/\//i.test(trimmed)) {
        return null;
    }

    return trimmed.replace(/#.*$/, '');
}

export function collectMediaFromNode(node: Node): MediaElement[] {
    if (!(node instanceof Element)) {
        return [];
    }

    const media: MediaElement[] = [];
    if (isMediaElement(node)) {
        media.push(node);
    }

    for (const element of node.querySelectorAll('img,video')) {
        if (isMediaElement(element)) {
            media.push(element);
        }
    }

    return media;
}
