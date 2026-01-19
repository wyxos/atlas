import { watch, type Ref } from 'vue';

export function useFileViewerSheetSizing(params: {
    isSheetOpen: Ref<boolean>;
    overlayRect: Ref<{ top: number; left: number; width: number; height: number } | null>;
    overlayImageSize: Ref<{ width: number; height: number } | null>;
    originalImageDimensions: Ref<{ width: number; height: number } | null>;
    containerRef: Ref<HTMLElement | null>;
    overlayFillComplete: Ref<boolean>;
    getAvailableWidth: (containerWidth: number, borderWidth: number) => number;
    calculateBestFitSize: (
        originalWidth: number,
        originalHeight: number,
        containerWidth: number,
        containerHeight: number
    ) => { width: number; height: number };
    getCenteredPosition: (
        containerWidth: number,
        containerHeight: number,
        imageWidth: number,
        imageHeight: number
    ) => { top: number; left: number };
    imageCenterPosition: Ref<{ top: number; left: number } | null>;
}) {
    watch(() => params.isSheetOpen.value, () => {
        if (params.overlayRect.value && params.overlayImageSize.value && params.originalImageDimensions.value && params.containerRef.value && params.overlayFillComplete.value) {
            const tabContent = params.containerRef.value;
            const tabContentBox = tabContent.getBoundingClientRect();
            const containerWidth = tabContentBox.width;
            const containerHeight = tabContentBox.height;
            const borderWidth = 4;
            const availableWidth = params.getAvailableWidth(containerWidth, borderWidth);
            const availableHeight = containerHeight - (borderWidth * 2);

            const bestFitSize = params.calculateBestFitSize(
                params.originalImageDimensions.value.width,
                params.originalImageDimensions.value.height,
                availableWidth,
                availableHeight
            );

            params.overlayImageSize.value = bestFitSize;
            params.imageCenterPosition.value = params.getCenteredPosition(
                availableWidth,
                availableHeight,
                bestFitSize.width,
                bestFitSize.height
            );
        }
    });
}
