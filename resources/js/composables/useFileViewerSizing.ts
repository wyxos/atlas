import type { Ref } from 'vue';

export function useFileViewerSizing(params: {
    overlayIsFilled: Ref<boolean>;
    overlayFillComplete: Ref<boolean>;
    overlayIsClosing: Ref<boolean>;
    isSheetOpen: Ref<boolean>;
}) {
    function getAvailableWidth(containerWidth: number, borderWidth: number): number {
        const taskbarWidth = params.overlayIsFilled.value && params.overlayFillComplete.value && !params.overlayIsClosing.value && !params.isSheetOpen.value ? 64 : 0;
        const sheetWidth = params.overlayIsFilled.value && params.overlayFillComplete.value && !params.overlayIsClosing.value && params.isSheetOpen.value ? 320 : 0;
        return containerWidth - (borderWidth * 2) - taskbarWidth - sheetWidth;
    }

    function calculateBestFitSize(
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

    function getCenteredPosition(containerWidth: number, containerHeight: number, imageWidth: number, imageHeight: number): { top: number; left: number } {
        return {
            top: Math.round((containerHeight - imageHeight) / 2),
            left: Math.round((containerWidth - imageWidth) / 2),
        };
    }

    return {
        getAvailableWidth,
        calculateBestFitSize,
        getCenteredPosition,
    };
}
