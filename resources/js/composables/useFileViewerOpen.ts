import { nextTick, toRefs, type Ref } from 'vue';
import type { FeedItem } from '@/composables/useTabs';

type OverlayMediaType = 'image' | 'video' | 'audio' | 'file';

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
        mediaType: OverlayMediaType;
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

        const resolveMediaType = (item: FeedItem): OverlayMediaType => {
            const kind = typeof item.media_kind === 'string' ? item.media_kind : null;
            if (kind === 'image' || kind === 'video' || kind === 'audio' || kind === 'file') {
                return kind;
            }

            const mime = typeof item.mime_type === 'string' ? item.mime_type : '';
            if (mime.startsWith('video/')) return 'video';
            if (mime.startsWith('image/')) return 'image';
            if (mime.startsWith('audio/')) return 'audio';

            return item.type === 'video' ? 'video' : 'image';
        };

        const nextMediaType = resolveMediaType(masonryItem);
        mediaType.value = nextMediaType;
        videoSrc.value = null;
        audioSrc.value = null;

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
        currentItemIndex.value = params.items.value.findIndex((it) => it.id === masonryItem.id);

        key.value++;
        imageScale.value = 1;

        imageSize.value = { width, height };
        isFilled.value = false;
        fillComplete.value = false;
        isClosing.value = false;
        scale.value = 1;
        isLoading.value = nextMediaType === 'image';
        fullSizeImage.value = null;

        rect.value = { top, left, width, height };
        image.value = { src, srcset, sizes, alt };
        borderRadius.value = radius || null;
        isAnimating.value = false;

        params.emitOpen();

        await nextTick();

        try {
            if (nextMediaType === 'video') {
                originalDimensions.value = {
                    width: masonryItem.width,
                    height: masonryItem.height,
                };
                videoSrc.value = fullSizeUrl;
                isLoading.value = false;

                await params.handleItemSeen(masonryItem.id);
                await nextTick();
            } else if (nextMediaType === 'audio') {
                originalDimensions.value = {
                    width: masonryItem.width,
                    height: masonryItem.height,
                };
                // The overlay still renders an image (icon), but we load/play the audio URL.
                fullSizeImage.value = src;
                audioSrc.value = fullSizeUrl;
                isLoading.value = false;

                await params.handleItemSeen(masonryItem.id);
                await nextTick();
            } else if (nextMediaType === 'file') {
                originalDimensions.value = {
                    width: masonryItem.width,
                    height: masonryItem.height,
                };
                // Generic files show the icon in the overlay; sheet can show details/actions.
                fullSizeImage.value = src;
                isLoading.value = false;

                await params.handleItemSeen(masonryItem.id);
                await nextTick();
            } else {
                const imageDimensions = await params.preloadImage(fullSizeUrl);
                originalDimensions.value = imageDimensions;
                fullSizeImage.value = fullSizeUrl;
                isLoading.value = false;

                await params.handleItemSeen(masonryItem.id);

                await nextTick();
                await new Promise(resolve => setTimeout(resolve, 50));
            }
        } catch (error) {
            console.warn('Failed to preload full-size image, using original:', error);
            fullSizeImage.value = src;
            isLoading.value = false;
            try {
                const fallbackDimensions = await params.preloadImage(src);
                originalDimensions.value = fallbackDimensions;
            } catch {
                originalDimensions.value = {
                    width: masonryItem.width,
                    height: masonryItem.height,
                };
            }
            await nextTick();
        }

        const borderWidth = 4;
        const initialContentWidth = width - (borderWidth * 2);
        const initialContentHeight = height - (borderWidth * 2);

        if (imageSize.value) {
            centerPosition.value = params.getCenteredPosition(
                initialContentWidth,
                initialContentHeight,
                imageSize.value.width,
                imageSize.value.height
            );
        }

        await nextTick();
        await new Promise(resolve => setTimeout(resolve, 50));
        await new Promise(resolve => setTimeout(resolve, 0));

        if (!tabContent || !rect.value || !imageSize.value) return;

        const tabContentBoxAfter = tabContent.getBoundingClientRect();
        const containerWidth = tabContentBoxAfter.width;
        const containerHeight = tabContentBoxAfter.height;

        const centerLeft = Math.round((containerWidth - width) / 2);
        const centerTop = Math.round((containerHeight - height) / 2);

        const contentWidth = width - (borderWidth * 2);
        const contentHeight = height - (borderWidth * 2);

        centerPosition.value = params.getCenteredPosition(
            contentWidth,
            contentHeight,
            imageSize.value.width,
            imageSize.value.height
        );

        isAnimating.value = true;
        rect.value = {
            top: centerTop,
            left: centerLeft,
            width,
            height,
        };

        setTimeout(() => {
            if (!container || !rect.value || !imageSize.value || !originalDimensions.value) return;

            const availableWidth = params.getAvailableWidth(containerWidth, borderWidth);
            const availableHeight = containerHeight - (borderWidth * 2);
            const bestFitSize = params.calculateBestFitSize(
                originalDimensions.value.width,
                originalDimensions.value.height,
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

            isFilled.value = true;
            rect.value = {
                top: 0,
                left: 0,
                width: containerWidth,
                height: containerHeight,
            };

            setTimeout(() => {
                fillComplete.value = true;
            }, 500);
        }, 500);
    }

    return {
        openFromClick,
    };
}
