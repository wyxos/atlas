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
    emitPreviewFailure: (item: FeedItem) => void;
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

    function isNavigationSuperseded(targetItemId: number): boolean {
        return currentTarget.value !== targetItemId;
    }

    function stopNavigationIfSuperseded(targetItemId: number): boolean {
        if (!isNavigationSuperseded(targetItemId)) {
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

    function findItemIndex(itemId: number): number {
        return params.items.value.findIndex((item) => item.id === itemId);
    }

    async function finishSlideIn(targetItemId: number): Promise<boolean> {
        if (stopNavigationIfSuperseded(targetItemId)) {
            return false;
        }

        imageTranslateY.value = 0;
        await wait(transitionDurationMs);

        if (stopNavigationIfSuperseded(targetItemId)) {
            return false;
        }

        stopNavigation();
        void params.handleItemSeen(targetItemId);
        return true;
    }

    async function revealImmediateMedia(
        target: FileViewerOverlayMediaTarget,
        container: HTMLElement,
        targetItemId: number,
    ): Promise<boolean> {
        if (stopNavigationIfSuperseded(targetItemId)) {
            return false;
        }

        originalDimensions.value = target.originalDimensions;

        if (target.isVideo) {
            videoSrc.value = target.fullSizeUrl;
        }

        if (target.isAudio || target.isFile) {
            fullSizeImage.value = target.previewSrc;
        }

        if (stopNavigationIfSuperseded(targetItemId)) {
            return false;
        }

        updateOverlayLayout(container, target.originalDimensions.width, target.originalDimensions.height);
        isLoading.value = false;

        await nextTick();
        await waitForLayoutPasses();

        return finishSlideIn(targetItemId);
    }

    async function revealImageMedia(
        target: FileViewerOverlayMediaTarget,
        container: HTMLElement,
        targetItemId: number,
    ): Promise<boolean> {
        const dimensions = await preloadImage(target.fullSizeUrl);

        if (stopNavigationIfSuperseded(targetItemId)) {
            return false;
        }

        originalDimensions.value = dimensions;
        fullSizeImage.value = target.fullSizeUrl;

        if (stopNavigationIfSuperseded(targetItemId)) {
            return false;
        }

        updateOverlayLayout(container, dimensions.width, dimensions.height);
        imageScale.value = 1;

        await nextTick();

        if (stopNavigationIfSuperseded(targetItemId)) {
            return false;
        }

        await waitForLayoutPasses();

        if (stopNavigationIfSuperseded(targetItemId)) {
            return false;
        }

        isLoading.value = false;
        await nextTick();
        await waitForLayoutPasses();
        await wait(10);

        return finishSlideIn(targetItemId);
    }

    async function revealImageFallback(
        item: FeedItem,
        target: FileViewerOverlayMediaTarget,
        container: HTMLElement,
        error: unknown,
    ): Promise<void> {
        console.warn('Failed to preload next image:', error);
        params.emitPreviewFailure(item);
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
                const nextItemId = params.items.value[currentItemIndex.value + 1]?.id ?? null;
                if (nextItemId !== null) {
                    await navigateToItem(nextItemId, 'down');
                }
            }
            return;
        }

        const nextItemId = params.items.value[currentItemIndex.value + 1]?.id ?? null;
        if (nextItemId !== null) {
            await navigateToItem(nextItemId, 'down');
        }
    }

    async function navigateToPrevious(): Promise<void> {
        if (!rect.value || !fillComplete.value || currentItemIndex.value === null) return;
        if (currentItemIndex.value <= 0) return;

        const previousItemId = params.items.value[currentItemIndex.value - 1]?.id ?? null;
        if (previousItemId !== null) {
            await navigateToItem(previousItemId, 'up');
        }
    }

    async function navigateToItem(itemId: number, dir?: 'up' | 'down'): Promise<void> {
        if (!rect.value || !fillComplete.value) return;

        const tabContent = params.containerRef.value;
        if (!tabContent) return;

        const initialIndex = findItemIndex(itemId);
        if (initialIndex === -1) {
            return;
        }

        const resolvedDirection = dir
            ?? (currentItemIndex.value !== null && initialIndex < currentItemIndex.value ? 'up' : 'down');

        direction.value = resolvedDirection;

        currentTarget.value = itemId;
        isNavigating.value = true;

        const { height: slideOutDistance } = getContainerSize(tabContent);
        imageTranslateY.value = resolvedDirection === 'down' ? -slideOutDistance : slideOutDistance;
        isAnimating.value = true;

        await wait(transitionDurationMs);

        const nextIndex = findItemIndex(itemId);
        if (nextIndex === -1) {
            stopNavigation();
            return;
        }

        const nextItem = params.items.value[nextIndex];
        if (!nextItem) {
            stopNavigation();
            return;
        }

        currentItemIndex.value = nextIndex;
        const target = resolveFileViewerOverlayMediaTarget(nextItem);
        applyPreparedMedia(target);
        updateOverlayLayout(tabContent, target.originalDimensions.width, target.originalDimensions.height);

        const { height: slideInDistance } = getContainerSize(tabContent);
        imageTranslateY.value = resolvedDirection === 'down' ? slideInDistance : -slideInDistance;
        imageScale.value = 1;
        await nextTick();

        const preloadTarget = itemId;

        try {
            if (target.isVideo || target.isAudio || target.isFile) {
                await revealImmediateMedia(target, tabContent, preloadTarget);
                return;
            }

            await revealImageMedia(target, tabContent, preloadTarget);
        } catch (error) {
            await revealImageFallback(nextItem, target, tabContent, error);
        }

        stopNavigation();
    }

    async function navigateToIndex(index: number, dir?: 'up' | 'down'): Promise<void> {
        if (index < 0 || index >= params.items.value.length) return;

        const itemId = params.items.value[index]?.id ?? null;
        if (itemId === null) {
            return;
        }

        await navigateToItem(itemId, dir);
    }

    return {
        navigateToNext,
        navigateToPrevious,
        navigateToIndex,
        navigateToItem,
    };
}
