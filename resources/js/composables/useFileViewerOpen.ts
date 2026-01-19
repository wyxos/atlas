import { nextTick, type Ref } from 'vue';
import type { FeedItem } from '@/composables/useTabs';

export function useFileViewerOpen(params: {
    containerRef: Ref<HTMLElement | null>;
    masonryContainerRef: Ref<HTMLElement | null>;
    items: Ref<FeedItem[]>;
    containerOverflow: Ref<string | null>;
    containerOverscroll: Ref<string | null>;
    overlayRect: Ref<{ top: number; left: number; width: number; height: number } | null>;
    overlayImage: Ref<{ src: string; srcset?: string; sizes?: string; alt?: string } | null>;
    overlayMediaType: Ref<'image' | 'video'>;
    overlayVideoSrc: Ref<string | null>;
    overlayBorderRadius: Ref<string | null>;
    overlayKey: Ref<number>;
    overlayIsAnimating: Ref<boolean>;
    overlayImageSize: Ref<{ width: number; height: number } | null>;
    overlayIsFilled: Ref<boolean>;
    overlayFillComplete: Ref<boolean>;
    overlayIsClosing: Ref<boolean>;
    overlayScale: Ref<number>;
    overlayIsLoading: Ref<boolean>;
    overlayFullSizeImage: Ref<string | null>;
    originalImageDimensions: Ref<{ width: number; height: number } | null>;
    currentItemIndex: Ref<number | null>;
    imageScale: Ref<number>;
    imageCenterPosition: Ref<{ top: number; left: number } | null>;
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
    closeOverlay: () => void;
    emitOpen: () => void;
}) {
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

        if (params.containerOverflow.value === null) {
            params.containerOverflow.value = tabContent.style.overflow || '';
            params.containerOverscroll.value = tabContent.style.overscrollBehavior || '';
            tabContent.style.overflow = 'hidden';
            tabContent.style.overscrollBehavior = 'contain';
        }

        const target = e.target as HTMLElement | null;
        if (!target) return;

        const itemEl = target.closest('[data-testid="item-card"]') as HTMLElement | null;

        if (!itemEl || !container.contains(itemEl)) {
            params.closeOverlay();
            return;
        }

        const clickedItemId = getClickedItemId(target);
        if (clickedItemId === null) {
            params.closeOverlay();
            return;
        }

        const masonryItem = params.items.value.find((i) => i.id === clickedItemId) ?? null;
        if (!masonryItem) {
            params.closeOverlay();
            return;
        }

        const mediaType: 'image' | 'video' = masonryItem.type === 'video' ? 'video' : 'image';
        params.overlayMediaType.value = mediaType;
        params.overlayVideoSrc.value = null;

        const itemBox = itemEl.getBoundingClientRect();
        const tabContentBox = tabContent.getBoundingClientRect();

        const overlayBorderWidth = 4;
        const top = itemBox.top - tabContentBox.top - overlayBorderWidth;
        const left = itemBox.left - tabContentBox.left - overlayBorderWidth;
        const width = itemBox.width + (overlayBorderWidth * 2);
        const height = itemBox.height + (overlayBorderWidth * 2);

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

        const fullSizeUrl = masonryItem.original || src;
        params.currentItemIndex.value = params.items.value.findIndex((it) => it.id === masonryItem.id);

        params.overlayKey.value++;
        params.imageScale.value = 1;

        params.overlayImageSize.value = { width, height };
        params.overlayIsFilled.value = false;
        params.overlayFillComplete.value = false;
        params.overlayIsClosing.value = false;
        params.overlayScale.value = 1;
        params.overlayIsLoading.value = mediaType === 'image';
        params.overlayFullSizeImage.value = null;

        params.overlayRect.value = { top, left, width, height };
        params.overlayImage.value = { src, srcset, sizes, alt };
        params.overlayBorderRadius.value = radius || null;
        params.overlayIsAnimating.value = false;

        params.emitOpen();

        await nextTick();

        try {
            if (mediaType === 'video') {
                params.originalImageDimensions.value = {
                    width: masonryItem.width,
                    height: masonryItem.height,
                };
                params.overlayVideoSrc.value = fullSizeUrl;
                params.overlayIsLoading.value = false;

                await params.handleItemSeen(masonryItem.id);
                await nextTick();
            } else {
                const imageDimensions = await params.preloadImage(fullSizeUrl);
                params.originalImageDimensions.value = imageDimensions;
                params.overlayFullSizeImage.value = fullSizeUrl;
                params.overlayIsLoading.value = false;

                await params.handleItemSeen(masonryItem.id);

                await nextTick();
                await new Promise(resolve => setTimeout(resolve, 50));
            }
        } catch (error) {
            console.warn('Failed to preload full-size image, using original:', error);
            params.overlayFullSizeImage.value = src;
            params.overlayIsLoading.value = false;
            params.originalImageDimensions.value = {
                width: masonryItem.width,
                height: masonryItem.height,
            };
            await nextTick();
        }

        const borderWidth = 4;
        const initialContentWidth = width - (borderWidth * 2);
        const initialContentHeight = height - (borderWidth * 2);

        if (params.overlayImageSize.value) {
            params.imageCenterPosition.value = params.getCenteredPosition(
                initialContentWidth,
                initialContentHeight,
                params.overlayImageSize.value.width,
                params.overlayImageSize.value.height
            );
        }

        await nextTick();
        await new Promise(resolve => setTimeout(resolve, 50));

        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                const container = params.containerRef.value;
                if (!container || !params.overlayRect.value || !params.overlayImageSize.value) return;

                const tabContentBox = container.getBoundingClientRect();
                const containerWidth = tabContentBox.width;
                const containerHeight = tabContentBox.height;

                const centerLeft = Math.round((containerWidth - width) / 2);
                const centerTop = Math.round((containerHeight - height) / 2);

                const contentWidth = width - (borderWidth * 2);
                const contentHeight = height - (borderWidth * 2);

                params.imageCenterPosition.value = params.getCenteredPosition(
                    contentWidth,
                    contentHeight,
                    params.overlayImageSize.value.width,
                    params.overlayImageSize.value.height
                );

                params.overlayIsAnimating.value = true;
                params.overlayRect.value = {
                    top: centerTop,
                    left: centerLeft,
                    width,
                    height,
                };

                setTimeout(() => {
                    if (!container || !params.overlayRect.value || !params.overlayImageSize.value || !params.originalImageDimensions.value) return;

                    const availableWidth = params.getAvailableWidth(containerWidth, borderWidth);
                    const availableHeight = containerHeight - (borderWidth * 2);
                    const bestFitSize = params.calculateBestFitSize(
                        params.originalImageDimensions.value.width,
                        params.originalImageDimensions.value.height,
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

                    params.overlayIsFilled.value = true;
                    params.overlayRect.value = {
                        top: 0,
                        left: 0,
                        width: containerWidth,
                        height: containerHeight,
                    };

                    setTimeout(() => {
                        params.overlayFillComplete.value = true;
                    }, 500);
                }, 500);
            });
        });
    }

    return {
        openFromClick,
    };
}
