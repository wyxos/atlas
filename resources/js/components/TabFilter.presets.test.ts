import { describe, expect, it } from 'vitest';
import { FEED_REMOVED_MAX_VISIBLE_PREVIEW_COUNT, FEED_REMOVED_PREVIEW_COUNT } from '@/lib/feedModeration';
import { LOCAL_TAB_FILTER_PRESET_GROUPS, LOCAL_TAB_FILTER_PRESETS } from '@/utils/tabFilter';

describe('TabFilter local presets', () => {
    it('provides random newest and oldest presets for each local reaction view', () => {
        const reactionsGroup = LOCAL_TAB_FILTER_PRESET_GROUPS.find((group) => group.label === 'Reactions');

        expect(reactionsGroup?.presets.map((preset) => preset.label)).toEqual([
            'Reacted (Random)',
            'Reacted (Newest)',
            'Reacted (Oldest)',
            'Favorite (Random)',
            'Favorite (Newest)',
            'Favorite (Oldest)',
            'Likes (Random)',
            'Likes (Newest)',
            'Likes (Oldest)',
            'Funny (Random)',
            'Funny (Newest)',
            'Funny (Oldest)',
        ]);

        expect(LOCAL_TAB_FILTER_PRESETS.find((preset) => preset.value === 'like_random')?.filters).toMatchObject({
            reaction_mode: 'types',
            reaction: ['like'],
            sort: 'random',
        });
        expect(LOCAL_TAB_FILTER_PRESETS.find((preset) => preset.value === 'like_newest')?.filters).toMatchObject({
            reaction_mode: 'types',
            reaction: ['like'],
            sort: 'reaction_at',
        });
        expect(LOCAL_TAB_FILTER_PRESETS.find((preset) => preset.value === 'like_oldest')?.filters).toMatchObject({
            reaction_mode: 'types',
            reaction: ['like'],
            sort: 'reaction_at_asc',
        });
    });

    it('provides newest and oldest presets for blacklisted Library', () => {
        const moderationGroup = LOCAL_TAB_FILTER_PRESET_GROUPS.find((group) => group.label === 'Moderation');

        expect(moderationGroup?.presets.map((preset) => preset.label)).toEqual([
            'Blacklisted (Newest)',
            'Blacklisted (Oldest)',
            'Out of Feed (Newest)',
            'Out of Feed (Oldest)',
        ]);
        expect(LOCAL_TAB_FILTER_PRESETS.find((preset) => preset.value === 'blacklisted_any')?.filters).toMatchObject({
            blacklisted: 'yes',
            max_previewed_count: FEED_REMOVED_MAX_VISIBLE_PREVIEW_COUNT,
            sort: 'blacklisted_at',
        });
        expect(LOCAL_TAB_FILTER_PRESETS.find((preset) => preset.value === 'blacklisted_oldest')?.filters).toMatchObject({
            blacklisted: 'yes',
            max_previewed_count: FEED_REMOVED_MAX_VISIBLE_PREVIEW_COUNT,
            sort: 'blacklisted_at_asc',
        });
        expect(LOCAL_TAB_FILTER_PRESETS.find((preset) => preset.value === 'out_of_feed_newest')?.filters).toMatchObject({
            blacklisted: 'yes',
            max_previewed_count: null,
            min_previewed_count: FEED_REMOVED_PREVIEW_COUNT,
            sort: 'updated_at',
        });
        expect(LOCAL_TAB_FILTER_PRESETS.find((preset) => preset.value === 'out_of_feed_oldest')?.filters).toMatchObject({
            blacklisted: 'yes',
            max_previewed_count: null,
            min_previewed_count: FEED_REMOVED_PREVIEW_COUNT,
            sort: 'updated_at_asc',
        });
    });

    it('provides not found anomaly presets', () => {
        const anomaliesGroup = LOCAL_TAB_FILTER_PRESET_GROUPS.find((group) => group.label === 'Anomalies');

        expect(anomaliesGroup?.presets.map((preset) => preset.label)).toEqual([
            'Not Found',
            'Not Found (Reacted)',
            'Saved Blacklisted',
        ]);
        expect(LOCAL_TAB_FILTER_PRESETS.find((preset) => preset.value === 'not_found')?.filters).toMatchObject({
            not_found: 'yes',
            reaction_mode: 'any',
            blacklisted: 'no',
            sort: 'updated_at',
        });
        expect(LOCAL_TAB_FILTER_PRESETS.find((preset) => preset.value === 'not_found_reacted')?.filters).toMatchObject({
            not_found: 'yes',
            reaction_mode: 'reacted',
            blacklisted: 'no',
            sort: 'reaction_at',
        });
    });

    it('provides an imported-files local preset', () => {
        const commonGroup = LOCAL_TAB_FILTER_PRESET_GROUPS.find((group) => group.label === 'Common');

        expect(commonGroup?.presets.map((preset) => preset.label)).toEqual([
            'All',
            'Inbox (Fresh)',
            'Imported Files',
        ]);
        expect(LOCAL_TAB_FILTER_PRESETS.find((preset) => preset.value === 'imported_files')?.filters).toMatchObject({
            imported: 'yes',
            reaction_mode: 'any',
            sort: 'updated_at',
        });
    });
});
