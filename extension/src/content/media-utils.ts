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
