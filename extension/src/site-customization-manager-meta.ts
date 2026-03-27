import type {
    CustomizationTab,
    SiteCustomizationForm,
} from './options-site-customization-form';
import type { MediaCleanerStrategy } from './site-customizations';

export const CUSTOMIZATION_TAB_META: Record<CustomizationTab, { title: string; description: string }> = {
    matchRules: {
        title: 'Match rules',
        description: 'Gate widget rendering to URL patterns on this page host. Leave this empty to keep the site permissive.',
    },
    referrerCleaner: {
        title: 'Referrer cleaner',
        description: 'Strip unstable query params from page and anchor URLs before Atlas checks or stores them.',
    },
    mediaCleaner: {
        title: 'Media cleaner',
        description: 'Normalize media URLs with named strategies, query stripping, and optional rewrite rules.',
    },
};

export const MEDIA_CLEANER_STRATEGY_META: Record<MediaCleanerStrategy, { title: string; description: string }> = {
    civitaiCanonical: {
        title: 'Civitai canonical',
        description: 'Normalize Civitai asset variants to the stable URL Atlas stores for matching and reactions.',
    },
};

function countListEntries(value: string): number {
    return value
        .split(/[,\n]+/)
        .map((entry) => entry.trim())
        .filter((entry) => entry !== '').length;
}

function formatCount(count: number, singular: string, plural: string = `${singular}s`): string {
    return `${count} ${count === 1 ? singular : plural}`;
}

export function describeCustomization(customization: SiteCustomizationForm): string[] {
    const summary = [
        customization.enabled ? 'Enabled' : 'Disabled',
        customization.matchRules.length === 0
            ? 'Permissive matching'
            : formatCount(customization.matchRules.length, 'match rule'),
    ];

    const referrerParamCount = countListEntries(customization.referrerCleanerQueryParamsText);
    if (referrerParamCount > 0) {
        summary.push(formatCount(referrerParamCount, 'referrer param'));
    }

    const mediaStrategyCount = customization.mediaCleanerStrategies.length;
    if (mediaStrategyCount > 0) {
        summary.push(formatCount(mediaStrategyCount, 'media strategy', 'media strategies'));
    }

    const mediaParamCount = countListEntries(customization.mediaCleanerQueryParamsText);
    if (mediaParamCount > 0) {
        summary.push(formatCount(mediaParamCount, 'media param'));
    }

    const rewriteRuleCount = customization.mediaCleanerRewriteRules.length;
    if (rewriteRuleCount > 0) {
        summary.push(formatCount(rewriteRuleCount, 'rewrite rule'));
    }

    return summary;
}
