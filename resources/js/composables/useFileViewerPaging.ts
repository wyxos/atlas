import { nextTick, type Ref } from 'vue';
import type { FeedItem } from '@/composables/useTabs';

export function useFileViewerPaging(params: {
    containerRef: Ref<HTMLElement | null>;
    items: Ref<FeedItem[]>;
    currentItemIndex: Ref<number | null>;
    currentNavigationTarget: Ref<number | null>;
    overlayRect: Ref<{ top: number; left: number; width: number; height: number } | null>;
    overlayFillComplete: Ref<boolean>;
    overlayIsAnimating: Ref<boolean>;
    overlayMediaType: Ref<'image' | 'video'>;
    overlayVideoSrc: Ref<string | null>;
    overlayIsLoading: Ref<boolean>;
    overlayFullSizeImage: Ref<string | null>;
    overlayImage: Ref<{ src: string; srcset?: string; sizes?: string; alt?: string } | null>;
    overlayImageSize: Ref<{ width: number; height: number } | null>;
    overlayKey: Ref<number>;
    originalImageDimensions: Ref<{ width: number; height: number } | null>;
    imageTranslateY: Ref<number>;
    imageScale: Ref<number>;
    imageCenterPosition: Ref<{ top: number; left: number } | null>;
    isNavigating: Ref<boolean>;
    navigationDirection: Ref<'up' | 'down' | null>;
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
    preloadImage: (url: string) => Promise<{ width: number; height: number }>;
    handleItemSeen: (fileId: number) => Promise<void>;
    ensureMoreItems: () => Promise<boolean>;
}) {
    async function navigateToNext(): Promise<void> {
        if (!params.overlayRect.value || !params.overlayFillComplete.value || params.currentItemIndex.value === null) return;
        if (params.currentItemIndex.value >= params.items.value.length - 1) {
            await params.ensureMoreItems();
            if (params.currentItemIndex.value < params.items.value.length - 1) {
                const nextIndex = params.currentItemIndex.value + 1;
                params.currentItemIndex.value = nextIndex;
                navigateToIndex(nextIndex, 'down');
            }
            return;
        }

        const nextIndex = params.currentItemIndex.value + 1;
        params.currentItemIndex.value = nextIndex;
        navigateToIndex(nextIndex, 'down');
    }

    async function navigateToPrevious(): Promise<void> {
        if (!params.overlayRect.value || !params.overlayFillComplete.value || params.currentItemIndex.value === null) return;
        if (params.currentItemIndex.value <= 0) return;

        const prevIndex = params.currentItemIndex.value - 1;
        params.currentItemIndex.value = prevIndex;
        navigateToIndex(prevIndex, 'up');
    }

    async function navigateToIndex(index: number, direction?: 'up' | 'down'): Promise<void> {
        if (!params.overlayRect.value || !params.overlayFillComplete.value) return;
        if (index < 0 || index >= params.items.value.length) return;

        const tabContent = params.containerRef.value;
        if (!tabContent) return;

        if (!direction && params.currentItemIndex.value !== null) {
            direction = index > params.currentItemIndex.value ? 'down' : 'up';
        }
        params.navigationDirection.value = direction || 'down';

        params.currentNavigationTarget.value = index;
        params.isNavigating.value = true;

        const slideOutDistance = tabContent.getBoundingClientRect().height;
        params.imageTranslateY.value = direction === 'down' ? -slideOutDistance : slideOutDistance;
        params.overlayIsAnimating.value = true;

        await new Promise(resolve => setTimeout(resolve, 500));

        const nextItem = params.items.value[index];
        if (!nextItem) {
            params.isNavigating.value = false;
            return;
        }

        const nextIsVideo = nextItem.type === 'video';
        const nextImageSrc = (nextItem.preview || nextItem.original) as string;
        const nextFullSizeUrl = nextItem.original || nextImageSrc;

        params.overlayImage.value = {
            src: nextImageSrc,
            srcset: undefined,
            sizes: undefined,
            alt: nextItem.id.toString(),
        };
        params.overlayMediaType.value = nextIsVideo ? 'video' : 'image';
        params.overlayVideoSrc.value = nextIsVideo ? nextFullSizeUrl : null;
        params.overlayIsLoading.value = !nextIsVideo;
        params.overlayFullSizeImage.value = null;
        params.overlayKey.value++;

        const tabContentBox = tabContent.getBoundingClientRect();
        const containerWidth = tabContentBox.width;
        const containerHeight = tabContentBox.height;
        const borderWidth = 4;
        const availableWidth = params.getAvailableWidth(containerWidth, borderWidth);
        const availableHeight = containerHeight - (borderWidth * 2);

        params.overlayImageSize.value = {
            width: availableWidth,
            height: availableHeight,
        };

        params.imageCenterPosition.value = params.getCenteredPosition(
            availableWidth,
            availableHeight,
            availableWidth,
            availableHeight
        );

        const slideInDistance = tabContent.getBoundingClientRect().height;
        params.imageTranslateY.value = direction === 'down' ? slideInDistance : -slideInDistance;
        params.imageScale.value = 1;
        await nextTick();

        const preloadTarget = index;
        try {
            if (nextIsVideo) {
                if (params.currentNavigationTarget.value !== preloadTarget) {
                    params.isNavigating.value = false;
                    params.overlayIsAnimating.value = false;
                    return;
                }

                params.originalImageDimensions.value = {
                    width: nextItem.width,
                    height: nextItem.height,
                };
                params.overlayVideoSrc.value = nextFullSizeUrl;

                if (nextItem?.id) {
                    await params.handleItemSeen(nextItem.id);
                }

                const tabContentBox = tabContent.getBoundingClientRect();
                const containerWidth = tabContentBox.width;
                const containerHeight = tabContentBox.height;
                const borderWidth = 4;
                const availableWidth = params.getAvailableWidth(containerWidth, borderWidth);
                const availableHeight = containerHeight - (borderWidth * 2);

                const bestFitSize = params.calculateBestFitSize(
                    nextItem.width,
                    nextItem.height,
                    availableWidth,
                    availableHeight
                );

                params.overlayImageSize.value = bestFitSize;
                params.imageCenterPosition.value = params.getCenteredPosition(
                    availableWidth,
                    availableHeight,
                    bestFitSize.width,
                    bestFitSize.height
                );

                params.overlayIsLoading.value = false;

                await nextTick();

                await new Promise(resolve => {
                    requestAnimationFrame(() => {
                        requestAnimationFrame(() => resolve(void 0));
                    });
                });

                if (params.currentNavigationTarget.value !== preloadTarget) {
                    params.isNavigating.value = false;
                    params.overlayIsAnimating.value = false;
                    return;
                }

                params.imageTranslateY.value = 0;
                await new Promise(resolve => setTimeout(resolve, 500));
                if (params.currentNavigationTarget.value !== preloadTarget) {
                    params.isNavigating.value = false;
                    params.overlayIsAnimating.value = false;
                    return;
                }

                params.isNavigating.value = false;
                params.overlayIsAnimating.value = false;
                return;
            }

            const imageDimensions = await params.preloadImage(nextFullSizeUrl);

            if (params.currentNavigationTarget.value !== preloadTarget) {
                params.isNavigating.value = false;
                params.overlayIsAnimating.value = false;
                return;
            }

            params.originalImageDimensions.value = imageDimensions;
            params.overlayFullSizeImage.value = nextFullSizeUrl;

            if (nextItem?.id) {
                await params.handleItemSeen(nextItem.id);
            }

            if (params.currentNavigationTarget.value !== preloadTarget) {
                params.isNavigating.value = false;
                params.overlayIsAnimating.value = false;
                return;
            }

            const tabContentBox = tabContent.getBoundingClientRect();
            const containerWidth = tabContentBox.width;
            const containerHeight = tabContentBox.height;
            const borderWidth = 4;

            const availableWidth = params.getAvailableWidth(containerWidth, borderWidth);
            const availableHeight = containerHeight - (borderWidth * 2);

            const bestFitSize = params.calculateBestFitSize(
                imageDimensions.width,
                imageDimensions.height,
                availableWidth,
                availableHeight
            );

            params.overlayImageSize.value = bestFitSize;
            params.imageCenterPosition.value = params.getCenteredPosition(
                availableWidth,
                availableHeight,
                bestFitSize.width,
                bestFitSize.height
            );

            params.imageScale.value = 1;

            await nextTick();

            if (params.currentNavigationTarget.value !== preloadTarget) {
                params.isNavigating.value = false;
                params.overlayIsAnimating.value = false;
                return;
            }

            await new Promise(resolve => {
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        resolve(void 0);
                    });
                });
            });

            if (params.currentNavigationTarget.value !== preloadTarget) {
                params.isNavigating.value = false;
                params.overlayIsAnimating.value = false;
                return;
            }

            params.overlayIsLoading.value = false;
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

            if (params.currentNavigationTarget.value !== preloadTarget) {
                params.isNavigating.value = false;
                params.overlayIsAnimating.value = false;
                return;
            }

            params.imageTranslateY.value = 0;
            await new Promise(resolve => setTimeout(resolve, 500));

            if (params.currentNavigationTarget.value !== preloadTarget) {
                params.isNavigating.value = false;
                params.overlayIsAnimating.value = false;
                return;
            }
        } catch (error) {
            console.warn('Failed to preload next image:', error);
            params.overlayFullSizeImage.value = nextImageSrc;
            params.overlayIsLoading.value = false;
            if (nextItem) {
                params.originalImageDimensions.value = {
                    width: nextItem.width,
                    height: nextItem.height,
                };
            }
            params.imageScale.value = 1;
            params.imageTranslateY.value = 0;
            await nextTick();
        }

        params.isNavigating.value = false;
        params.overlayIsAnimating.value = false;
    }

    return {
        navigateToNext,
        navigateToPrevious,
        navigateToIndex,
    };
}
