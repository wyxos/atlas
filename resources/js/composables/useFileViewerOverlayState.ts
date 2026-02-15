import { toRefs, type Ref } from 'vue';

type OverlayMediaType = 'image' | 'video' | 'audio' | 'file';

export function useFileViewerOverlayState(params: {
    containerRef: Ref<HTMLElement | null>;
    container: {
        overflow: string | null;
        overscroll: string | null;
    };
    overlay: {
        rect: { top: number; left: number; width: number; height: number } | null;
        key: number;
        isAnimating: boolean;
        isClosing: boolean;
        isFilled: boolean;
        fillComplete: boolean;
        scale: number;
        imageSize: { width: number; height: number } | null;
        centerPosition: { top: number; left: number } | null;
        image: { src: string; srcset?: string; sizes?: string; alt?: string } | null;
        mediaType: OverlayMediaType;
        videoSrc: string | null;
        audioSrc: string | null;
        borderRadius: string | null;
        isLoading: boolean;
        fullSizeImage: string | null;
        originalDimensions: { width: number; height: number } | null;
    };
    navigation: {
        currentItemIndex: number | null;
        imageScale: number;
        imageTranslateY: number;
        direction: 'up' | 'down' | null;
        isNavigating: boolean;
    };

    emitClose: () => void;
}) {
    const { overflow, overscroll } = toRefs(params.container);
    const {
        rect,
        key,
        isAnimating,
        isClosing,
        isFilled,
        fillComplete,
        scale,
        imageSize,
        centerPosition,
        image,
        mediaType,
        videoSrc,
        audioSrc,
        borderRadius,
        isLoading,
        fullSizeImage,
        originalDimensions,
    } = toRefs(params.overlay);
    const {
        currentItemIndex,
        imageScale,
        imageTranslateY,
        direction,
        isNavigating,
    } = toRefs(params.navigation);

    function resetOverlayState(): void {
        key.value++;
        isAnimating.value = false;
        isClosing.value = false;
        isFilled.value = false;
        fillComplete.value = false;
        scale.value = 1;
        imageSize.value = null;
        centerPosition.value = null;
        rect.value = null;
        image.value = null;
        mediaType.value = 'image';
        videoSrc.value = null;
        audioSrc.value = null;
        borderRadius.value = null;
        isLoading.value = false;
        fullSizeImage.value = null;
        originalDimensions.value = null;
        currentItemIndex.value = null;
        imageScale.value = 1;
        imageTranslateY.value = 0;
        direction.value = null;
        isNavigating.value = false;
        params.emitClose();
    }

    function restoreContainerStyles(): void {
        const tabContent = params.containerRef.value;
        if (!tabContent) {
            return;
        }
        if (overflow.value !== null) {
            tabContent.style.overflow = overflow.value;
        } else {
            tabContent.style.removeProperty('overflow');
        }
        if (overscroll.value !== null) {
            tabContent.style.overscrollBehavior = overscroll.value;
        } else {
            tabContent.style.removeProperty('overscroll-behavior');
        }
        overflow.value = null;
        overscroll.value = null;
    }

    function closeOverlay(): void {
        if (!rect.value) return;

        restoreContainerStyles();

        isClosing.value = true;
        isAnimating.value = true;
        scale.value = 0;

        const tabContent = params.containerRef.value;
        if (tabContent && rect.value) {
            const tabContentBox = tabContent.getBoundingClientRect();
            const containerWidth = tabContentBox.width;
            const containerHeight = tabContentBox.height;

            const centerLeft = Math.round((containerWidth - rect.value.width) / 2);
            const centerTop = Math.round((containerHeight - rect.value.height) / 2);

            rect.value = {
                ...rect.value,
                top: centerTop,
                left: centerLeft,
            };

            setTimeout(() => {
                resetOverlayState();
            }, 500);
        } else {
            setTimeout(() => {
                resetOverlayState();
            }, 500);
        }
    }

    return {
        closeOverlay,
        resetOverlayState,
        restoreContainerStyles,
    };
}


