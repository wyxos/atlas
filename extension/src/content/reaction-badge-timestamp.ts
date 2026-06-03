import { Ban, Download } from 'lucide-vue-next';
import { formatMatchTimestamp } from './match-timestamp';
import type { BadgeMatchResult } from './reaction-check-queue';
import type { BadgeTimestampDisplay } from './reaction-badge-view';

export function resolveBadgeTimestampDisplay(matchResult: BadgeMatchResult): BadgeTimestampDisplay {
    const blacklistedAt = formatMatchTimestamp(matchResult.blacklistedAt);
    if (blacklistedAt) {
        return { icon: Ban, text: `- ${blacklistedAt}` };
    }

    const downloadedAt = formatMatchTimestamp(matchResult.downloadedAt);
    if (downloadedAt) {
        return { icon: Download, text: `- ${downloadedAt}` };
    }

    return null;
}
