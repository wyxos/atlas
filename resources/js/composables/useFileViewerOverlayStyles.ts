import { computed, toRefs } from 'vue';

export function useFileViewerOverlayStyles(params: {
    overlay: {
        rect: { top: number; left: number; width: number; height: number } | null;
        isFilled: boolean;
        isClosing: boolean;
        isAnimating: boolean;
        borderRadius: string | null;
        scale: number;
        imageSize: { width: number; height: number } | null;
        centerPosition: { top: number; left: number } | null;
        fillComplete: boolean;
    };
    navigation: {
        imageScale: number;
        imageTranslateY: number;
        isNavigating: boolean;
    };
}) {
    const {
        rect,
        isFilled,
        isClosing,
        isAnimating,
        borderRadius,
        scale,
        imageSize,
        centerPosition,
    } = toRefs(params.overlay);
    const { imageScale, imageTranslateY, isNavigating } = toRefs(params.navigation);

    const overlayContainerClass = computed(() => [
        'absolute z-50 border-4 border-smart-blue-500 bg-prussian-blue-900 overflow-hidden',
        isFilled.value ? 'flex' : 'flex flex-col',
        isFilled.value && !isClosing.value ? '' : 'pointer-events-none',
        isAnimating.value || isClosing.value ? 'transition-all duration-500 ease-in-out' : '',
    ]);

    const overlayContainerStyle = computed(() => {
        const value = rect.value;
        if (!value) {
            return {};
        }
        return {
            top: value.top + 'px',
            left: value.left + 'px',
            width: value.width + 'px',
            height: value.height + 'px',
            borderRadius: isFilled.value ? undefined : (borderRadius.value || undefined),
            transform: `scale(${scale.value})`,
            transformOrigin: 'center center',
        };
    });

    const overlayContentClass = computed(() => [
        'relative overflow-hidden transition-all duration-500 ease-in-out',
        isFilled.value ? 'flex-1 min-h-0 min-w-0 flex flex-col' : 'flex-1 min-h-0',
    ]);

    const overlayMediaWrapperStyle = computed(() => ({
        height: isFilled.value ? undefined : '100%',
    }));

    const overlayMediaTransitionClass = computed(() => (
        (isAnimating.value || isClosing.value || isFilled.value || isNavigating.value) && centerPosition.value
            ? 'transition-all duration-500 ease-in-out'
            : ''
    ));

    const overlayMediaStyle = computed(() => {
        const scaleValue = `scale(${imageScale.value}) translateY(${imageTranslateY.value}px)`;
        if (imageSize.value && centerPosition.value) {
            return {
                width: imageSize.value.width + 'px',
                height: imageSize.value.height + 'px',
                top: centerPosition.value.top + 'px',
                left: centerPosition.value.left + 'px',
                transform: scaleValue,
                transformOrigin: 'center center',
            };
        }

        if (imageSize.value) {
            return {
                width: imageSize.value.width + 'px',
                height: imageSize.value.height + 'px',
                top: '50%',
                left: '50%',
                transform: `translate(-50%, -50%) ${scaleValue}`,
                transformOrigin: 'center center',
            };
        }

        return {
            top: '50%',
            left: '50%',
            transform: `translate(-50%, -50%) ${scaleValue}`,
            transformOrigin: 'center center',
        };
    });

    return {
        overlayContainerClass,
        overlayContainerStyle,
        overlayContentClass,
        overlayMediaWrapperStyle,
        overlayMediaTransitionClass,
        overlayMediaStyle,
    };
}
