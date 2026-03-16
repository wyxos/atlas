import type { MediaCleanerStrategy, MediaRewriteRule } from './site-customizations';

export type CustomizationTab = 'matchRules' | 'referrerCleaner' | 'mediaCleaner';

export type SiteCustomizationForm = {
    domain: string;
    matchRules: string[];
    referrerCleanerQueryParamsText: string;
    mediaCleanerQueryParamsText: string;
    mediaCleanerRewriteRules: MediaRewriteRule[];
    mediaCleanerStrategies: MediaCleanerStrategy[];
};

export const CUSTOMIZATION_TABS: Array<{ id: CustomizationTab; label: string }> = [
    { id: 'matchRules', label: 'Match rules' },
    { id: 'referrerCleaner', label: 'Referrer cleaner' },
    { id: 'mediaCleaner', label: 'Media cleaner' },
];
