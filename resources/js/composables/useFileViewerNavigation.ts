import { nextTick, type Ref } from 'vue';
import { preloadImage, calculateBestFitSize, getAvailableWidth } from './useFileViewerUtils';

const BORDER_WIDTH = 4;
const PANEL_HEIGHT = 200;

export function useFileViewerNavigation(
    overlay: {
        overlayRect: Ref<{ top: number; left: number; width: number; height: number } | null>;
        overlayFillComplete: Ref<boolean>;
        overlayIsFilled: Ref<boolean>;
        overlayIsClosing: Ref<boolean>;
        overlayIsAnimating: Ref<boolean>;
        overlayIsLoading: Ref<boolean>;
        overlayImage: Ref<{ src: string; srcset?: string; sizes?: string; alt?: string } | null>;
        overlayFullSizeImage: Ref<string | null>;
        overlayKey: Ref<number>;
        originalImageDimensions: Ref<{ width: number; height: number } | null>;
        imageScale: Ref<number>;
        imageTranslateX: Ref<number>;
        isNavigating: Ref<boolean>;
        navigationDirection: Ref<'left' | 'right' | null>;
        currentNavigationTarget: Ref<number | null>;
    },
    imageSize: {
        overlayImageSize: Ref<{ width: number; height: number } | null>;
        imageCenterPosition: Ref<{ top: number; left: number } | null>;
    },
    items: Ref<Array<{ id: number; src?: string; thumbnail?: string; originalUrl?: string; width?: number; height?: number }>>,
    currentItemIndex: Ref<number | null>,
    containerRef: () => HTMLElement | null,
    isBottomPanelOpen: Ref<boolean>,
    isSheetOpen: Ref<boolean>,
    handleItemSeen: (fileId: number) => Promise<void>
) {
    async function navigateToNext(): Promise<void> {
        if (!overlay.overlayRect.value || !overlay.overlayFillComplete.value || currentItemIndex.value === null) return;
        if (currentItemIndex.value >= items.value.length - 1) return;

        const nextIndex = currentItemIndex.value + 1;
        currentItemIndex.value = nextIndex;
        await navigateToIndex(nextIndex, 'right');
    }

    async function navigateToPrevious(): Promise<void> {
        if (!overlay.overlayRect.value || !overlay.overlayFillComplete.value || currentItemIndex.value === null) return;
        if (currentItemIndex.value <= 0) return;

        const prevIndex = currentItemIndex.value - 1;
        currentItemIndex.value = prevIndex;
        await navigateToIndex(prevIndex, 'left');
    }

    async function navigateToIndex(index: number, direction?: 'left' | 'right'): Promise<void> {
        if (!overlay.overlayRect.value || !overlay.overlayFillComplete.value) return;
        if (index < 0 || index >= items.value.length) return;

        const tabContent = containerRef();
        if (!tabContent) return;

        if (!direction && currentItemIndex.value !== null) {
            direction = index > currentItemIndex.value ? 'right' : 'left';
        }
        overlay.navigationDirection.value = direction || 'right';
        overlay.currentNavigationTarget.value = index;
        overlay.isNavigating.value = true;

        const slideOutDistance = tabContent.getBoundingClientRect().width;
        overlay.imageTranslateX.value = direction === 'right' ? -slideOutDistance : slideOutDistance;
        overlay.overlayIsAnimating.value = true;

        await new Promise(resolve => setTimeout(resolve, 500));

        const nextItem = items.value[index];
        if (!nextItem) {
            overlay.isNavigating.value = false;
            return;
        }

        const nextImageSrc = nextItem.src || nextItem.thumbnail || '';
        const nextFullSizeUrl = nextItem.originalUrl || nextImageSrc;

        overlay.overlayImage.value = {
            src: nextImageSrc,
            srcset: undefined,
            sizes: undefined,
            alt: nextItem.id.toString(),
        };
        overlay.overlayIsLoading.value = true;
        overlay.overlayFullSizeImage.value = null;
        overlay.overlayKey.value++;

        const tabContentBox = tabContent.getBoundingClientRect();
        const containerWidth = tabContentBox.width;
        const containerHeight = tabContentBox.height;
        const availableWidth = getAvailableWidth(containerWidth, BORDER_WIDTH, overlay.overlayIsFilled.value, overlay.overlayFillComplete.value, overlay.overlayIsClosing.value, isSheetOpen.value);
        const availableHeight = containerHeight - (BORDER_WIDTH * 2);

        imageSize.overlayImageSize.value = {
            width: availableWidth,
            height: availableHeight,
        };

        const previewImageLeft = Math.floor((availableWidth - availableWidth) / 2) + BORDER_WIDTH;
        const previewImageTop = Math.floor((availableHeight - availableHeight) / 2) + BORDER_WIDTH;

        imageSize.imageCenterPosition.value = {
            top: previewImageTop,
            left: previewImageLeft,
        };

        const slideInDistance = tabContent.getBoundingClientRect().width;
        overlay.imageTranslateX.value = direction === 'right' ? slideInDistance : -slideInDistance;
        overlay.imageScale.value = 1;
        await nextTick();

        const preloadTarget = index;
        try {
            const imageDimensions = await preloadImage(nextFullSizeUrl);

            if (overlay.currentNavigationTarget.value !== preloadTarget) {
                overlay.isNavigating.value = false;
                overlay.overlayIsAnimating.value = false;
                return;
            }

            overlay.originalImageDimensions.value = imageDimensions;
            overlay.overlayFullSizeImage.value = nextFullSizeUrl;

            if (nextItem?.id) {
                await handleItemSeen(nextItem.id);
            }

            if (overlay.currentNavigationTarget.value !== preloadTarget) {
                overlay.isNavigating.value = false;
                overlay.overlayIsAnimating.value = false;
                return;
            }

            const tabContentBox2 = tabContent.getBoundingClientRect();
            const containerWidth2 = tabContentBox2.width;
            const containerHeight2 = tabContentBox2.height;
            const panelHeight = isBottomPanelOpen.value ? PANEL_HEIGHT : 0;
            const availableWidth2 = getAvailableWidth(containerWidth2, BORDER_WIDTH, overlay.overlayIsFilled.value, overlay.overlayFillComplete.value, overlay.overlayIsClosing.value, isSheetOpen.value);
            const availableHeight2 = containerHeight2 - (BORDER_WIDTH * 2) - panelHeight;

            const bestFitSize = calculateBestFitSize(
                imageDimensions.width,
                imageDimensions.height,
                availableWidth2,
                availableHeight2
            );

            imageSize.overlayImageSize.value = bestFitSize;

            const fullImageLeft = Math.round((availableWidth2 - bestFitSize.width) / 2);
            const fullImageTop = Math.round((availableHeight2 - bestFitSize.height) / 2);

            imageSize.imageCenterPosition.value = {
                top: fullImageTop,
                left: fullImageLeft,
            };

            overlay.imageScale.value = 1;
            await nextTick();

            if (overlay.currentNavigationTarget.value !== preloadTarget) {
                overlay.isNavigating.value = false;
                overlay.overlayIsAnimating.value = false;
                return;
            }

            await new Promise(resolve => {
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        resolve(void 0);
                    });
                });
            });

            if (overlay.currentNavigationTarget.value !== preloadTarget) {
                overlay.isNavigating.value = false;
                overlay.overlayIsAnimating.value = false;
                return;
            }

            overlay.overlayIsLoading.value = false;
            await nextTick();

            await new Promise(resolve => {
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        setTimeout(() => {
                            resolve(void 0);
                        }, 10);
                    });
                });
            });

            if (overlay.currentNavigationTarget.value !== preloadTarget) {
                overlay.isNavigating.value = false;
                overlay.overlayIsAnimating.value = false;
                return;
            }

            overlay.imageTranslateX.value = 0;
            await new Promise(resolve => setTimeout(resolve, 500));

            if (overlay.currentNavigationTarget.value !== preloadTarget) {
                overlay.isNavigating.value = false;
                overlay.overlayIsAnimating.value = false;
                return;
            }
        } catch (error) {
            console.warn('Failed to preload next image:', error);
            overlay.overlayFullSizeImage.value = nextImageSrc;
            overlay.overlayIsLoading.value = false;
            if (nextItem) {
                overlay.originalImageDimensions.value = {
                    width: nextItem.width || 0,
                    height: nextItem.height || 0,
                };
            }
            overlay.imageScale.value = 1;
            overlay.imageTranslateX.value = 0;
            await nextTick();
        }

        overlay.isNavigating.value = false;
        overlay.overlayIsAnimating.value = false;
    }

    return {
        navigateToNext,
        navigateToPrevious,
        navigateToIndex,
    };
}





