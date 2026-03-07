import { watch, toRefs, type Ref } from 'vue';
import {
    calculateBestFitSize,
    getAvailableWidth,
    getCenteredPosition,
} from '@/utils/fileViewer';

export function useFileViewerSheetSizing(params: {
    sheet: {
        isOpen: boolean;
    };
    overlay: {
        rect: { top: number; left: number; width: number; height: number } | null;
        imageSize: { width: number; height: number } | null;
        originalDimensions: { width: number; height: number } | null;
        isFilled: boolean;
        fillComplete: boolean;
        isClosing: boolean;
        centerPosition: { top: number; left: number } | null;
    };
    containerRef: Ref<HTMLElement | null>;
}) {
    const { isOpen } = toRefs(params.sheet);
    const {
        rect,
        imageSize,
        originalDimensions,
        isFilled,
        fillComplete,
        isClosing,
        centerPosition,
    } = toRefs(params.overlay);

    watch(() => isOpen.value, () => {
        if (rect.value && imageSize.value && originalDimensions.value && params.containerRef.value && fillComplete.value) {
            const tabContent = params.containerRef.value;
            const tabContentBox = tabContent.getBoundingClientRect();
            const containerWidth = tabContentBox.width;
            const containerHeight = tabContentBox.height;
            const borderWidth = 4;
            const availableWidth = getAvailableWidth(
                containerWidth,
                borderWidth,
                isFilled.value,
                fillComplete.value,
                isClosing.value,
                isOpen.value,
            );
            const availableHeight = containerHeight - (borderWidth * 2);

            const bestFitSize = calculateBestFitSize(
                originalDimensions.value.width,
                originalDimensions.value.height,
                availableWidth,
                availableHeight
            );

            imageSize.value = bestFitSize;
            centerPosition.value = getCenteredPosition(
                availableWidth,
                availableHeight,
                bestFitSize.width,
                bestFitSize.height
            );
        }
    });
}
