/**
 * Utility functions for FileViewer component
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
    // If image is smaller than container in both dimensions, use original size (will be centered)
    if (originalWidth <= containerWidth && originalHeight <= containerHeight) {
        return {
            width: originalWidth,
            height: originalHeight,
        };
    }

    // Image is larger than container - scale down to fit while maintaining aspect ratio
    const aspectRatio = originalWidth / originalHeight;
    const containerAspectRatio = containerWidth / containerHeight;

    let fitWidth: number;
    let fitHeight: number;

    if (aspectRatio > containerAspectRatio) {
        // Image is wider - fit to width
        fitWidth = containerWidth;
        fitHeight = containerWidth / aspectRatio;
    } else {
        // Image is taller - fit to height
        fitHeight = containerHeight;
        fitWidth = containerHeight * aspectRatio;
    }

    // Ensure dimensions don't exceed container bounds (account for rounding errors)
    fitWidth = Math.min(fitWidth, containerWidth);
    fitHeight = Math.min(fitHeight, containerHeight);

    return {
        width: Math.floor(fitWidth), // Use floor to ensure we don't exceed bounds
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
    const taskbarWidth = isFilled && fillComplete && !isClosing && !sheetOpen ? 64 : 0; // w-16 = 64px
    const sheetWidth = isFilled && fillComplete && !isClosing && sheetOpen ? 320 : 0; // w-80 = 320px
    return containerWidth - (borderWidth * 2) - taskbarWidth - sheetWidth;
}

export function findMasonryItemByImageSrc(
    imageSrc: string,
    itemElement: HTMLElement,
    items: Array<{ id: number; key?: string; src?: string; thumbnail?: string }>
): { id: number; key?: string; src?: string; thumbnail?: string } | null {
    // Try to find item by checking data attributes on the masonry item element
    const itemKeyAttr = itemElement.getAttribute('data-key');
    if (itemKeyAttr) {
        // Match by key (provided by backend)
        const itemByKey = items.find(i => i.key === itemKeyAttr);
        if (itemByKey) return itemByKey;

        // Fallback: parse and match by id only (for backward compatibility with old data)
        const parts = itemKeyAttr.split('-');
        const fileId = parts.length > 1 ? Number(parts[parts.length - 1]) : Number(itemKeyAttr);
        if (!isNaN(fileId)) {
            const item = items.find(i => i.id === fileId);
            if (item) return item;
        }
    }

    // Fallback: try to match by URL (compare src with item.src or thumbnail)
    // Extract base URL without query params for comparison
    const baseSrc = imageSrc.split('?')[0].split('#')[0];
    return items.find(item => {
        const itemSrc = (item.src || item.thumbnail || '').split('?')[0].split('#')[0];
        return baseSrc === itemSrc || baseSrc.includes(itemSrc) || itemSrc.includes(baseSrc);
    }) || null;
}

