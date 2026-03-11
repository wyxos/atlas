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

export type FileViewerPagingMediaTarget = {
    mediaType: FileViewerOverlayMediaType;
    previewSrc: string;
    fullSizeUrl: string;
    overlayImage: { src: string; srcset?: string; sizes?: string; alt?: string };
    originalDimensions: { width: number; height: number };
    initialFullSizeImage: string | null;
    isLoading: boolean;
    isVideo: boolean;
    isAudio: boolean;
    isFile: boolean;
};

type FileViewerPagingLayoutOptions = {
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

export function resolveFileViewerPagingMediaTarget(item: FeedItem): FileViewerPagingMediaTarget {
    const mediaType = resolveFileViewerMediaType(item);
    const previewSrc = resolveFileViewerPreviewUrl(item);
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
            srcset: undefined,
            sizes: undefined,
            alt: item.id.toString(),
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

export function calculateFileViewerPagingLayout(options: FileViewerPagingLayoutOptions): {
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
