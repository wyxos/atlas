import { nextTick, toRefs, type Ref } from 'vue';
import type { FeedItem } from '@/composables/useTabs';

export function useFileViewerPaging(params: {
    containerRef: Ref<HTMLElement | null>;
    items: Ref<FeedItem[]>;
    overlay: {
        rect: { top: number; left: number; width: number; height: number } | null;
        fillComplete: boolean;
        isAnimating: boolean;
        mediaType: 'image' | 'video';
        videoSrc: string | null;
        isLoading: boolean;
        fullSizeImage: string | null;
        image: { src: string; srcset?: string; sizes?: string; alt?: string } | null;
        imageSize: { width: number; height: number } | null;
        key: number;
        originalDimensions: { width: number; height: number } | null;
        centerPosition: { top: number; left: number } | null;
    };
    navigation: {
        currentItemIndex: number | null;
        currentTarget: number | null;
        imageTranslateY: number;
        imageScale: number;
        isNavigating: boolean;
        direction: 'up' | 'down' | null;
    };
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
    const {
        rect,
        fillComplete,
        isAnimating,
        mediaType,
        videoSrc,
        isLoading,
        fullSizeImage,
        image,
        imageSize,
        key,
        originalDimensions,
        centerPosition,
    } = toRefs(params.overlay);
    const {
        currentItemIndex,
        currentTarget,
        imageTranslateY,
        imageScale,
        isNavigating,
        direction,
    } = toRefs(params.navigation);

    async function navigateToNext(): Promise<void> {
        if (!rect.value || !fillComplete.value || currentItemIndex.value === null) return;
        if (currentItemIndex.value >= params.items.value.length - 1) {
            await params.ensureMoreItems();
            if (currentItemIndex.value < params.items.value.length - 1) {
                const nextIndex = currentItemIndex.value + 1;
                currentItemIndex.value = nextIndex;
                navigateToIndex(nextIndex, 'down');
            }
            return;
        }

        const nextIndex = currentItemIndex.value + 1;
        currentItemIndex.value = nextIndex;
        navigateToIndex(nextIndex, 'down');
    }

    async function navigateToPrevious(): Promise<void> {
        if (!rect.value || !fillComplete.value || currentItemIndex.value === null) return;
        if (currentItemIndex.value <= 0) return;

        const prevIndex = currentItemIndex.value - 1;
        currentItemIndex.value = prevIndex;
        navigateToIndex(prevIndex, 'up');
    }

    async function navigateToIndex(index: number, dir?: 'up' | 'down'): Promise<void> {
        if (!rect.value || !fillComplete.value) return;
        if (index < 0 || index >= params.items.value.length) return;

        const tabContent = params.containerRef.value;
        if (!tabContent) return;

        if (!dir && currentItemIndex.value !== null) {
            dir = index > currentItemIndex.value ? 'down' : 'up';
        }
        direction.value = dir || 'down';

        currentTarget.value = index;
        isNavigating.value = true;

        const slideOutDistance = tabContent.getBoundingClientRect().height;
        imageTranslateY.value = dir === 'down' ? -slideOutDistance : slideOutDistance;
        isAnimating.value = true;

        await new Promise(resolve => setTimeout(resolve, 500));

        const nextItem = params.items.value[index];
        if (!nextItem) {
            isNavigating.value = false;
            return;
        }

        const nextIsVideo = nextItem.type === 'video';
        const nextImageSrc = (nextItem.preview || nextItem.original) as string;
        const nextFullSizeUrl = nextItem.original || nextImageSrc;

        image.value = {
            src: nextImageSrc,
            srcset: undefined,
            sizes: undefined,
            alt: nextItem.id.toString(),
        };
        mediaType.value = nextIsVideo ? 'video' : 'image';
        videoSrc.value = nextIsVideo ? nextFullSizeUrl : null;
        isLoading.value = !nextIsVideo;
        fullSizeImage.value = null;
        key.value++;

        const tabContentBox = tabContent.getBoundingClientRect();
        const containerWidth = tabContentBox.width;
        const containerHeight = tabContentBox.height;
        const borderWidth = 4;
        const availableWidth = params.getAvailableWidth(containerWidth, borderWidth);
        const availableHeight = containerHeight - (borderWidth * 2);

        const previewDimensions = {
            width: nextItem.width,
            height: nextItem.height,
        };
        const initialBestFit = params.calculateBestFitSize(
            previewDimensions.width,
            previewDimensions.height,
            availableWidth,
            availableHeight
        );

        imageSize.value = initialBestFit;
        centerPosition.value = params.getCenteredPosition(
            availableWidth,
            availableHeight,
            initialBestFit.width,
            initialBestFit.height
        );

        const slideInDistance = tabContent.getBoundingClientRect().height;
        imageTranslateY.value = dir === 'down' ? slideInDistance : -slideInDistance;
        imageScale.value = 1;
        await nextTick();

        const preloadTarget = index;
        try {
            if (nextIsVideo) {
                if (currentTarget.value !== preloadTarget) {
                    isNavigating.value = false;
                    isAnimating.value = false;
                    return;
                }

                originalDimensions.value = {
                    width: nextItem.width,
                    height: nextItem.height,
                };
                videoSrc.value = nextFullSizeUrl;

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

                imageSize.value = bestFitSize;
                centerPosition.value = params.getCenteredPosition(
                    availableWidth,
                    availableHeight,
                    bestFitSize.width,
                    bestFitSize.height
                );

                isLoading.value = false;

                await nextTick();

                await new Promise(resolve => {
                    requestAnimationFrame(() => {
                        requestAnimationFrame(() => resolve(void 0));
                    });
                });

                if (currentTarget.value !== preloadTarget) {
                    isNavigating.value = false;
                    isAnimating.value = false;
                    return;
                }

                imageTranslateY.value = 0;
                await new Promise(resolve => setTimeout(resolve, 500));
                if (currentTarget.value !== preloadTarget) {
                    isNavigating.value = false;
                    isAnimating.value = false;
                    return;
                }

                isNavigating.value = false;
                isAnimating.value = false;
                return;
            }

            const imageDimensions = await params.preloadImage(nextFullSizeUrl);

            if (currentTarget.value !== preloadTarget) {
                isNavigating.value = false;
                isAnimating.value = false;
                return;
            }

            originalDimensions.value = imageDimensions;
            fullSizeImage.value = nextFullSizeUrl;

            if (nextItem?.id) {
                await params.handleItemSeen(nextItem.id);
            }

            if (currentTarget.value !== preloadTarget) {
                isNavigating.value = false;
                isAnimating.value = false;
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

            imageSize.value = bestFitSize;
            centerPosition.value = params.getCenteredPosition(
                availableWidth,
                availableHeight,
                bestFitSize.width,
                bestFitSize.height
            );

            imageScale.value = 1;

            await nextTick();

            if (currentTarget.value !== preloadTarget) {
                isNavigating.value = false;
                isAnimating.value = false;
                return;
            }

            await new Promise(resolve => {
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        resolve(void 0);
                    });
                });
            });

            if (currentTarget.value !== preloadTarget) {
                isNavigating.value = false;
                isAnimating.value = false;
                return;
            }

            isLoading.value = false;
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

            if (currentTarget.value !== preloadTarget) {
                isNavigating.value = false;
                isAnimating.value = false;
                return;
            }

            imageTranslateY.value = 0;
            await new Promise(resolve => setTimeout(resolve, 500));

            if (currentTarget.value !== preloadTarget) {
                isNavigating.value = false;
                isAnimating.value = false;
                return;
            }
        } catch (error) {
            console.warn('Failed to preload next image:', error);
            fullSizeImage.value = nextImageSrc;
            isLoading.value = false;

            try {
                const fallbackDimensions = await params.preloadImage(nextImageSrc);
                originalDimensions.value = fallbackDimensions;

                const tabContentBox = tabContent.getBoundingClientRect();
                const containerWidth = tabContentBox.width;
                const containerHeight = tabContentBox.height;
                const borderWidth = 4;
                const availableWidth = params.getAvailableWidth(containerWidth, borderWidth);
                const availableHeight = containerHeight - (borderWidth * 2);

                const bestFitSize = params.calculateBestFitSize(
                    fallbackDimensions.width,
                    fallbackDimensions.height,
                    availableWidth,
                    availableHeight
                );

                imageSize.value = bestFitSize;
                centerPosition.value = params.getCenteredPosition(
                    availableWidth,
                    availableHeight,
                    bestFitSize.width,
                    bestFitSize.height
                );
            } catch {
                if (nextItem) {
                    originalDimensions.value = {
                        width: nextItem.width,
                        height: nextItem.height,
                    };
                }
            }

            imageScale.value = 1;
            imageTranslateY.value = 0;
            await nextTick();
        }

        isNavigating.value = false;
        isAnimating.value = false;
    }

    return {
        navigateToNext,
        navigateToPrevious,
        navigateToIndex,
    };
}
