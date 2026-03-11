import { nextTick, toRefs, type Ref } from 'vue';
import type { FeedItem } from '@/composables/useTabs';
import {
    preloadImage,
    type FileViewerOverlayMediaType,
} from '@/utils/fileViewer';
import {
    calculateFileViewerOverlayLayout,
    resolveFileViewerOverlayMediaTarget,
    type FileViewerOverlayMediaTarget,
} from '@/utils/fileViewerOverlay';

export function useFileViewerPaging(params: {
    containerRef: Ref<HTMLElement | null>;
    items: Ref<FeedItem[]>;
    overlay: {
        rect: { top: number; left: number; width: number; height: number } | null;
        fillComplete: boolean;
        isAnimating: boolean;
        mediaType: FileViewerOverlayMediaType;
        videoSrc: string | null;
        audioSrc: string | null;
        isLoading: boolean;
        fullSizeImage: string | null;
        image: { src: string; srcset?: string; sizes?: string; alt?: string } | null;
        imageSize: { width: number; height: number } | null;
        key: number;
        originalDimensions: { width: number; height: number } | null;
        centerPosition: { top: number; left: number } | null;
        isFilled: boolean;
        isClosing: boolean;
    };
    sheet: {
        isOpen: boolean;
    };
    navigation: {
        currentItemIndex: number | null;
        currentTarget: number | null;
        imageTranslateY: number;
        imageScale: number;
        isNavigating: boolean;
        direction: 'up' | 'down' | null;
    };
    handleItemSeen: (fileId: number) => Promise<void>;
    ensureMoreItems: () => Promise<boolean>;
}) {
    const {
        rect,
        fillComplete,
        isAnimating,
        mediaType,
        videoSrc,
        audioSrc,
        isLoading,
        fullSizeImage,
        image,
        imageSize,
        key,
        originalDimensions,
        centerPosition,
        isFilled,
        isClosing,
    } = toRefs(params.overlay);
    const { isOpen } = toRefs(params.sheet);
    const {
        currentItemIndex,
        currentTarget,
        imageTranslateY,
        imageScale,
        isNavigating,
        direction,
    } = toRefs(params.navigation);
    const transitionDurationMs = 500;
    const borderWidth = 4;

    function wait(ms: number): Promise<void> {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    async function waitForLayoutPasses(): Promise<void> {
        await wait(0);
        await wait(0);
    }

    function stopNavigation(): void {
        isNavigating.value = false;
        isAnimating.value = false;
    }

    function isNavigationSuperseded(targetIndex: number): boolean {
        return currentTarget.value !== targetIndex;
    }

    function stopNavigationIfSuperseded(targetIndex: number): boolean {
        if (!isNavigationSuperseded(targetIndex)) {
            return false;
        }

        stopNavigation();
        return true;
    }

    function getContainerSize(container: HTMLElement): { width: number; height: number } {
        const box = container.getBoundingClientRect();

        return {
            width: box.width,
            height: box.height,
        };
    }

    function updateOverlayLayout(container: HTMLElement, width: number, height: number): void {
        const { width: containerWidth, height: containerHeight } = getContainerSize(container);
        const layout = calculateFileViewerOverlayLayout({
            containerWidth,
            containerHeight,
            borderWidth,
            mediaWidth: width,
            mediaHeight: height,
            isFilled: isFilled.value,
            fillComplete: fillComplete.value,
            isClosing: isClosing.value,
            isSheetOpen: isOpen.value,
        });

        imageSize.value = layout.imageSize;
        centerPosition.value = layout.centerPosition;
    }

    function applyPreparedMedia(target: FileViewerOverlayMediaTarget): void {
        image.value = target.overlayImage;
        mediaType.value = target.mediaType;
        videoSrc.value = target.isVideo ? target.fullSizeUrl : null;
        audioSrc.value = target.isAudio ? target.fullSizeUrl : null;
        isLoading.value = target.isLoading;
        fullSizeImage.value = target.initialFullSizeImage;
        key.value += 1;
    }

    async function markItemSeen(item: FeedItem): Promise<void> {
        await params.handleItemSeen(item.id);
    }

    async function finishSlideIn(targetIndex: number): Promise<boolean> {
        if (stopNavigationIfSuperseded(targetIndex)) {
            return false;
        }

        imageTranslateY.value = 0;
        await wait(transitionDurationMs);

        if (stopNavigationIfSuperseded(targetIndex)) {
            return false;
        }

        stopNavigation();
        return true;
    }

    async function revealImmediateMedia(
        item: FeedItem,
        target: FileViewerOverlayMediaTarget,
        container: HTMLElement,
        targetIndex: number,
    ): Promise<boolean> {
        if (stopNavigationIfSuperseded(targetIndex)) {
            return false;
        }

        originalDimensions.value = target.originalDimensions;

        if (target.isVideo) {
            videoSrc.value = target.fullSizeUrl;
        }

        if (target.isAudio || target.isFile) {
            fullSizeImage.value = target.previewSrc;
        }

        await markItemSeen(item);

        if (stopNavigationIfSuperseded(targetIndex)) {
            return false;
        }

        updateOverlayLayout(container, target.originalDimensions.width, target.originalDimensions.height);
        isLoading.value = false;

        await nextTick();
        await waitForLayoutPasses();

        return finishSlideIn(targetIndex);
    }

    async function revealImageMedia(
        item: FeedItem,
        target: FileViewerOverlayMediaTarget,
        container: HTMLElement,
        targetIndex: number,
    ): Promise<boolean> {
        const dimensions = await preloadImage(target.fullSizeUrl);

        if (stopNavigationIfSuperseded(targetIndex)) {
            return false;
        }

        originalDimensions.value = dimensions;
        fullSizeImage.value = target.fullSizeUrl;

        await markItemSeen(item);

        if (stopNavigationIfSuperseded(targetIndex)) {
            return false;
        }

        updateOverlayLayout(container, dimensions.width, dimensions.height);
        imageScale.value = 1;

        await nextTick();

        if (stopNavigationIfSuperseded(targetIndex)) {
            return false;
        }

        await waitForLayoutPasses();

        if (stopNavigationIfSuperseded(targetIndex)) {
            return false;
        }

        isLoading.value = false;
        await nextTick();
        await waitForLayoutPasses();
        await wait(10);

        return finishSlideIn(targetIndex);
    }

    async function revealImageFallback(
        item: FeedItem,
        target: FileViewerOverlayMediaTarget,
        container: HTMLElement,
        error: unknown,
    ): Promise<void> {
        console.warn('Failed to preload next image:', error);
        fullSizeImage.value = target.previewSrc;
        isLoading.value = false;

        try {
            const fallbackDimensions = await preloadImage(target.previewSrc);
            originalDimensions.value = fallbackDimensions;
            updateOverlayLayout(container, fallbackDimensions.width, fallbackDimensions.height);
        } catch {
            originalDimensions.value = {
                width: item.width,
                height: item.height,
            };
        }

        imageScale.value = 1;
        imageTranslateY.value = 0;
        await nextTick();
    }

    async function navigateToNext(): Promise<void> {
        if (!rect.value || !fillComplete.value || currentItemIndex.value === null) return;
        if (currentItemIndex.value >= params.items.value.length - 1) {
            await params.ensureMoreItems();
            if (currentItemIndex.value < params.items.value.length - 1) {
                const nextIndex = currentItemIndex.value + 1;
                currentItemIndex.value = nextIndex;
                await navigateToIndex(nextIndex, 'down');
            }
            return;
        }

        const nextIndex = currentItemIndex.value + 1;
        currentItemIndex.value = nextIndex;
        await navigateToIndex(nextIndex, 'down');
    }

    async function navigateToPrevious(): Promise<void> {
        if (!rect.value || !fillComplete.value || currentItemIndex.value === null) return;
        if (currentItemIndex.value <= 0) return;

        const prevIndex = currentItemIndex.value - 1;
        currentItemIndex.value = prevIndex;
        await navigateToIndex(prevIndex, 'up');
    }

    async function navigateToIndex(index: number, dir?: 'up' | 'down'): Promise<void> {
        if (!rect.value || !fillComplete.value) return;
        if (index < 0 || index >= params.items.value.length) return;

        const tabContent = params.containerRef.value;
        if (!tabContent) return;

        const resolvedDirection = dir
            ?? (currentItemIndex.value !== null && index < currentItemIndex.value ? 'up' : 'down');

        direction.value = resolvedDirection;

        currentTarget.value = index;
        isNavigating.value = true;

        const { height: slideOutDistance } = getContainerSize(tabContent);
        imageTranslateY.value = resolvedDirection === 'down' ? -slideOutDistance : slideOutDistance;
        isAnimating.value = true;

        await wait(transitionDurationMs);

        const nextItem = params.items.value[index];
        if (!nextItem) {
            stopNavigation();
            return;
        }

        const target = resolveFileViewerOverlayMediaTarget(nextItem);
        applyPreparedMedia(target);
        updateOverlayLayout(tabContent, target.originalDimensions.width, target.originalDimensions.height);

        const { height: slideInDistance } = getContainerSize(tabContent);
        imageTranslateY.value = resolvedDirection === 'down' ? slideInDistance : -slideInDistance;
        imageScale.value = 1;
        await nextTick();

        const preloadTarget = index;

        try {
            if (target.isVideo || target.isAudio || target.isFile) {
                await revealImmediateMedia(nextItem, target, tabContent, preloadTarget);
                return;
            }

            await revealImageMedia(nextItem, target, tabContent, preloadTarget);
        } catch (error) {
            await revealImageFallback(nextItem, target, tabContent, error);
        }

        stopNavigation();
    }

    return {
        navigateToNext,
        navigateToPrevious,
        navigateToIndex,
    };
}
