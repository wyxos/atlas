/**
 * FileViewer helpers.
 *
 * Note: This module intentionally uses named exports (no default export)
 * for consistency and safer refactors.
 */
import type { FeedItem } from '@/composables/useTabs';

export type FileViewerOverlayMediaType = 'image' | 'video' | 'audio' | 'file';

export function preloadImage(url: string): Promise<{ width: number; height: number }> {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve({ width: img.naturalWidth, height: img.naturalHeight });
        img.onerror = () => reject(new Error(`Failed to load image: ${url}`));
        img.src = url;
    });
}

export function calculateBestFitSize(
    originalWidth: number,
    originalHeight: number,
    containerWidth: number,
    containerHeight: number
): { width: number; height: number } {
    if (originalWidth <= containerWidth && originalHeight <= containerHeight) {
        return {
            width: originalWidth,
            height: originalHeight,
        };
    }

    const aspectRatio = originalWidth / originalHeight;
    const containerAspectRatio = containerWidth / containerHeight;

    let fitWidth: number;
    let fitHeight: number;

    if (aspectRatio > containerAspectRatio) {
        fitWidth = containerWidth;
        fitHeight = containerWidth / aspectRatio;
    } else {
        fitHeight = containerHeight;
        fitWidth = containerHeight * aspectRatio;
    }

    fitWidth = Math.min(fitWidth, containerWidth);
    fitHeight = Math.min(fitHeight, containerHeight);

    return {
        width: Math.floor(fitWidth),
        height: Math.floor(fitHeight),
    };
}

export function getAvailableWidth(
    containerWidth: number,
    borderWidth: number,
    isFilled: boolean,
    fillComplete: boolean,
    isClosing: boolean,
    sheetOpen: boolean
): number {
    const taskbarWidth = isFilled && fillComplete && !isClosing && !sheetOpen ? 64 : 0; // w-16
    const sheetWidth = isFilled && fillComplete && !isClosing && sheetOpen ? 320 : 0; // w-80
    return containerWidth - (borderWidth * 2) - taskbarWidth - sheetWidth;
}

export function getCenteredPosition(
    containerWidth: number,
    containerHeight: number,
    imageWidth: number,
    imageHeight: number,
): { top: number; left: number } {
    return {
        top: Math.round((containerHeight - imageHeight) / 2),
        left: Math.round((containerWidth - imageWidth) / 2),
    };
}

export function resolveFileViewerMediaType(item: FeedItem): FileViewerOverlayMediaType {
    const kind = typeof item.media_kind === 'string' ? item.media_kind : null;

    if (kind === 'image' || kind === 'video' || kind === 'audio' || kind === 'file') {
        return kind;
    }

    const mime = typeof item.mime_type === 'string' ? item.mime_type : '';
    if (mime.startsWith('video/')) return 'video';
    if (mime.startsWith('image/')) return 'image';
    if (mime.startsWith('audio/')) return 'audio';

    return item.type === 'video' ? 'video' : 'image';
}

export function normalizeFileViewerMediaUrl(
    url: string | null | undefined,
    mediaType: FileViewerOverlayMediaType,
): string {
    if (typeof url !== 'string') {
        return '';
    }

    const value = url.trim();
    if (mediaType !== 'video' || value === '') {
        return value;
    }

    // Guard against malformed payloads that pass preview endpoint as the playback URL.
    const match = value.match(/^(.*\/api\/files\/\d+)\/preview(\?.*)?$/);
    if (!match) {
        return value;
    }

    const base = match[1];
    const query = match[2] ?? '';
    return `${base}/downloaded${query}`;
}

export function resolveFileViewerFullSizeUrl(
    item: FeedItem,
    fallback: string,
    mediaType: FileViewerOverlayMediaType,
): string {
    const candidates = [item.original, item.originalUrl, fallback];

    for (const candidate of candidates) {
        if (typeof candidate !== 'string') {
            continue;
        }

        const value = candidate.trim();
        if (value === '') {
            continue;
        }

        return normalizeFileViewerMediaUrl(value, mediaType);
    }

    return normalizeFileViewerMediaUrl(fallback, mediaType);
}

export function resolveFileViewerPreviewUrl(item: FeedItem): string {
    const candidates = [item.preview, item.original, item.src, item.thumbnail, item.originalUrl];

    for (const candidate of candidates) {
        if (typeof candidate !== 'string') {
            continue;
        }

        const value = candidate.trim();
        if (value !== '') {
            return value;
        }
    }

    return '';
}

export type MasonryItemLike = {
    id: number;
    key?: string;
    src?: string;
    thumbnail?: string;
};

export function findMasonryItemByImageSrc<T extends MasonryItemLike>(
    imageSrc: string,
    itemElement: HTMLElement,
    items: Array<T>
): T | null {
    const itemKeyAttr = itemElement.getAttribute('data-key');
    if (itemKeyAttr) {
        const itemByKey = items.find((i) => i.key === itemKeyAttr);
        if (itemByKey) {
            return itemByKey;
        }

        const parts = itemKeyAttr.split('-');
        const fileId = parts.length > 1 ? Number(parts[parts.length - 1]) : Number(itemKeyAttr);
        if (!isNaN(fileId)) {
            const item = items.find((i) => i.id === fileId);
            if (item) {
                return item;
            }
        }
    }

    const baseSrc = imageSrc.split('?')[0].split('#')[0];
    return (
        items.find((item) => {
            const itemSrc = (item.src || item.thumbnail || '').split('?')[0].split('#')[0];
            return baseSrc === itemSrc || baseSrc.includes(itemSrc) || itemSrc.includes(baseSrc);
        }) || null
    );
}
