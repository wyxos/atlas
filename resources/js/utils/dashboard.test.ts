import { describe, expect, it } from 'vitest';
import { createDashboardCoverage } from './dashboard';
import type { DashboardMetrics } from '@/types/dashboard';

function metrics(overrides: Partial<DashboardMetrics['files']> = {}): DashboardMetrics {
    return {
        files: {
            total: 10,
            downloaded: 0,
            stored: 0,
            records_only: 0,
            local: 0,
            non_local: 0,
            local_available: 0,
            non_local_available: 0,
            file_types: {
                image: 0,
                video: 0,
                audio: 0,
                other: 0,
            },
            reactions: {
                love: 0,
                like: 0,
                funny: 0,
            },
            reacted: 3,
            unreacted: 5,
            blacklisted: 2,
            blacklisted_manual: 1,
            blacklisted_feed_removed: 1,
            blacklisted_manual_in_feed: 0,
            blacklisted_auto_in_feed: 0,
            auto_blacklisted: 0,
            not_found: 1,
            previewed_not_blacklisted: 5,
            unpreviewed_not_blacklisted: 3,
            unreacted_not_blacklisted: 4,
            unreacted_previewed_not_blacklisted: 2,
            unreacted_unpreviewed_not_blacklisted: 2,
            ...overrides,
        },
        containers: {
            total: 0,
            blacklisted: 0,
            top_downloads: [],
            top_favorites: [],
            top_blacklisted: [],
        },
    };
}

describe('dashboard coverage metrics', () => {
    it('summarizes preview and reaction state against non-blacklisted files', () => {
        const coverage = createDashboardCoverage(metrics());

        expect(coverage.total).toBe(8);
        expect(coverage.previewed).toBe(5);
        expect(coverage.distributions[0]).toMatchObject({
            label: 'Preview state',
            total: 8,
            segments: [
                { key: 'previewed', label: 'Previewed', value: 5 },
                { key: 'not-previewed', label: 'Not previewed', value: 3 },
            ],
        });
        expect(coverage.distributions[1]).toMatchObject({
            label: 'Reaction state',
            total: 8,
            segments: [
                { key: 'reacted', label: 'Reacted', value: 3 },
                { key: 'unreacted', label: 'Unreacted', value: 5 },
            ],
        });
    });
});
