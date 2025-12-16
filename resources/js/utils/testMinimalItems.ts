/**
 * Utility to test performance with minimal items
 * This helps prove if object size is causing performance issues
 */

import type { MasonryItem } from '@/composables/useBrowseTabs';
import { createMinimalItems, compareItemPerformance } from './itemSizeDiagnostics';

/**
 * Essential properties needed for masonry rendering
 */
const ESSENTIAL_PROPERTIES = [
    'id',
    'width',
    'height',
    'src',
    'key',
    'page',
    'index',
] as const;

/**
 * Create a test version with minimal items
 * Use this to test if reducing object size improves performance
 */
export function createTestMinimalItems(items: MasonryItem[]): MasonryItem[] {
    return createMinimalItems(items, [...ESSENTIAL_PROPERTIES]) as MasonryItem[];
}

/**
 * Test performance difference between full and minimal items
 * Call this from browser console: window.testItemPerformance()
 */
export function setupPerformanceTest(items: MasonryItem[]): void {
    if (items.length === 0) {
        console.warn('No items to test');
        return;
    }

    const minimalItems = createTestMinimalItems(items);

    compareItemPerformance(items, minimalItems, 'Masonry Item Performance Test');

    console.log('\n💡 To test with minimal items:');
    console.log('1. Open Vue DevTools');
    console.log('2. Find the BrowseTabContent component');
    console.log('3. Set items.value to the minimal items array');
    console.log('\nOr use: window.testWithMinimalItems()');
}

/**
 * Make test function available globally for easy testing
 */
if (typeof window !== 'undefined') {
    (window as unknown as { testItemPerformance?: () => void }).testItemPerformance = () => {
        // This will be called from component context
        console.log('Call this from the component context where items are available');
    };
}


