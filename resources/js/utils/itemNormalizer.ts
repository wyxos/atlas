import type { MasonryItem } from '@/composables/useBrowseTabs';

/**
 * Normalizes a MasonryItem to ensure all properties exist (even as undefined).
 * This is critical for reactivity with shallowRef - properties must exist initially
 * to be reactive when updated later.
 * 
 * When items are loaded initially with minimal data, properties like originalUrl,
 * prompt, previewed_count, etc. may not exist. By ensuring they exist as undefined,
 * Vue's reactivity system will track them even with shallowRef.
 */
export function normalizeMasonryItem(item: Partial<MasonryItem> & { id: number; width: number; height: number; src: string; page: number; key: string; index: number }): MasonryItem {
    return {
        id: item.id,
        width: item.width,
        height: item.height,
        page: item.page,
        key: item.key,
        index: item.index,
        src: item.src,
        // Ensure all optional properties exist (even if undefined)
        // This makes them reactive with shallowRef when updated later
        originalUrl: item.originalUrl ?? undefined,
        thumbnail: item.thumbnail ?? undefined,
        type: item.type ?? undefined,
        notFound: item.notFound ?? undefined,
        previewed_count: item.previewed_count ?? undefined,
        seen_count: item.seen_count ?? undefined,
        will_auto_dislike: item.will_auto_dislike ?? undefined,
        // Preserve any other properties that might exist
        ...Object.fromEntries(
            Object.entries(item).filter(([key]) => 
                !['id', 'width', 'height', 'page', 'key', 'index', 'src', 
                  'originalUrl', 'thumbnail', 'type', 'notFound', 
                  'previewed_count', 'seen_count', 'will_auto_dislike'].includes(key)
            )
        ),
    };
}

/**
 * Normalizes an array of MasonryItems.
 */
export function normalizeMasonryItems(items: Array<Partial<MasonryItem> & { id: number; width: number; height: number; src: string; page: number; key: string; index: number }>): MasonryItem[] {
    return items.map(normalizeMasonryItem);
}

