import type { BrowseFormData } from '@/composables/useBrowseForm';
import type { ServiceFilterField, ServiceFilterSchema } from '@/lib/browseCatalog';

export type LocalPreset = {
    label: string;
    value: string;
    filters: Record<string, unknown>;
};

export type LocalPresetGroup = {
    label: string;
    presets: LocalPreset[];
};

export const TAB_FILTER_LIMIT_OPTIONS = ['20', '40', '60', '80', '100', '200'] as const;

export const LOCAL_TAB_FILTER_PRESET_GROUPS: LocalPresetGroup[] = [
    {
        label: 'Common',
        presets: [
            {
                label: 'All',
                value: 'all',
                filters: {
                    downloaded: 'any',
                    reaction_mode: 'any',
                    auto_disliked: 'any',
                    blacklisted: 'any',
                    blacklist_type: 'any',
                    max_previewed_count: null,
                    sort: 'downloaded_at',
                    seed: null,
                },
            },
            {
                label: 'Inbox (Fresh)',
                value: 'inbox_fresh',
                filters: {
                    downloaded: 'any',
                    reaction_mode: 'unreacted',
                    blacklisted: 'no',
                    auto_disliked: 'no',
                    max_previewed_count: 0,
                    sort: 'created_at',
                },
            },
            {
                label: 'Reacted (Random)',
                value: 'reacted_random',
                filters: {
                    downloaded: 'any',
                    reaction_mode: 'reacted',
                    blacklisted: 'no',
                    auto_disliked: 'no',
                    max_previewed_count: null,
                    sort: 'random',
                },
            },
            {
                label: 'Favorite (Random)',
                value: 'favorite_random',
                filters: {
                    downloaded: 'any',
                    reaction_mode: 'types',
                    reaction: ['love'],
                    blacklisted: 'no',
                    blacklist_type: 'any',
                    auto_disliked: 'no',
                    max_previewed_count: null,
                    sort: 'random',
                },
            },
            {
                label: 'Favorite (Latest)',
                value: 'favorite_latest',
                filters: {
                    downloaded: 'any',
                    reaction_mode: 'types',
                    reaction: ['love'],
                    blacklisted: 'no',
                    blacklist_type: 'any',
                    auto_disliked: 'no',
                    max_previewed_count: null,
                    sort: 'reaction_at',
                },
            },
            {
                label: 'Funny (Random)',
                value: 'funny_random',
                filters: {
                    downloaded: 'any',
                    reaction_mode: 'types',
                    reaction: ['funny'],
                    blacklisted: 'no',
                    blacklist_type: 'any',
                    auto_disliked: 'no',
                    max_previewed_count: null,
                    sort: 'random',
                },
            },
        ],
    },
    {
        label: 'Unreacted',
        presets: [
            {
                label: 'Unreacted (Random)',
                value: 'unreacted_random',
                filters: {
                    downloaded: 'any',
                    reaction_mode: 'unreacted',
                    blacklisted: 'no',
                    auto_disliked: 'no',
                    max_previewed_count: null,
                    sort: 'random',
                },
            },
            {
                label: 'Unreacted (Newest)',
                value: 'inbox_newest',
                filters: {
                    downloaded: 'any',
                    reaction_mode: 'unreacted',
                    blacklisted: 'no',
                    auto_disliked: 'no',
                    max_previewed_count: null,
                    sort: 'created_at',
                },
            },
            {
                label: 'Unreacted (Oldest)',
                value: 'inbox_oldest',
                filters: {
                    downloaded: 'any',
                    reaction_mode: 'unreacted',
                    blacklisted: 'no',
                    auto_disliked: 'no',
                    max_previewed_count: null,
                    sort: 'created_at_asc',
                },
            },
        ],
    },
    {
        label: 'Reactions',
        presets: [
            {
                label: 'Reacted (Newest)',
                value: 'reacted_newest',
                filters: {
                    downloaded: 'any',
                    reaction_mode: 'reacted',
                    blacklisted: 'no',
                    auto_disliked: 'no',
                    max_previewed_count: null,
                    sort: 'reaction_at',
                },
            },
            {
                label: 'Reacted (Oldest)',
                value: 'reacted_oldest',
                filters: {
                    downloaded: 'any',
                    reaction_mode: 'reacted',
                    blacklisted: 'no',
                    auto_disliked: 'no',
                    max_previewed_count: null,
                    sort: 'reaction_at_asc',
                },
            },
        ],
    },
    {
        label: 'Moderation',
        presets: [
            {
                label: 'Disliked (Any)',
                value: 'disliked_any',
                filters: {
                    downloaded: 'any',
                    reaction_mode: 'types',
                    reaction: ['dislike'],
                    blacklisted: 'no',
                    blacklist_type: 'any',
                    auto_disliked: 'any',
                    include_total: true,
                    max_previewed_count: 2,
                    sort: 'reaction_at',
                },
            },
            {
                label: 'Disliked (Manual)',
                value: 'disliked_manual',
                filters: {
                    downloaded: 'any',
                    reaction_mode: 'types',
                    reaction: ['dislike'],
                    blacklisted: 'no',
                    blacklist_type: 'any',
                    auto_disliked: 'no',
                    include_total: true,
                    max_previewed_count: 2,
                    sort: 'reaction_at',
                },
            },
            {
                label: 'Saved Dislikes (Manual)',
                value: 'saved_dislikes_manual',
                filters: {
                    downloaded: 'yes',
                    reaction_mode: 'types',
                    reaction: ['dislike'],
                    blacklisted: 'no',
                    blacklist_type: 'any',
                    auto_disliked: 'no',
                    include_total: true,
                    max_previewed_count: 2,
                    sort: 'reaction_at',
                },
            },
            {
                label: 'Disliked (Auto)',
                value: 'disliked_auto',
                filters: {
                    downloaded: 'any',
                    reaction_mode: 'types',
                    reaction: ['dislike'],
                    blacklisted: 'no',
                    blacklist_type: 'any',
                    auto_disliked: 'yes',
                    include_total: true,
                    max_previewed_count: 2,
                    sort: 'reaction_at',
                },
            },
            {
                label: 'Saved Dislikes (Auto)',
                value: 'saved_dislikes_auto',
                filters: {
                    downloaded: 'yes',
                    reaction_mode: 'types',
                    reaction: ['dislike'],
                    blacklisted: 'no',
                    blacklist_type: 'any',
                    auto_disliked: 'yes',
                    include_total: true,
                    max_previewed_count: 2,
                    sort: 'reaction_at',
                },
            },
            {
                label: 'Blacklisted (Any)',
                value: 'blacklisted_any',
                filters: {
                    downloaded: 'any',
                    reaction_mode: 'any',
                    blacklisted: 'yes',
                    blacklist_type: 'any',
                    auto_disliked: 'any',
                    max_previewed_count: 2,
                    sort: 'blacklisted_at',
                },
            },
            {
                label: 'Blacklisted (Manual)',
                value: 'blacklisted_manual',
                filters: {
                    downloaded: 'any',
                    reaction_mode: 'any',
                    blacklisted: 'yes',
                    blacklist_type: 'manual',
                    auto_disliked: 'any',
                    max_previewed_count: 2,
                    sort: 'blacklisted_at',
                },
            },
            {
                label: 'Saved Blacklist (Manual)',
                value: 'saved_blacklisted_manual',
                filters: {
                    downloaded: 'yes',
                    reaction_mode: 'any',
                    blacklisted: 'yes',
                    blacklist_type: 'manual',
                    auto_disliked: 'any',
                    max_previewed_count: 2,
                    sort: 'blacklisted_at',
                },
            },
            {
                label: 'Blacklisted (Auto)',
                value: 'blacklisted_auto',
                filters: {
                    downloaded: 'any',
                    reaction_mode: 'any',
                    blacklisted: 'yes',
                    blacklist_type: 'auto',
                    auto_disliked: 'any',
                    max_previewed_count: 2,
                    sort: 'blacklisted_at',
                },
            },
            {
                label: 'Saved Blacklist (Auto)',
                value: 'saved_blacklisted_auto',
                filters: {
                    downloaded: 'yes',
                    reaction_mode: 'any',
                    blacklisted: 'yes',
                    blacklist_type: 'auto',
                    auto_disliked: 'any',
                    max_previewed_count: 2,
                    sort: 'blacklisted_at',
                },
            },
            {
                label: 'Disliked + Blacklisted (Auto)',
                value: 'disliked_blacklisted_auto',
                filters: {
                    downloaded: 'any',
                    reaction_mode: 'any',
                    blacklisted: 'any',
                    blacklist_type: 'any',
                    auto_disliked: 'any',
                    moderation_union: 'auto_disliked_or_blacklisted_auto',
                    include_total: true,
                    max_previewed_count: 2,
                    sort: 'blacklisted_at',
                },
            },
        ],
    },
];

export const LOCAL_TAB_FILTER_PRESETS: LocalPreset[] = LOCAL_TAB_FILTER_PRESET_GROUPS.flatMap((group) => group.presets);

export type TabFilterFieldUpdate = {
    uiKey: string;
    value: unknown;
};

export function getVisibleTabFilterFields(
    schema: ServiceFilterSchema | null | undefined,
    feed: BrowseFormData['feed'],
): ServiceFilterField[] {
    if (!schema?.fields.length) {
        return [];
    }

    return schema.fields.filter((field) => {
        if (field.type === 'hidden') {
            return false;
        }

        if (field.uiKey === 'page' || field.uiKey === 'limit') {
            return false;
        }

        if (feed === 'local' && field.uiKey === 'source') {
            return false;
        }

        return true;
    });
}

export function getLocalSourceField(schema: ServiceFilterSchema | null | undefined): ServiceFilterField | null {
    if (!schema?.fields.length) {
        return null;
    }

    return schema.fields.find((field) => field.uiKey === 'source') ?? null;
}

export function getTabFilterValueOrDefault(field: ServiceFilterField, value: unknown): unknown {
    if (value !== undefined && value !== null && value !== '') {
        return value;
    }

    return field.default;
}

export function getTabFilterCheckboxGroupSelection(field: ServiceFilterField, value: unknown): string[] {
    const raw = getTabFilterValueOrDefault(field, value);
    if (!Array.isArray(raw)) {
        return [];
    }

    return raw.map((entry) => String(entry));
}

export function toggleTabFilterCheckboxGroupValue(
    field: ServiceFilterField,
    currentValue: unknown,
    value: string,
    checked: boolean,
): string[] {
    if (field.uiKey === 'file_type') {
        const allTypes = ['image', 'video', 'audio', 'other'];
        const current = new Set(getTabFilterCheckboxGroupSelection(field, currentValue));

        if (checked) {
            if (value === 'all') {
                return ['all'];
            }

            current.delete('all');
            current.add(value);

            return allTypes.every((type) => current.has(type)) ? ['all'] : Array.from(current);
        }

        if (value === 'all') {
            return ['all'];
        }

        current.delete(value);

        return current.size === 0 ? ['all'] : Array.from(current);
    }

    const current = new Set(getTabFilterCheckboxGroupSelection(field, currentValue));

    if (checked) {
        current.add(value);
    } else {
        current.delete(value);
    }

    return (field.options ?? [])
        .map((option) => String(option.value))
        .filter((optionValue) => current.has(optionValue));
}

export function getTabFilterFieldPlaceholder(field: ServiceFilterField): string | undefined {
    if (field.placeholder) {
        return field.placeholder;
    }

    if ((field.type === 'text' || field.type === 'number') && field.description) {
        return field.description;
    }

    return undefined;
}

export function shouldShowTabFilterDescriptionBelow(field: ServiceFilterField): boolean {
    if (!field.description) {
        return false;
    }

    if (field.type === 'boolean' || field.type === 'checkbox') {
        return false;
    }

    if ((field.type === 'text' || field.type === 'number') && !field.placeholder) {
        return false;
    }

    return true;
}

export function isTabFilterFieldDisabled(
    field: ServiceFilterField,
    feed: BrowseFormData['feed'],
    serviceFilters: Record<string, unknown>,
): boolean {
    if (feed !== 'local') {
        return false;
    }

    if (field.uiKey !== 'reaction') {
        return false;
    }

    return String(serviceFilters.reaction_mode ?? 'any') !== 'types';
}
