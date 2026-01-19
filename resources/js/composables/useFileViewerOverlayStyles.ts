import { computed, type Ref } from 'vue';

export function useFileViewerOverlayStyles(params: {
    overlayRect: Ref<{ top: number; left: number; width: number; height: number } | null>;
    overlayIsFilled: Ref<boolean>;
    overlayIsClosing: Ref<boolean>;
    overlayIsAnimating: Ref<boolean>;
    overlayBorderRadius: Ref<string | null>;
    overlayScale: Ref<number>;
    overlayImageSize: Ref<{ width: number; height: number } | null>;
    imageCenterPosition: Ref<{ top: number; left: number } | null>;
    imageScale: Ref<number>;
    imageTranslateY: Ref<number>;
    overlayFillComplete: Ref<boolean>;
    isNavigating: Ref<boolean>;
}) {
    const overlayContainerClass = computed(() => [
        'absolute z-50 border-4 border-smart-blue-500 bg-prussian-blue-900 overflow-hidden',
        params.overlayIsFilled.value ? 'flex' : 'flex flex-col',
        params.overlayIsFilled.value && !params.overlayIsClosing.value ? '' : 'pointer-events-none',
        params.overlayIsAnimating.value || params.overlayIsClosing.value ? 'transition-all duration-500 ease-in-out' : '',
    ]);

    const overlayContainerStyle = computed(() => {
        const rect = params.overlayRect.value;
        if (!rect) {
            return {};
        }
        return {
            top: rect.top + 'px',
            left: rect.left + 'px',
            width: rect.width + 'px',
            height: rect.height + 'px',
            borderRadius: params.overlayIsFilled.value ? undefined : (params.overlayBorderRadius.value || undefined),
            transform: `scale(${params.overlayScale.value})`,
            transformOrigin: 'center center',
        };
    });

    const overlayContentClass = computed(() => [
        'relative overflow-hidden transition-all duration-500 ease-in-out',
        params.overlayIsFilled.value ? 'flex-1 min-h-0 min-w-0 flex flex-col' : 'flex-1 min-h-0',
    ]);

    const overlayMediaWrapperStyle = computed(() => ({
        height: params.overlayIsFilled.value ? undefined : '100%',
    }));

    const overlayMediaTransitionClass = computed(() => (
        (params.overlayIsAnimating.value || params.overlayIsClosing.value || params.overlayIsFilled.value || params.isNavigating.value) && params.imageCenterPosition.value
            ? 'transition-all duration-500 ease-in-out'
            : ''
    ));

    const overlayMediaStyle = computed(() => {
        const scale = `scale(${params.imageScale.value}) translateY(${params.imageTranslateY.value}px)`;
        if (params.overlayImageSize.value && params.imageCenterPosition.value) {
            return {
                width: params.overlayImageSize.value.width + 'px',
                height: params.overlayImageSize.value.height + 'px',
                top: params.imageCenterPosition.value.top + 'px',
                left: params.imageCenterPosition.value.left + 'px',
                transform: scale,
                transformOrigin: 'center center',
            };
        }

        if (params.overlayImageSize.value) {
            return {
                width: params.overlayImageSize.value.width + 'px',
                height: params.overlayImageSize.value.height + 'px',
                top: '50%',
                left: '50%',
                transform: `translate(-50%, -50%) ${scale}`,
                transformOrigin: 'center center',
            };
        }

        return {
            top: '50%',
            left: '50%',
            transform: `translate(-50%, -50%) ${scale}`,
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
