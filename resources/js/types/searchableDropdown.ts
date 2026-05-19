export type SearchableDropdownValue = string | number | boolean | null;

export type SearchableDropdownOption = {
    label: string;
    value: SearchableDropdownValue;
    disabled?: boolean;
    badge?: string | null;
    badgeVariant?: 'danger' | 'warning' | 'neutral';
};

export type SearchableDropdownGroup = {
    label?: string;
    options: SearchableDropdownOption[];
};
