import { CIVITAI_SUPPORTED_DOMAINS } from './civitai-domains';
import { DEFAULT_MATCH_RULES } from './match-rules';
import type { MediaCleanerStrategy, SiteCustomization } from './site-customizations';

function createDefaultSiteCustomization(
    domain: string,
    options: Partial<Pick<SiteCustomization, 'matchRules' | 'mediaCleaner'>> = {},
): SiteCustomization {
    return {
        enabled: true,
        domain,
        matchRules: options.matchRules ?? [],
        referrerCleaner: {
            stripQueryParams: [],
        },
        mediaCleaner: options.mediaCleaner ?? {
            stripQueryParams: [],
            rewriteRules: [],
            strategies: [],
        },
    };
}

export const BUILT_IN_SITE_CUSTOMIZATIONS: SiteCustomization[] = [
    createDefaultSiteCustomization('deviantart.com', {
        matchRules: DEFAULT_MATCH_RULES[0]?.regexes ?? [],
    }),
    ...CIVITAI_SUPPORTED_DOMAINS.map((domain) => createDefaultSiteCustomization(domain, {
        mediaCleaner: {
            stripQueryParams: [],
            rewriteRules: [],
            strategies: ['civitaiCanonical'] as MediaCleanerStrategy[],
        },
    })),
];
