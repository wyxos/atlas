import { describe, expect, it } from 'vitest';
import { FEED_REMOVED_MAX_VISIBLE_PREVIEW_COUNT } from '@/lib/feedModeration';
import { getTabFilterLimitOptions, LOCAL_TAB_FILTER_PRESET_GROUPS } from '@/utils/tabFilter';

describe('getTabFilterLimitOptions', () => {
    it('uses schema-defined local limit options when present', () => {
        expect(getTabFilterLimitOptions('local', {
            fields: [
                {
                    uiKey: 'limit',
                    serviceKey: 'limit',
                    type: 'number',
                    label: 'Limit',
                    options: [
                        { label: '20', value: 20 },
                        { label: '200', value: 200 },
                        { label: '250', value: 250 },
                    ],
                },
            ],
        })).toEqual(['20', '200', '250']);
    });

    it('falls back to online defaults when schema options are absent', () => {
        expect(getTabFilterLimitOptions('online', null)).toEqual(['20', '40', '60', '80', '100', '200']);
    });
});

describe('LOCAL_TAB_FILTER_PRESET_GROUPS', () => {
    it('keeps fully removed blacklisted items out of the blacklist preset', () => {
        const blacklistedPreset = LOCAL_TAB_FILTER_PRESET_GROUPS
            .flatMap((group) => group.presets)
            .find((preset) => preset.value === 'blacklisted_any');

        expect(blacklistedPreset?.filters.max_previewed_count).toBe(FEED_REMOVED_MAX_VISIBLE_PREVIEW_COUNT);
    });
});
