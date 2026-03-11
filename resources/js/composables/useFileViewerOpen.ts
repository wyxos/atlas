import { nextTick, toRefs, type Ref } from 'vue';
import type { FeedItem } from '@/composables/useTabs';
import {
    preloadImage,
    type FileViewerOverlayMediaType,
} from '@/utils/fileViewer';
import {
    calculateFileViewerOverlayLayout,
    calculateFileViewerPreviewLayout,
    resolveFileViewerOverlayMediaTarget,
} from '@/utils/fileViewerOverlay';

export function useFileViewerOpen(params: {
    containerRef: Ref<HTMLElement | null>;
    masonryContainerRef: Ref<HTMLElement | null>;
    items: Ref<FeedItem[]>;
    container: {
        overflow: string | null;
        overscroll: string | null;
    };
    overlay: {
        rect: { top: number; left: number; width: number; height: number } | null;
        image: { src: string; srcset?: string; sizes?: string; alt?: string } | null;
        mediaType: FileViewerOverlayMediaType;
        videoSrc: string | null;
        audioSrc: string | null;
        borderRadius: string | null;
        key: number;
        isAnimating: boolean;
        imageSize: { width: number; height: number } | null;
        isFilled: boolean;
        fillComplete: boolean;
        isClosing: boolean;
        scale: number;
        isLoading: boolean;
        fullSizeImage: string | null;
        originalDimensions: { width: number; height: number } | null;
        centerPosition: { top: number; left: number } | null;
    };
    navigation: {
        currentItemIndex: number | null;
        imageScale: number;
    };
    sheet: {
        isOpen: boolean;
    };
    handleItemSeen: (fileId: number) => Promise<void>;
    closeOverlay: () => void;
    emitOpen: () => void;
}) {
    const { overflow, overscroll } = toRefs(params.container);
    const {
        rect,
        image,
        mediaType,
        videoSrc,
        audioSrc,
        borderRadius,
        key,
        isAnimating,
        imageSize,
        isFilled,
        fillComplete,
        isClosing,
        scale,
        isLoading,
        fullSizeImage,
        originalDimensions,
        centerPosition,
    } = toRefs(params.overlay);
    const { currentItemIndex, imageScale } = toRefs(params.navigation);
    const { isOpen } = toRefs(params.sheet);
    const borderWidth = 4;
    const transitionDurationMs = 500;

    function wait(ms: number): Promise<void> {
        return new Promise(resolve => setTimeout(resolve, ms));
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

    function getClickedItemId(target: HTMLElement): number | null {
        const el = target.closest('[data-file-id]') as HTMLElement | null;
        if (!el) {
            return null;
        }

        const raw = el.getAttribute('data-file-id');
        if (!raw) {
            return null;
        }

        const id = Number(raw);
        return Number.isFinite(id) ? id : null;
    }

    async function openFromClick(e: MouseEvent): Promise<void> {
        const container = params.masonryContainerRef.value;
        const tabContent = params.containerRef.value;
        if (!container || !tabContent) return;

        if (overflow.value === null) {
            overflow.value = tabContent.style.overflow || '';
            overscroll.value = tabContent.style.overscrollBehavior || '';
            tabContent.style.overflow = 'hidden';
            tabContent.style.overscrollBehavior = 'contain';
        }

        const clickTarget = e.target as HTMLElement | null;
        if (!clickTarget) return;

        const itemEl = clickTarget.closest('[data-testid="item-card"]') as HTMLElement | null;

        if (!itemEl || !container.contains(itemEl)) {
            params.closeOverlay();
            return;
        }

        const clickedItemId = getClickedItemId(clickTarget);
        if (clickedItemId === null) {
            params.closeOverlay();
            return;
        }

        const masonryItem = params.items.value.find((i) => i.id === clickedItemId) ?? null;
        if (!masonryItem) {
            params.closeOverlay();
            return;
        }

        const itemBox = itemEl.getBoundingClientRect();
        const tabContentBox = tabContent.getBoundingClientRect();

        const top = itemBox.top - tabContentBox.top - borderWidth;
        const left = itemBox.left - tabContentBox.left - borderWidth;
        const width = itemBox.width + (borderWidth * 2);
        const height = itemBox.height + (borderWidth * 2);

        const imgEl = itemEl.querySelector('img') as HTMLImageElement | null;

        const src = (imgEl?.currentSrc
            || imgEl?.getAttribute('src')
            || masonryItem.preview
            || masonryItem.original) as string;

        const srcset = imgEl?.getAttribute('srcset') || undefined;
        const sizes = imgEl?.getAttribute('sizes') || undefined;
        const alt = imgEl?.getAttribute('alt') || String(masonryItem.id);

        const computedStyle = window.getComputedStyle(itemEl);
        const radius = computedStyle.borderRadius || '';
        const target = resolveFileViewerOverlayMediaTarget(masonryItem, {
            previewSrc: src,
            srcset,
            sizes,
            alt,
        });
        const previewLayout = calculateFileViewerPreviewLayout(width, height, borderWidth);
        currentItemIndex.value = params.items.value.findIndex((it) => it.id === masonryItem.id);

        key.value++;
        imageScale.value = 1;

        imageSize.value = previewLayout.imageSize;
        isFilled.value = false;
        fillComplete.value = false;
        isClosing.value = false;
        scale.value = 1;
        isLoading.value = target.isLoading;
        fullSizeImage.value = null;
        centerPosition.value = previewLayout.centerPosition;
        originalDimensions.value = target.originalDimensions;

        rect.value = { top, left, width, height };
        image.value = target.overlayImage;
        borderRadius.value = radius || null;
        mediaType.value = target.mediaType;
        videoSrc.value = null;
        audioSrc.value = null;
        isAnimating.value = false;

        params.emitOpen();

        await nextTick();

        try {
            if (target.isVideo) {
                videoSrc.value = target.fullSizeUrl;
                isLoading.value = false;

                await params.handleItemSeen(masonryItem.id);
                await nextTick();
            } else if (target.isAudio) {
                fullSizeImage.value = target.initialFullSizeImage;
                audioSrc.value = target.fullSizeUrl;
                isLoading.value = false;

                await params.handleItemSeen(masonryItem.id);
                await nextTick();
            } else if (target.isFile) {
                fullSizeImage.value = target.initialFullSizeImage;
                isLoading.value = false;

                await params.handleItemSeen(masonryItem.id);
                await nextTick();
            } else {
                const imageDimensions = await preloadImage(target.fullSizeUrl);
                originalDimensions.value = imageDimensions;
                fullSizeImage.value = target.fullSizeUrl;
                isLoading.value = false;

                await params.handleItemSeen(masonryItem.id);

                await nextTick();
                await wait(50);
            }
        } catch (error) {
            console.warn('Failed to preload full-size image, using original:', error);
            fullSizeImage.value = target.previewSrc;
            isLoading.value = false;
            try {
                const fallbackDimensions = await preloadImage(target.previewSrc);
                originalDimensions.value = fallbackDimensions;
            } catch {
                originalDimensions.value = target.originalDimensions;
            }
            await nextTick();
        }

        await nextTick();
        await wait(50);
        await wait(0);

        if (!rect.value) return;

        const { width: containerWidth, height: containerHeight } = getContainerSize(tabContent);

        const centerLeft = Math.round((containerWidth - width) / 2);
        const centerTop = Math.round((containerHeight - height) / 2);

        isAnimating.value = true;
        rect.value = {
            top: centerTop,
            left: centerLeft,
            width,
            height,
        };

        setTimeout(() => {
            if (!container || !rect.value || !originalDimensions.value) return;

            updateOverlayLayout(
                tabContent,
                originalDimensions.value.width,
                originalDimensions.value.height,
            );

            isFilled.value = true;
            rect.value = {
                top: 0,
                left: 0,
                width: containerWidth,
                height: containerHeight,
            };

            setTimeout(() => {
                fillComplete.value = true;
            }, transitionDurationMs);
        }, transitionDurationMs);
    }

    return {
        openFromClick,
    };
}
