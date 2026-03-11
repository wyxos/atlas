import type { FeedItem } from '@/composables/useTabs';
import {
    calculateBestFitSize,
    getAvailableWidth,
    getCenteredPosition,
    resolveFileViewerFullSizeUrl,
    resolveFileViewerMediaType,
    resolveFileViewerPreviewUrl,
    type FileViewerOverlayMediaType,
} from './fileViewer';

export type FileViewerOverlayImage = {
    src: string;
    srcset?: string;
    sizes?: string;
    alt?: string;
};

export type FileViewerOverlayMediaTarget = {
    mediaType: FileViewerOverlayMediaType;
    previewSrc: string;
    fullSizeUrl: string;
    overlayImage: FileViewerOverlayImage;
    originalDimensions: { width: number; height: number };
    initialFullSizeImage: string | null;
    isLoading: boolean;
    isVideo: boolean;
    isAudio: boolean;
    isFile: boolean;
};

type FileViewerOverlayLayoutOptions = {
    containerWidth: number;
    containerHeight: number;
    borderWidth: number;
    mediaWidth: number;
    mediaHeight: number;
    isFilled: boolean;
    fillComplete: boolean;
    isClosing: boolean;
    isSheetOpen: boolean;
};

type FileViewerOverlayMediaOverrides = {
    previewSrc?: string;
    srcset?: string;
    sizes?: string;
    alt?: string;
};

function resolveOverlayPreviewSrc(item: FeedItem, previewSrc?: string): string {
    if (typeof previewSrc === 'string') {
        const value = previewSrc.trim();
        if (value !== '') {
            return value;
        }
    }

    return resolveFileViewerPreviewUrl(item);
}

export function resolveFileViewerOverlayMediaTarget(
    item: FeedItem,
    overrides: FileViewerOverlayMediaOverrides = {},
): FileViewerOverlayMediaTarget {
    const mediaType = resolveFileViewerMediaType(item);
    const previewSrc = resolveOverlayPreviewSrc(item, overrides.previewSrc);
    const fullSizeUrl = resolveFileViewerFullSizeUrl(item, previewSrc, mediaType);
    const isVideo = mediaType === 'video';
    const isAudio = mediaType === 'audio';
    const isFile = mediaType === 'file';

    return {
        mediaType,
        previewSrc,
        fullSizeUrl,
        overlayImage: {
            src: previewSrc,
            srcset: overrides.srcset,
            sizes: overrides.sizes,
            alt: typeof overrides.alt === 'string' ? overrides.alt : item.id.toString(),
        },
        originalDimensions: {
            width: item.width,
            height: item.height,
        },
        initialFullSizeImage: isAudio || isFile ? previewSrc : null,
        isLoading: mediaType === 'image',
        isVideo,
        isAudio,
        isFile,
    };
}

export function calculateFileViewerOverlayLayout(options: FileViewerOverlayLayoutOptions): {
    availableWidth: number;
    availableHeight: number;
    imageSize: { width: number; height: number };
    centerPosition: { top: number; left: number };
} {
    const availableWidth = getAvailableWidth(
        options.containerWidth,
        options.borderWidth,
        options.isFilled,
        options.fillComplete,
        options.isClosing,
        options.isSheetOpen,
    );
    const availableHeight = options.containerHeight - (options.borderWidth * 2);
    const imageSize = calculateBestFitSize(
        options.mediaWidth,
        options.mediaHeight,
        availableWidth,
        availableHeight,
    );

    return {
        availableWidth,
        availableHeight,
        imageSize,
        centerPosition: getCenteredPosition(
            availableWidth,
            availableHeight,
            imageSize.width,
            imageSize.height,
        ),
    };
}

export function calculateFileViewerPreviewLayout(
    frameWidth: number,
    frameHeight: number,
    borderWidth: number,
): {
    imageSize: { width: number; height: number };
    centerPosition: { top: number; left: number };
} {
    const contentWidth = Math.max(frameWidth - (borderWidth * 2), 0);
    const contentHeight = Math.max(frameHeight - (borderWidth * 2), 0);
    const imageSize = {
        width: frameWidth,
        height: frameHeight,
    };

    return {
        imageSize,
        centerPosition: getCenteredPosition(
            contentWidth,
            contentHeight,
            imageSize.width,
            imageSize.height,
        ),
    };
}
