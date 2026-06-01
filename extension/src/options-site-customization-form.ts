import type { MediaCleanerStrategy, MediaRewriteRule } from './site-customizations';

export type CustomizationTab = 'matchRules' | 'widget' | 'referrerCleaner' | 'mediaCleaner';

export type SiteCustomizationForm = {
    enabled: boolean;
    domain: string;
    matchRules: string[];
    widgetMinImageWidthText: string;
    referrerCleanerQueryParamsText: string;
    mediaCleanerQueryParamsText: string;
    mediaCleanerRewriteRules: MediaRewriteRule[];
    mediaCleanerStrategies: MediaCleanerStrategy[];
};

export const CUSTOMIZATION_TABS: Array<{ id: CustomizationTab; label: string }> = [
    { id: 'matchRules', label: 'Match rules' },
    { id: 'widget', label: 'Widget' },
    { id: 'referrerCleaner', label: 'Referrer cleaner' },
    { id: 'mediaCleaner', label: 'Media cleaner' },
];
