import { ref } from 'vue';

export function useFileViewerOverlay() {
    const overlayRect = ref<{ top: number; left: number; width: number; height: number } | null>(null);
    const overlayImage = ref<{ src: string; srcset?: string; sizes?: string; alt?: string } | null>(null);
    const overlayBorderRadius = ref<string | null>(null);
    const overlayKey = ref(0);
    const overlayIsAnimating = ref(false);
    const overlayIsFilled = ref(false);
    const overlayFillComplete = ref(false);
    const overlayIsClosing = ref(false);
    const overlayScale = ref(1);
    const overlayIsLoading = ref(false);
    const overlayFullSizeImage = ref<string | null>(null);
    const originalImageDimensions = ref<{ width: number; height: number } | null>(null);
    const imageScale = ref(1);
    const imageTranslateX = ref(0);
    const isNavigating = ref(false);
    const navigationDirection = ref<'left' | 'right' | null>(null);
    const currentNavigationTarget = ref<number | null>(null);

    function resetOverlayState(
        overlayImageSize: { value: { width: number; height: number } | null },
        imageCenterPosition: { value: { top: number; left: number } | null },
        currentItemIndex: { value: number | null },
        isBottomPanelOpen: { value: boolean },
        isSheetOpen: { value: boolean }
    ): void {
        overlayKey.value++;
        overlayIsAnimating.value = false;
        overlayIsClosing.value = false;
        overlayIsFilled.value = false;
        overlayFillComplete.value = false;
        overlayScale.value = 1;
        overlayImageSize.value = null;
        imageCenterPosition.value = null;
        overlayRect.value = null;
        overlayImage.value = null;
        overlayBorderRadius.value = null;
        overlayIsLoading.value = false;
        overlayFullSizeImage.value = null;
        originalImageDimensions.value = null;
        currentItemIndex.value = null;
        imageScale.value = 1;
        imageTranslateX.value = 0;
        navigationDirection.value = null;
        isNavigating.value = false;
        isBottomPanelOpen.value = false;
        isSheetOpen.value = false;
    }

    return {
        overlayRect,
        overlayImage,
        overlayBorderRadius,
        overlayKey,
        overlayIsAnimating,
        overlayIsFilled,
        overlayFillComplete,
        overlayIsClosing,
        overlayScale,
        overlayIsLoading,
        overlayFullSizeImage,
        originalImageDimensions,
        imageScale,
        imageTranslateX,
        isNavigating,
        navigationDirection,
        currentNavigationTarget,
        resetOverlayState,
    };
}




