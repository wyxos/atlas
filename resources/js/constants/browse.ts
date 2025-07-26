import type { SortOption, PeriodOption } from '@/types/browse';

// Sort options based on CivitAI API specification
export const SORT_OPTIONS: SortOption[] = [
    { value: 'Most Reactions', label: 'Most Reactions' },
    { value: 'Most Comments', label: 'Most Comments' },
    { value: 'Newest', label: 'Newest' },
];

// Period options based on CivitAI API
export const PERIOD_OPTIONS: PeriodOption[] = [
    { value: 'AllTime', label: 'All Time' },
    { value: 'Year', label: 'Year' },
    { value: 'Month', label: 'Month' },
    { value: 'Week', label: 'Week' },
    { value: 'Day', label: 'Day' },
];

export const MAX_AUTOCYCLE_ATTEMPTS = 10;
export const AUTOCYCLE_DELAY = 100;
