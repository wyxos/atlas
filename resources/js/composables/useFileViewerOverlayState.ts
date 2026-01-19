import type { Ref } from 'vue';

export function useFileViewerOverlayState(params: {
    containerRef: Ref<HTMLElement | null>;
    containerOverflow: Ref<string | null>;
    containerOverscroll: Ref<string | null>;
    overlayRect: Ref<{ top: number; left: number; width: number; height: number } | null>;
    overlayKey: Ref<number>;
    overlayIsAnimating: Ref<boolean>;
    overlayIsClosing: Ref<boolean>;
    overlayIsFilled: Ref<boolean>;
    overlayFillComplete: Ref<boolean>;
    overlayScale: Ref<number>;
    overlayImageSize: Ref<{ width: number; height: number } | null>;
    imageCenterPosition: Ref<{ top: number; left: number } | null>;
    overlayImage: Ref<{ src: string; srcset?: string; sizes?: string; alt?: string } | null>;
    overlayMediaType: Ref<'image' | 'video'>;
    overlayVideoSrc: Ref<string | null>;
    overlayBorderRadius: Ref<string | null>;
    overlayIsLoading: Ref<boolean>;
    overlayFullSizeImage: Ref<string | null>;
    originalImageDimensions: Ref<{ width: number; height: number } | null>;
    currentItemIndex: Ref<number | null>;
    imageScale: Ref<number>;
    imageTranslateY: Ref<number>;
    navigationDirection: Ref<'up' | 'down' | null>;
    isNavigating: Ref<boolean>;
    isSheetOpen: Ref<boolean>;
    emitClose: () => void;
}) {
    function resetOverlayState(): void {
        params.overlayKey.value++;
        params.overlayIsAnimating.value = false;
        params.overlayIsClosing.value = false;
        params.overlayIsFilled.value = false;
        params.overlayFillComplete.value = false;
        params.overlayScale.value = 1;
        params.overlayImageSize.value = null;
        params.imageCenterPosition.value = null;
        params.overlayRect.value = null;
        params.overlayImage.value = null;
        params.overlayMediaType.value = 'image';
        params.overlayVideoSrc.value = null;
        params.overlayBorderRadius.value = null;
        params.overlayIsLoading.value = false;
        params.overlayFullSizeImage.value = null;
        params.originalImageDimensions.value = null;
        params.currentItemIndex.value = null;
        params.imageScale.value = 1;
        params.imageTranslateY.value = 0;
        params.navigationDirection.value = null;
        params.isNavigating.value = false;
        params.isSheetOpen.value = false;
        params.emitClose();
    }

    function restoreContainerStyles(): void {
        const tabContent = params.containerRef.value;
        if (!tabContent) {
            return;
        }
        if (params.containerOverflow.value !== null) {
            tabContent.style.overflow = params.containerOverflow.value;
        } else {
            tabContent.style.removeProperty('overflow');
        }
        if (params.containerOverscroll.value !== null) {
            tabContent.style.overscrollBehavior = params.containerOverscroll.value;
        } else {
            tabContent.style.removeProperty('overscroll-behavior');
        }
        params.containerOverflow.value = null;
        params.containerOverscroll.value = null;
    }

    function closeOverlay(): void {
        if (!params.overlayRect.value) return;

        restoreContainerStyles();

        params.overlayIsClosing.value = true;
        params.overlayIsAnimating.value = true;

        const tabContent = params.containerRef.value;
        if (tabContent && params.overlayRect.value) {
            const tabContentBox = tabContent.getBoundingClientRect();
            const containerWidth = tabContentBox.width;
            const containerHeight = tabContentBox.height;

            const centerLeft = Math.round((containerWidth - params.overlayRect.value.width) / 2);
            const centerTop = Math.round((containerHeight - params.overlayRect.value.height) / 2);

            params.overlayRect.value = {
                ...params.overlayRect.value,
                top: centerTop,
                left: centerLeft,
            };

            params.overlayScale.value = 0;

            setTimeout(() => {
                resetOverlayState();
            }, 500);
        } else {
            resetOverlayState();
        }
    }

    return {
        closeOverlay,
        resetOverlayState,
        restoreContainerStyles,
    };
}
