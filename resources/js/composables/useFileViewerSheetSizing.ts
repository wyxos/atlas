import { watch, toRefs, type Ref } from 'vue';

export function useFileViewerSheetSizing(params: {
    sheet: {
        isOpen: boolean;
    };
    overlay: {
        rect: { top: number; left: number; width: number; height: number } | null;
        imageSize: { width: number; height: number } | null;
        originalDimensions: { width: number; height: number } | null;
        fillComplete: boolean;
        centerPosition: { top: number; left: number } | null;
    };
    containerRef: Ref<HTMLElement | null>;
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
}) {
    const { isOpen } = toRefs(params.sheet);
    const {
        rect,
        imageSize,
        originalDimensions,
        fillComplete,
        centerPosition,
    } = toRefs(params.overlay);

    watch(() => isOpen.value, () => {
        if (rect.value && imageSize.value && originalDimensions.value && params.containerRef.value && fillComplete.value) {
            const tabContent = params.containerRef.value;
            const tabContentBox = tabContent.getBoundingClientRect();
            const containerWidth = tabContentBox.width;
            const containerHeight = tabContentBox.height;
            const borderWidth = 4;
            const availableWidth = params.getAvailableWidth(containerWidth, borderWidth);
            const availableHeight = containerHeight - (borderWidth * 2);

            const bestFitSize = params.calculateBestFitSize(
                originalDimensions.value.width,
                originalDimensions.value.height,
                availableWidth,
                availableHeight
            );

            imageSize.value = bestFitSize;
            centerPosition.value = params.getCenteredPosition(
                availableWidth,
                availableHeight,
                bestFitSize.width,
                bestFitSize.height
            );
        }
    });
}
