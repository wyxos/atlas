import { ref, watch } from 'vue';
import { calculateBestFitSize, getAvailableWidth } from './useFileViewerUtils';

const BORDER_WIDTH = 4;
const PANEL_HEIGHT = 200;

export function useFileViewerImageSize(
    overlayRect: () => { width: number; height: number } | null,
    originalImageDimensions: () => { width: number; height: number } | null,
    containerRef: () => HTMLElement | null,
    overlayFillComplete: () => boolean,
    isBottomPanelOpen: () => boolean,
    isSheetOpen: () => boolean,
    overlayIsFilled: () => boolean,
    overlayIsClosing: () => boolean
) {
    const overlayImageSize = ref<{ width: number; height: number } | null>(null);
    const imageCenterPosition = ref<{ top: number; left: number } | null>(null);

    function recalculateImageSize(): void {
        if (!overlayRect() || !overlayImageSize.value || !originalImageDimensions() || !containerRef() || !overlayFillComplete()) {
            return;
        }

        const tabContent = containerRef()!;
        const tabContentBox = tabContent.getBoundingClientRect();
        const containerWidth = tabContentBox.width;
        const containerHeight = tabContentBox.height;
        const panelHeight = isBottomPanelOpen() ? PANEL_HEIGHT : 0;
        const availableWidth = getAvailableWidth(
            containerWidth,
            BORDER_WIDTH,
            overlayIsFilled(),
            overlayFillComplete(),
            overlayIsClosing(),
            isSheetOpen()
        );
        const availableHeight = containerHeight - (BORDER_WIDTH * 2) - panelHeight;

        const bestFitSize = calculateBestFitSize(
            originalImageDimensions()!.width,
            originalImageDimensions()!.height,
            availableWidth,
            availableHeight
        );

        overlayImageSize.value = bestFitSize;

        const fullImageLeft = Math.round((availableWidth - bestFitSize.width) / 2);
        const fullImageTop = Math.round((availableHeight - bestFitSize.height) / 2);

        imageCenterPosition.value = {
            top: fullImageTop,
            left: fullImageLeft,
        };
    }

    // Watch sheet open/close to recalculate image size immediately
    watch(() => isSheetOpen(), recalculateImageSize);

    // Watch bottom panel open/close to recalculate image size
    watch(() => isBottomPanelOpen(), recalculateImageSize);

    return {
        overlayImageSize,
        imageCenterPosition,
        recalculateImageSize,
    };
}






