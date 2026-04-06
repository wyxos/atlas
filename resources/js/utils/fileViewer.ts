/**
 * FileViewer helpers.
 *
 * Note: This module intentionally uses named exports (no default export)
 * for consistency and safer refactors.
 */
import type { FeedItem } from '@/composables/useTabs';
import { getMimeTypeCategory } from '@/utils/file';

export type FileViewerOverlayMediaType = 'image' | 'video' | 'audio' | 'file';
type ImageDimensions = { width: number; height: number };

type PendingImagePreload = {
    promise: Promise<ImageDimensions>;
    consumers: number;
    abort: () => void;
};

type PendingVideoPreload = {
    promise: Promise<void>;
    consumers: number;
};

const MAX_COMPLETED_IMAGE_PRELOADS = 64;
const MAX_COMPLETED_VIDEO_PRELOADS = 32;
const completedImagePreloads = new Map<string, ImageDimensions>();
const pendingImagePreloads = new Map<string, PendingImagePreload>();
const completedVideoPreloads = new Set<string>();
const pendingVideoPreloads = new Map<string, PendingVideoPreload>();

function createImagePreloadAbortError(): Error {
    try {
        return new DOMException('Image preload aborted', 'AbortError');
    } catch {
        const error = new Error('Image preload aborted');
        error.name = 'AbortError';
        return error;
    }
}

function rememberCompletedImagePreload(url: string, dimensions: ImageDimensions): void {
    if (completedImagePreloads.has(url)) {
        completedImagePreloads.delete(url);
    }

    completedImagePreloads.set(url, dimensions);

    while (completedImagePreloads.size > MAX_COMPLETED_IMAGE_PRELOADS) {
        const oldestUrl = completedImagePreloads.keys().next().value;
        if (typeof oldestUrl !== 'string') {
            break;
        }

        completedImagePreloads.delete(oldestUrl);
    }
}

function rememberCompletedVideoPreload(url: string): void {
    if (completedVideoPreloads.has(url)) {
        completedVideoPreloads.delete(url);
    }

    completedVideoPreloads.add(url);

    while (completedVideoPreloads.size > MAX_COMPLETED_VIDEO_PRELOADS) {
        const oldestUrl = completedVideoPreloads.values().next().value;
        if (typeof oldestUrl !== 'string') {
            break;
        }

        completedVideoPreloads.delete(oldestUrl);
    }
}

function createPendingImagePreload(url: string): PendingImagePreload {
    const img = new Image();
    let settled = false;
    let resolvePromise: ((value: ImageDimensions) => void) | null = null;
    let rejectPromise: ((reason?: unknown) => void) | null = null;

    const cleanup = (): void => {
        img.onload = null;
        img.onerror = null;
    };

    const rejectOnce = (error: Error): void => {
        if (settled) {
            return;
        }

        settled = true;
        cleanup();
        pendingImagePreloads.delete(url);
        rejectPromise?.(error);
    };

    const entry: PendingImagePreload = {
        promise: new Promise<ImageDimensions>((resolve, reject) => {
            resolvePromise = resolve;
            rejectPromise = reject;
        }),
        consumers: 0,
        abort: () => {
            try {
                img.src = '';
            } catch {
                // Ignore browser-specific image reset errors.
            }

            rejectOnce(createImagePreloadAbortError());
        },
    };

    img.onload = () => {
        if (settled) {
            return;
        }

        settled = true;
        cleanup();
        const dimensions = { width: img.naturalWidth, height: img.naturalHeight };
        pendingImagePreloads.delete(url);
        rememberCompletedImagePreload(url, dimensions);
        resolvePromise?.(dimensions);
    };
    img.onerror = () => rejectOnce(new Error(`Failed to load image: ${url}`));
    img.src = url;
    pendingImagePreloads.set(url, entry);

    return entry;
}

function attachImagePreloadConsumer(
    url: string,
    entry: PendingImagePreload,
    signal?: AbortSignal,
): Promise<ImageDimensions> {
    entry.consumers += 1;

    return new Promise((resolve, reject) => {
        let settled = false;

        const cleanup = (): void => {
            if (settled) {
                return;
            }

            settled = true;
            entry.consumers = Math.max(0, entry.consumers - 1);
            signal?.removeEventListener('abort', handleAbort);
        };

        const handleAbort = (): void => {
            if (settled) {
                return;
            }

            cleanup();
            if (entry.consumers === 0 && pendingImagePreloads.get(url) === entry) {
                entry.abort();
            }

            reject(createImagePreloadAbortError());
        };

        if (signal?.aborted) {
            handleAbort();
            return;
        }

        signal?.addEventListener('abort', handleAbort, { once: true });

        entry.promise.then(
            (value) => {
                if (settled) {
                    return;
                }

                cleanup();
                resolve(value);
            },
            (error) => {
                if (settled) {
                    return;
                }

                cleanup();
                reject(error);
            },
        );
    });
}

export function preloadImage(url: string, signal?: AbortSignal): Promise<ImageDimensions> {
    const normalizedUrl = url.trim();
    if (normalizedUrl === '') {
        return Promise.reject(new Error('Failed to load image:'));
    }

    const completed = completedImagePreloads.get(normalizedUrl);
    if (completed) {
        return Promise.resolve(completed);
    }

    const pending = pendingImagePreloads.get(normalizedUrl) ?? createPendingImagePreload(normalizedUrl);

    return attachImagePreloadConsumer(normalizedUrl, pending, signal);
}

export function preloadVideoMetadata(url: string): Promise<void> {
    const normalizedUrl = url.trim();
    if (normalizedUrl === '') {
        return Promise.resolve();
    }

    if (completedVideoPreloads.has(normalizedUrl)) {
        return Promise.resolve();
    }

    const pending = pendingVideoPreloads.get(normalizedUrl);
    if (pending) {
        pending.consumers += 1;

        return pending.promise.finally(() => {
            pending.consumers = Math.max(0, pending.consumers - 1);
        });
    }

    const promise = new Promise<void>((resolve) => {
        const video = document.createElement('video');
        video.preload = 'metadata';

        const complete = (): void => {
            video.onloadedmetadata = null;
            video.onerror = null;
            pendingVideoPreloads.delete(normalizedUrl);
            rememberCompletedVideoPreload(normalizedUrl);
            resolve();
        };

        video.onloadedmetadata = complete;
        video.onerror = complete;
        video.src = normalizedUrl;
        video.load();
    });

    pendingVideoPreloads.set(normalizedUrl, {
        promise,
        consumers: 1,
    });

    return promise.finally(() => {
        const entry = pendingVideoPreloads.get(normalizedUrl);
        if (!entry) {
            return;
        }

        entry.consumers = Math.max(0, entry.consumers - 1);
    });
}

export function clearFileViewerPreloadCache(options: { abortPending?: boolean } = {}): void {
    completedImagePreloads.clear();
    completedVideoPreloads.clear();

    if (!options.abortPending) {
        return;
    }

    for (const entry of pendingImagePreloads.values()) {
        entry.abort();
    }
    pendingImagePreloads.clear();
    pendingVideoPreloads.clear();
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

    const mimeCategory = getMimeTypeCategory(typeof item.mime_type === 'string' ? item.mime_type : null);
    if (mimeCategory === 'video') return 'video';
    if (mimeCategory === 'image') return 'image';
    if (mimeCategory === 'audio') return 'audio';

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
