/**
 * Diagnostic utilities to measure and analyze item object sizes
 * Helps identify if large object attributes are causing performance issues
 */

export interface ItemSizeDiagnostics {
    totalItems: number;
    totalMemoryBytes: number;
    averageItemSizeBytes: number;
    itemSizeDistribution: {
        min: number;
        max: number;
        median: number;
        p95: number;
        p99: number;
    };
    propertyCounts: Record<string, number>;
    largestProperties: Array<{ property: string; averageSize: number; totalSize: number }>;
    sampleItem: Record<string, unknown>;
    recommendations: string[];
}

/**
 * Estimate the memory size of a JavaScript object in bytes
 * This is an approximation - actual memory usage may vary
 */
function estimateObjectSize(obj: unknown, visited = new WeakSet()): number {
    if (obj === null || obj === undefined) {
        return 0;
    }

    // Handle circular references
    if (typeof obj === 'object' && visited.has(obj as object)) {
        return 0;
    }

    if (typeof obj === 'object' && obj !== null) {
        visited.add(obj as object);
    }

    switch (typeof obj) {
        case 'boolean':
            return 4; // V8 uses 4 bytes for booleans
        case 'number':
            return 8; // V8 uses 8 bytes for numbers
        case 'string':
            // UTF-16 encoding: 2 bytes per character + overhead
            return (obj as string).length * 2 + 24;
        case 'object':
            if (Array.isArray(obj)) {
                let size = 24; // Array overhead
                for (const item of obj) {
                    size += estimateObjectSize(item, visited);
                }
                return size;
            } else if (obj instanceof Date) {
                return 24; // Date object overhead
            } else {
                let size = 24; // Object overhead
                for (const key in obj) {
                    if (Object.prototype.hasOwnProperty.call(obj, key)) {
                        // Key string size
                        size += key.length * 2 + 24;
                        // Value size
                        size += estimateObjectSize((obj as Record<string, unknown>)[key], visited);
                    }
                }
                return size;
            }
        default:
            return 0;
    }
}

/**
 * Analyze item sizes and provide diagnostics
 */
export function analyzeItemSizes(items: unknown[]): ItemSizeDiagnostics {
    if (items.length === 0) {
        return {
            totalItems: 0,
            totalMemoryBytes: 0,
            averageItemSizeBytes: 0,
            itemSizeDistribution: {
                min: 0,
                max: 0,
                median: 0,
                p95: 0,
                p99: 0,
            },
            propertyCounts: {},
            largestProperties: [],
            sampleItem: {},
            recommendations: ['No items to analyze'],
        };
    }

    // Calculate size for each item
    const itemSizes = items.map(item => estimateObjectSize(item));
    const sortedSizes = [...itemSizes].sort((a, b) => a - b);

    // Calculate percentiles
    const getPercentile = (arr: number[], percentile: number): number => {
        const index = Math.ceil((arr.length * percentile) / 100) - 1;
        return arr[Math.max(0, Math.min(index, arr.length - 1))];
    };

    // Collect all unique properties
    const propertySet = new Set<string>();
    for (const item of items) {
        if (typeof item === 'object' && item !== null) {
            for (const key in item) {
                if (Object.prototype.hasOwnProperty.call(item, key)) {
                    propertySet.add(key);
                }
            }
        }
    }

    // Calculate property statistics
    const propertyCounts: Record<string, number> = {};
    const propertySizes: Record<string, number> = {};

    for (const property of propertySet) {
        let count = 0;
        let totalSize = 0;

        for (const item of items) {
            if (typeof item === 'object' && item !== null && property in item) {
                count++;
                totalSize += estimateObjectSize((item as Record<string, unknown>)[property]);
            }
        }

        propertyCounts[property] = count;
        propertySizes[property] = totalSize;
    }

    // Find largest properties
    const largestProperties = Object.entries(propertySizes)
        .map(([property, totalSize]) => ({
            property,
            averageSize: totalSize / (propertyCounts[property] || 1),
            totalSize,
        }))
        .sort((a, b) => b.totalSize - a.totalSize)
        .slice(0, 10);

    // Calculate total memory
    const totalMemoryBytes = itemSizes.reduce((sum, size) => sum + size, 0);
    const averageItemSizeBytes = totalMemoryBytes / items.length;

    // Generate recommendations
    const recommendations: string[] = [];
    const totalMB = totalMemoryBytes / (1024 * 1024);
    const avgKB = averageItemSizeBytes / 1024;

    if (totalMB > 50) {
        recommendations.push(`Large total memory: ${totalMB.toFixed(2)}MB. Consider pagination or virtualization.`);
    }

    if (avgKB > 10) {
        recommendations.push(`Large average item size: ${avgKB.toFixed(2)}KB. Items may contain unnecessary data.`);
    }

    // Check for large properties
    const largeProperties = largestProperties.filter(p => p.averageSize > 1000);
    if (largeProperties.length > 0) {
        recommendations.push(
            `Large properties detected: ${largeProperties.map(p => p.property).join(', ')}. Consider removing or compressing these.`
        );
    }

    // Check for many properties
    const propertyCount = Object.keys(propertyCounts).length;
    if (propertyCount > 20) {
        recommendations.push(
            `Many properties per item (${propertyCount}). Consider using a minimal item structure for the grid.`
        );
    }

    // Check for unused properties
    const requiredProperties = ['id', 'width', 'height', 'src', 'key'];
    const unusedProperties = Object.keys(propertyCounts).filter(
        prop => !requiredProperties.includes(prop) && propertyCounts[prop] < items.length * 0.1
    );
    if (unusedProperties.length > 0) {
        recommendations.push(
            `Potentially unused properties: ${unusedProperties.slice(0, 5).join(', ')}. Consider filtering these out.`
        );
    }

    return {
        totalItems: items.length,
        totalMemoryBytes,
        averageItemSizeBytes,
        itemSizeDistribution: {
            min: sortedSizes[0] || 0,
            max: sortedSizes[sortedSizes.length - 1] || 0,
            median: getPercentile(sortedSizes, 50),
            p95: getPercentile(sortedSizes, 95),
            p99: getPercentile(sortedSizes, 99),
        },
        propertyCounts,
        largestProperties,
        sampleItem: (items[0] as Record<string, unknown>) || {},
        recommendations,
    };
}

/**
 * Log diagnostics to console in a readable format
 */
export function logItemSizeDiagnostics(diagnostics: ItemSizeDiagnostics): void {
    console.group('📊 Item Size Diagnostics');
    console.log(`Total Items: ${diagnostics.totalItems.toLocaleString()}`);
    console.log(`Total Memory: ${(diagnostics.totalMemoryBytes / (1024 * 1024)).toFixed(2)} MB`);
    console.log(`Average Item Size: ${(diagnostics.averageItemSizeBytes / 1024).toFixed(2)} KB`);

    console.group('Size Distribution');
    console.log(`Min: ${(diagnostics.itemSizeDistribution.min / 1024).toFixed(2)} KB`);
    console.log(`Median: ${(diagnostics.itemSizeDistribution.median / 1024).toFixed(2)} KB`);
    console.log(`P95: ${(diagnostics.itemSizeDistribution.p95 / 1024).toFixed(2)} KB`);
    console.log(`P99: ${(diagnostics.itemSizeDistribution.p99 / 1024).toFixed(2)} KB`);
    console.log(`Max: ${(diagnostics.itemSizeDistribution.max / 1024).toFixed(2)} KB`);
    console.groupEnd();

    console.group('Property Statistics');
    console.table(
        Object.entries(diagnostics.propertyCounts)
            .map(([property, count]) => ({
                Property: property,
                Count: count,
                'Coverage %': ((count / diagnostics.totalItems) * 100).toFixed(1),
                'Avg Size': diagnostics.largestProperties.find(p => p.property === property)?.averageSize.toFixed(0) || '0',
            }))
            .sort((a, b) => Number(b['Avg Size']) - Number(a['Avg Size']))
    );
    console.groupEnd();

    console.group('Largest Properties (by total size)');
    console.table(
        diagnostics.largestProperties.map(p => ({
            Property: p.property,
            'Total Size (KB)': (p.totalSize / 1024).toFixed(2),
            'Avg Size (bytes)': p.averageSize.toFixed(0),
        }))
    );
    console.groupEnd();

    console.group('Sample Item');
    console.log(diagnostics.sampleItem);
    console.groupEnd();

    if (diagnostics.recommendations.length > 0) {
        console.group('💡 Recommendations');
        diagnostics.recommendations.forEach(rec => console.warn(rec));
        console.groupEnd();
    }

    console.groupEnd();
}

