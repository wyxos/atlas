import { describe, expect, it } from 'vitest';
import {
    createLocalSourceOptions,
    formatLocalSourceSelectionLabel,
    isLocalSourceSelected,
    normalizeLocalSourceSelection,
    normalizeLocalSourceOptions,
    toggleLocalSourceSelection,
} from './localSourceSelection';

const options = [
    { label: 'All', value: 'all' },
    { label: 'CivitAI', value: 'CivitAI' },
    { label: 'Wallhaven', value: 'Wallhaven' },
];

describe('localSourceSelection', () => {
    it('normalizes missing and all selections to the all sentinel', () => {
        expect(normalizeLocalSourceSelection(undefined)).toEqual(['all']);
        expect(normalizeLocalSourceSelection([])).toEqual(['all']);
        expect(normalizeLocalSourceSelection(['CivitAI', 'all'])).toEqual(['all']);
    });

    it('toggles individual sources without keeping all selected', () => {
        const selected = toggleLocalSourceSelection('all', 'CivitAI', true);

        expect(selected).toEqual(['CivitAI']);
        expect(toggleLocalSourceSelection(selected, 'Wallhaven', true)).toEqual(['CivitAI', 'Wallhaven']);
        expect(toggleLocalSourceSelection(['CivitAI'], 'CivitAI', false)).toEqual(['all']);
        expect(toggleLocalSourceSelection(['CivitAI'], 'all', true)).toEqual(['all']);
    });

    it('reports selected state and compact labels for multiple sources', () => {
        expect(isLocalSourceSelected(['CivitAI', 'Wallhaven'], 'CivitAI')).toBe(true);
        expect(isLocalSourceSelected(['all'], 'CivitAI')).toBe(false);
        expect(isLocalSourceSelected(['all'], 'all')).toBe(true);
        expect(formatLocalSourceSelectionLabel(['CivitAI', 'Wallhaven'], options)).toBe('2 sources');
        expect(formatLocalSourceSelectionLabel(['CivitAI'], options)).toBe('CivitAI');
    });

    it('labels local source values as library in source dropdowns', () => {
        expect(createLocalSourceOptions(['all', 'local', 'Spotify'])).toEqual([
            { label: 'All', value: 'all' },
            { label: 'Library', value: 'local' },
            { label: 'Spotify', value: 'Spotify' },
        ]);
        expect(normalizeLocalSourceOptions([{ label: 'Local', value: 'local' }])).toEqual([
            { label: 'Library', value: 'local' },
        ]);
    });
});
