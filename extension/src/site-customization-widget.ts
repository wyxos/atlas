export const DEFAULT_WIDGET_MIN_IMAGE_WIDTH = 200;

export type WidgetConfig = {
    minImageWidth: number | null;
};

export function normalizeWidgetMinImageWidth(value: number | null | undefined): number | null {
    if (value === null || value === undefined || !Number.isFinite(value) || value < 0) {
        return null;
    }

    return Math.floor(value);
}

function parseImportWidgetMinImageWidth(value: unknown, domain: string): number | null {
    if (value === undefined || value === null) {
        return null;
    }

    if (typeof value !== 'number' || !Number.isInteger(value) || value < 0) {
        throw new Error(`Domain "${domain}" widget.minImageWidth must be a non-negative integer or null.`);
    }

    return value;
}

export function parseImportWidgetConfig(value: unknown, domain: string): WidgetConfig {
    if (value === undefined || value === null) {
        return { minImageWidth: null };
    }

    if (typeof value !== 'object') {
        throw new Error(`Domain "${domain}" widget must be an object.`);
    }

    return {
        minImageWidth: parseImportWidgetMinImageWidth((value as Record<string, unknown>).minImageWidth, domain),
    };
}

export function parseStoredWidgetConfig(value: unknown): WidgetConfig {
    const row = value && typeof value === 'object'
        ? value as Record<string, unknown>
        : {};

    return {
        minImageWidth: typeof row.minImageWidth === 'number'
            ? normalizeWidgetMinImageWidth(row.minImageWidth)
            : null,
    };
}

export function validateWidgetConfig(domain: string, widget: WidgetConfig | undefined): string | null {
    const minImageWidth = widget?.minImageWidth ?? null;
    if (minImageWidth !== null && (!Number.isInteger(minImageWidth) || minImageWidth < 0)) {
        return `Domain "${domain}" widget min image width must be a non-negative integer.`;
    }

    return null;
}
