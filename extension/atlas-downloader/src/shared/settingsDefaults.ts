import type {DomainIncludeRule} from './domainIncludeRules';

export const DEFAULT_DOMAIN_INCLUDE_RULES: DomainIncludeRule[] = [
    {
        domain: 'deviantart.com',
        patterns: [
            '.*\\/art\\/.*',
            '.*deviationid=.*',
            '.*\\/v1\\/fill\\/.*strp\\/.*', '.*images-wixmp.*'
        ],
    },
];

export const DEFAULT_DOMAIN_INCLUDE_RULES_TEXT = JSON.stringify(DEFAULT_DOMAIN_INCLUDE_RULES);
