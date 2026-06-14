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
    widget: {
        title: 'Widget',
        description: 'Tune when the Atlas widget appears on media for this page host.',
    },
    referrerCleaner: {
        title: 'Referrer cleaner',
        description: 'Strip unstable query params from page and anchor URLs before Atlas checks or stores them.',
    },
    mediaCleaner: {
        title: 'Media cleaner',
        description: 'Atlas compares and saves media by URL. For example, if width is removed, image.jpg?width=450 and image.jpg?width=900 both compare as image.jpg, so duplicate checks, reactions, and saved records stay together.',
    },
};

export const MEDIA_CLEANER_STRATEGY_META: Record<MediaCleanerStrategy, { title: string; description: string }> = {
    civitaiCanonical: {
        title: 'Civitai canonical',
        description: 'Use on CivitAI pages so image and model variants save as one stable URL.',
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

    const widgetMinImageWidth = customization.widgetMinImageWidthText.trim();
    if (widgetMinImageWidth !== '') {
        summary.push(`Min ${widgetMinImageWidth}px`);
    }

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
