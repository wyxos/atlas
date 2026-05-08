import type { ServiceFilterOption } from '@/lib/browseCatalog';

export type LocalSourceSelection = string | string[];

export type LocalSourceOption = {
    label: string;
    value: string;
};

function stringifySourceValue(value: unknown): string | null {
    if (typeof value === 'string') {
        const trimmed = value.trim();

        return trimmed.length > 0 ? trimmed : null;
    }

    if (typeof value === 'number' && Number.isFinite(value)) {
        return String(value);
    }

    return null;
}

export function normalizeLocalSourceSelection(value: unknown): string[] {
    const rawValues = Array.isArray(value) ? value : [value];
    const sources = rawValues
        .map(stringifySourceValue)
        .filter((source): source is string => source !== null);
    const uniqueSources = Array.from(new Set(sources));

    if (uniqueSources.length === 0 || uniqueSources.includes('all')) {
        return ['all'];
    }

    return uniqueSources;
}

export function createLocalSourceOptions(sources: readonly string[]): LocalSourceOption[] {
    return normalizeLocalSourceOptions(sources.map((source) => ({
        label: source === 'all' ? 'All' : source,
        value: source,
    })));
}

export function normalizeLocalSourceOptions(options: readonly ServiceFilterOption[]): LocalSourceOption[] {
    const seen = new Set<string>();
    const normalized: LocalSourceOption[] = [];

    for (const option of options) {
        const value = stringifySourceValue(option.value);

        if (!value || seen.has(value)) {
            continue;
        }

        seen.add(value);
        normalized.push({
            label: option.label || (value === 'all' ? 'All' : value),
            value,
        });
    }

    return normalized;
}

export function isLocalSourceSelected(selection: unknown, value: string): boolean {
    const selectedSources = normalizeLocalSourceSelection(selection);

    if (value === 'all') {
        return selectedSources.length === 1 && selectedSources[0] === 'all';
    }

    return !selectedSources.includes('all') && selectedSources.includes(value);
}

export function toggleLocalSourceSelection(selection: unknown, value: string, checked: boolean): string[] {
    if (value === 'all') {
        return ['all'];
    }

    const selectedSources = normalizeLocalSourceSelection(selection).filter((source) => source !== 'all');
    const nextSources = new Set(selectedSources);

    if (checked) {
        nextSources.add(value);
    } else {
        nextSources.delete(value);
    }

    return nextSources.size === 0 ? ['all'] : Array.from(nextSources);
}

export function formatLocalSourceSelectionLabel(
    selection: unknown,
    options: readonly LocalSourceOption[],
    fallback = 'Select sources...',
): string {
    const selectedSources = normalizeLocalSourceSelection(selection);
    const optionLabels = new Map(options.map((option) => [option.value, option.label]));

    const selectedSource = selectedSources[0];

    if (!selectedSource) {
        return fallback;
    }

    if (selectedSources.length === 1 && selectedSource === 'all') {
        return optionLabels.get('all') ?? 'All sources';
    }

    if (selectedSources.length === 1) {
        return optionLabels.get(selectedSource) ?? selectedSource;
    }

    return `${selectedSources.length} sources`;
}
