/**
 * FileViewer helpers.
 *
 * Note: This module intentionally uses named exports (no default export)
 * for consistency and safer refactors.
 */

export function preloadImage(url: string): Promise<{ width: number; height: number }> {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve({ width: img.naturalWidth, height: img.naturalHeight });
        img.onerror = () => reject(new Error(`Failed to load image: ${url}`));
        img.src = url;
    });
}

export function calculateBestFitSize(
    originalWidth: number,
    originalHeight: number,
    containerWidth: number,
    containerHeight: number
): { width: number; height: number } {
    if (originalWidth <= containerWidth && originalHeight <= containerHeight) {
        return {
            width: originalWidth,
            height: originalHeight,
        };
    }

    const aspectRatio = originalWidth / originalHeight;
    const containerAspectRatio = containerWidth / containerHeight;

    let fitWidth: number;
    let fitHeight: number;

    if (aspectRatio > containerAspectRatio) {
        fitWidth = containerWidth;
        fitHeight = containerWidth / aspectRatio;
    } else {
        fitHeight = containerHeight;
        fitWidth = containerHeight * aspectRatio;
    }

    fitWidth = Math.min(fitWidth, containerWidth);
    fitHeight = Math.min(fitHeight, containerHeight);

    return {
        width: Math.floor(fitWidth),
        height: Math.floor(fitHeight),
    };
}

export function getAvailableWidth(
    containerWidth: number,
    borderWidth: number,
    isFilled: boolean,
    fillComplete: boolean,
    isClosing: boolean,
    sheetOpen: boolean
): number {
    const taskbarWidth = isFilled && fillComplete && !isClosing && !sheetOpen ? 64 : 0; // w-16
    const sheetWidth = isFilled && fillComplete && !isClosing && sheetOpen ? 320 : 0; // w-80
    return containerWidth - (borderWidth * 2) - taskbarWidth - sheetWidth;
}

export type MasonryItemLike = {
    id: number;
    key?: string;
    src?: string;
    thumbnail?: string;
};

export function findMasonryItemByImageSrc<T extends MasonryItemLike>(
    imageSrc: string,
    itemElement: HTMLElement,
    items: Array<T>
): T | null {
    const itemKeyAttr = itemElement.getAttribute('data-key');
    if (itemKeyAttr) {
        const itemByKey = items.find((i) => i.key === itemKeyAttr);
        if (itemByKey) {
            return itemByKey;
        }

        const parts = itemKeyAttr.split('-');
        const fileId = parts.length > 1 ? Number(parts[parts.length - 1]) : Number(itemKeyAttr);
        if (!isNaN(fileId)) {
            const item = items.find((i) => i.id === fileId);
            if (item) {
                return item;
            }
        }
    }

    const baseSrc = imageSrc.split('?')[0].split('#')[0];
    return (
        items.find((item) => {
            const itemSrc = (item.src || item.thumbnail || '').split('?')[0].split('#')[0];
            return baseSrc === itemSrc || baseSrc.includes(itemSrc) || itemSrc.includes(baseSrc);
        }) || null
    );
}
