import type { LimitOption, PeriodOption, SortOption, ContainerOption } from '@/types/browse';

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

export const MAX_AUTOCYCLE_ATTEMPTS = 100;
export const AUTOCYCLE_DELAY = 100;

// Container options
export const CONTAINER_OPTIONS: ContainerOption[] = [
    { value: 'Images', label: 'Images' },
    { value: 'Users', label: 'Users' },
    { value: 'Models', label: 'Models' },
    { value: 'Posts', label: 'Posts' },
    { value: 'Collections', label: 'Collections' },
];

// Limit options
export const LIMIT_OPTIONS: LimitOption[] = [
    { value: 20, label: '20' },
    { value: 40, label: '40' },
    { value: 60, label: '60' },
];
