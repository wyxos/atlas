import { toRefs } from 'vue';
import {
    calculateBestFitSize as calculateFileViewerBestFitSize,
    getAvailableWidth as getFileViewerAvailableWidth,
    getCenteredPosition,
} from '@/utils/fileViewer';

export function useFileViewerSizing(params: {
    overlay: {
        isFilled: boolean;
        fillComplete: boolean;
        isClosing: boolean;
    };
    sheet: {
        isOpen: boolean;
    };
}) {
    const { isFilled, fillComplete, isClosing } = toRefs(params.overlay);
    const { isOpen } = toRefs(params.sheet);

    function getAvailableWidth(containerWidth: number, borderWidth: number): number {
        return getFileViewerAvailableWidth(
            containerWidth,
            borderWidth,
            isFilled.value,
            fillComplete.value,
            isClosing.value,
            isOpen.value,
        );
    }

    function calculateBestFitSize(
        originalWidth: number,
        originalHeight: number,
        containerWidth: number,
        containerHeight: number
    ): { width: number; height: number } {
        return calculateFileViewerBestFitSize(
            originalWidth,
            originalHeight,
            containerWidth,
            containerHeight,
        );
    }

    return {
        getAvailableWidth,
        calculateBestFitSize,
        getCenteredPosition,
    };
}
