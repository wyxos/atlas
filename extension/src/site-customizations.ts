import {
    DEFAULT_MATCH_RULES,
    hostMatchesRuleDomain,
    normalizeMatchRules,
    parseStoredMatchRules,
    validateDomainRule,
    validateRegexPattern,
    type UrlMatchRule,
} from './match-rules';
import {
    normalizeReferrerQueryParams,
    parseStoredReferrerQueryParamsToStripByDomain,
    validateReferrerQueryParam,
} from './referrer-cleanup';

export const MEDIA_CLEANER_STRATEGIES = ['civitaiCanonical'] as const;
export const SITE_CUSTOMIZATIONS_EXPORT_VERSION = 1 as const;

export type MediaCleanerStrategy = typeof MEDIA_CLEANER_STRATEGIES[number];

export type MediaRewriteRule = {
    pattern: string;
    replace: string;
};

export type ReferrerCleanerConfig = {
    stripQueryParams: string[];
};

export type MediaCleanerConfig = {
    stripQueryParams: string[];
    rewriteRules: MediaRewriteRule[];
    strategies: MediaCleanerStrategy[];
};

export type SiteCustomization = {
    enabled: boolean;
    domain: string;
    matchRules: string[];
    referrerCleaner: ReferrerCleanerConfig;
    mediaCleaner: MediaCleanerConfig;
};

export type SiteCustomizationsExport = {
    version: typeof SITE_CUSTOMIZATIONS_EXPORT_VERSION;
    siteCustomizations: SiteCustomization[];
};

type ParsedSiteCustomizationEntry = Partial<{
    enabled: unknown;
    domain: unknown;
    matchRules: unknown;
    referrerCleaner: unknown;
    mediaCleaner: unknown;
}>;

const BUILT_IN_SITE_CUSTOMIZATIONS: SiteCustomization[] = [
    {
        enabled: true,
        domain: 'deviantart.com',
        matchRules: DEFAULT_MATCH_RULES[0]?.regexes ?? [],
        referrerCleaner: {
            stripQueryParams: [],
        },
        mediaCleaner: {
            stripQueryParams: [],
            rewriteRules: [],
            strategies: [],
        },
    },
    {
        enabled: true,
        domain: 'civitai.com',
        matchRules: [],
        referrerCleaner: {
            stripQueryParams: [],
        },
        mediaCleaner: {
            stripQueryParams: [],
            rewriteRules: [],
            strategies: ['civitaiCanonical'],
        },
    },
];

function normalizeSiteCustomizationDomain(input: string): string {
    const trimmed = input.trim().toLowerCase();
    if (trimmed === '') {
        return '';
    }

    if (trimmed.includes('://')) {
        try {
            return new URL(trimmed).hostname.toLowerCase();
        } catch {
            return '';
        }
    }

    return trimmed.replace(/^\.+/, '').replace(/\.+$/, '');
}

function normalizeMediaRewriteRules(rules: MediaRewriteRule[]): MediaRewriteRule[] {
    const seen = new Set<string>();

    return rules
        .map((rule) => ({
            pattern: rule.pattern.trim(),
            replace: typeof rule.replace === 'string' ? rule.replace : '',
        }))
        .filter((rule) => rule.pattern !== '')
        .filter((rule) => {
            const key = `${rule.pattern}\u0000${rule.replace}`;
            if (seen.has(key)) {
                return false;
            }

            seen.add(key);
            return true;
        });
}

function normalizeMediaCleanerStrategies(values: MediaCleanerStrategy[]): MediaCleanerStrategy[] {
    const seen = new Set<MediaCleanerStrategy>();
    const strategies: MediaCleanerStrategy[] = [];

    for (const value of values) {
        if (!MEDIA_CLEANER_STRATEGIES.includes(value) || seen.has(value)) {
            continue;
        }

        seen.add(value);
        strategies.push(value);
    }

    return strategies;
}

function normalizeSiteCustomizationEnabled(value: boolean | undefined): boolean {
    return value !== false;
}

function normalizeSiteCustomization(customization: SiteCustomization): SiteCustomization {
    return {
        enabled: normalizeSiteCustomizationEnabled(customization.enabled),
        domain: normalizeSiteCustomizationDomain(customization.domain),
        matchRules: normalizeMatchRules([{
            domain: customization.domain,
            regexes: customization.matchRules,
        }])[0]?.regexes ?? [],
        referrerCleaner: {
            stripQueryParams: normalizeReferrerQueryParams(customization.referrerCleaner.stripQueryParams),
        },
        mediaCleaner: {
            stripQueryParams: normalizeReferrerQueryParams(customization.mediaCleaner.stripQueryParams),
            rewriteRules: normalizeMediaRewriteRules(customization.mediaCleaner.rewriteRules),
            strategies: normalizeMediaCleanerStrategies(customization.mediaCleaner.strategies),
        },
    };
}

function parseMediaCleanerStrategies(value: unknown): MediaCleanerStrategy[] {
    if (!Array.isArray(value)) {
        return [];
    }

    return normalizeMediaCleanerStrategies(
        value.filter((entry): entry is MediaCleanerStrategy => typeof entry === 'string') as MediaCleanerStrategy[],
    );
}

function parseMediaRewriteRules(value: unknown): MediaRewriteRule[] {
    if (!Array.isArray(value)) {
        return [];
    }

    return normalizeMediaRewriteRules(
        value
            .map((entry): MediaRewriteRule | null => {
                if (!entry || typeof entry !== 'object') {
                    return null;
                }

                const row = entry as Record<string, unknown>;
                if (typeof row.pattern !== 'string' || typeof row.replace !== 'string') {
                    return null;
                }

                return {
                    pattern: row.pattern,
                    replace: row.replace,
                };
            })
            .filter((entry): entry is MediaRewriteRule => entry !== null),
    );
}

function parseImportStringArray(value: unknown, label: string): string[] {
    if (value === undefined) {
        return [];
    }

    if (!Array.isArray(value)) {
        throw new Error(`${label} must be an array of strings.`);
    }

    const values: string[] = [];
    for (const entry of value) {
        if (typeof entry !== 'string') {
            throw new Error(`${label} must be an array of strings.`);
        }

        values.push(entry);
    }

    return values;
}

function parseImportMediaRewriteRules(value: unknown, domain: string): MediaRewriteRule[] {
    if (value === undefined) {
        return [];
    }

    if (!Array.isArray(value)) {
        throw new Error(`Domain "${domain}" mediaCleaner.rewriteRules must be an array.`);
    }

    return value.map((entry) => {
        if (!entry || typeof entry !== 'object') {
            throw new Error(`Domain "${domain}" has an invalid media rewrite rule.`);
        }

        const row = entry as Record<string, unknown>;
        if (typeof row.pattern !== 'string' || typeof row.replace !== 'string') {
            throw new Error(`Domain "${domain}" has an invalid media rewrite rule.`);
        }

        return {
            pattern: row.pattern,
            replace: row.replace,
        };
    });
}

function parseSiteCustomizationImportEntry(entry: unknown, index: number): SiteCustomization {
    if (!entry || typeof entry !== 'object') {
        throw new Error(`Customization at index ${index} must be an object.`);
    }

    const row = entry as Record<string, unknown>;
    if (typeof row.domain !== 'string') {
        throw new Error(`Customization at index ${index} must include a domain string.`);
    }
    if (row.enabled !== undefined && typeof row.enabled !== 'boolean') {
        throw new Error(`Domain "${row.domain}" enabled must be a boolean.`);
    }

    const domain = normalizeSiteCustomizationDomain(row.domain);
    const referrerCleaner = row.referrerCleaner;
    const mediaCleaner = row.mediaCleaner;
    if (referrerCleaner !== undefined && (!referrerCleaner || typeof referrerCleaner !== 'object')) {
        throw new Error(`Domain "${row.domain}" referrerCleaner must be an object.`);
    }

    if (mediaCleaner !== undefined && (!mediaCleaner || typeof mediaCleaner !== 'object')) {
        throw new Error(`Domain "${row.domain}" mediaCleaner must be an object.`);
    }

    const parsed = normalizeSiteCustomization({
        enabled: row.enabled !== false,
        domain,
        matchRules: parseImportStringArray(row.matchRules, `Domain "${row.domain}" matchRules`),
        referrerCleaner: {
            stripQueryParams: parseImportStringArray(
                (referrerCleaner as Record<string, unknown> | undefined)?.stripQueryParams,
                `Domain "${row.domain}" referrerCleaner.stripQueryParams`,
            ),
        },
        mediaCleaner: {
            stripQueryParams: parseImportStringArray(
                (mediaCleaner as Record<string, unknown> | undefined)?.stripQueryParams,
                `Domain "${row.domain}" mediaCleaner.stripQueryParams`,
            ),
            rewriteRules: parseImportMediaRewriteRules(
                (mediaCleaner as Record<string, unknown> | undefined)?.rewriteRules,
                row.domain,
            ),
            strategies: parseImportStringArray(
                (mediaCleaner as Record<string, unknown> | undefined)?.strategies,
                `Domain "${row.domain}" mediaCleaner.strategies`,
            ) as MediaCleanerStrategy[],
        },
    });

    if (parsed.domain === '') {
        throw new Error(`Customization at index ${index} must include a valid domain.`);
    }

    return parsed;
}

export function createEmptySiteCustomization(domain: string = ''): SiteCustomization {
    return {
        enabled: true,
        domain: normalizeSiteCustomizationDomain(domain),
        matchRules: [],
        referrerCleaner: {
            stripQueryParams: [],
        },
        mediaCleaner: {
            stripQueryParams: [],
            rewriteRules: [],
            strategies: [],
        },
    };
}

export function getDefaultSiteCustomizations(): SiteCustomization[] {
    return BUILT_IN_SITE_CUSTOMIZATIONS.map((customization) => ({
        enabled: customization.enabled,
        domain: customization.domain,
        matchRules: [...customization.matchRules],
        referrerCleaner: {
            stripQueryParams: [...customization.referrerCleaner.stripQueryParams],
        },
        mediaCleaner: {
            stripQueryParams: [...customization.mediaCleaner.stripQueryParams],
            rewriteRules: customization.mediaCleaner.rewriteRules.map((rule) => ({ ...rule })),
            strategies: [...customization.mediaCleaner.strategies],
        },
    }));
}

export function normalizeSiteCustomizations(customizations: SiteCustomization[]): SiteCustomization[] {
    const normalized = customizations
        .map((customization) => normalizeSiteCustomization(customization))
        .filter((customization) => customization.domain !== '');

    const byDomain = new Map<string, SiteCustomization>();
    for (const customization of normalized) {
        byDomain.set(customization.domain, customization);
    }

    return Array.from(byDomain.values()).sort((left, right) => left.domain.localeCompare(right.domain));
}

function parseStoredSiteCustomization(entry: ParsedSiteCustomizationEntry): SiteCustomization | null {
    const domain = typeof entry.domain === 'string' ? entry.domain : '';
    if (normalizeSiteCustomizationDomain(domain) === '') {
        return null;
    }

    const matchRules = Array.isArray(entry.matchRules)
        ? entry.matchRules.filter((rule): rule is string => typeof rule === 'string')
        : [];

    const referrerCleanerRow = entry.referrerCleaner && typeof entry.referrerCleaner === 'object'
        ? entry.referrerCleaner as Record<string, unknown>
        : {};

    const mediaCleanerRow = entry.mediaCleaner && typeof entry.mediaCleaner === 'object'
        ? entry.mediaCleaner as Record<string, unknown>
        : {};

    return {
        enabled: normalizeSiteCustomizationEnabled(entry.enabled === false ? false : true),
        domain,
        matchRules,
        referrerCleaner: {
            stripQueryParams: Array.isArray(referrerCleanerRow.stripQueryParams)
                ? referrerCleanerRow.stripQueryParams.filter((param): param is string => typeof param === 'string')
                : [],
        },
        mediaCleaner: {
            stripQueryParams: Array.isArray(mediaCleanerRow.stripQueryParams)
                ? mediaCleanerRow.stripQueryParams.filter((param): param is string => typeof param === 'string')
                : [],
            rewriteRules: parseMediaRewriteRules(mediaCleanerRow.rewriteRules),
            strategies: parseMediaCleanerStrategies(mediaCleanerRow.strategies),
        },
    };
}

export function parseStoredSiteCustomizations(value: unknown): SiteCustomization[] {
    if (!Array.isArray(value)) {
        return [];
    }

    return normalizeSiteCustomizations(
        value
            .map((entry) => parseStoredSiteCustomization(entry as ParsedSiteCustomizationEntry))
            .filter((entry): entry is SiteCustomization => entry !== null),
    );
}

export function deriveSiteCustomizationsFromLegacyStorage(
    storedMatchRules: unknown,
    storedReferrerQueryParamsToStripByDomain: unknown,
): SiteCustomization[] {
    const merged = new Map<string, SiteCustomization>(
        getDefaultSiteCustomizations().map((customization) => [customization.domain, customization]),
    );

    for (const rule of parseStoredMatchRules(storedMatchRules)) {
        const existing = merged.get(rule.domain) ?? createEmptySiteCustomization(rule.domain);
        merged.set(rule.domain, {
            ...existing,
            enabled: existing.enabled,
            domain: rule.domain,
            matchRules: [...rule.regexes],
        });
    }

    for (const [domain, stripQueryParams] of Object.entries(
        parseStoredReferrerQueryParamsToStripByDomain(storedReferrerQueryParamsToStripByDomain),
    )) {
        const existing = merged.get(domain) ?? createEmptySiteCustomization(domain);
        merged.set(domain, {
            ...existing,
            enabled: existing.enabled,
            domain,
            referrerCleaner: {
                stripQueryParams: [...stripQueryParams],
            },
        });
    }

    return normalizeSiteCustomizations(Array.from(merged.values()));
}

export function resolveStoredSiteCustomizationForHostname(
    customizations: SiteCustomization[],
    hostname: string,
): SiteCustomization | null {
    const normalizedHostname = hostname.trim().toLowerCase();
    if (normalizedHostname === '') {
        return null;
    }

    const matches = customizations
        .filter((customization) => hostMatchesRuleDomain(normalizedHostname, customization.domain))
        .sort((left, right) => right.domain.length - left.domain.length || left.domain.localeCompare(right.domain));

    return matches[0] ?? null;
}

export function resolveSiteCustomizationForHostname(
    customizations: SiteCustomization[],
    hostname: string,
): SiteCustomization | null {
    const customization = resolveStoredSiteCustomizationForHostname(customizations, hostname);
    if (customization === null || customization.enabled === false) {
        return null;
    }

    return customization;
}

export function exportSiteCustomizationsPayload(siteCustomizations: SiteCustomization[]): SiteCustomizationsExport {
    return {
        version: SITE_CUSTOMIZATIONS_EXPORT_VERSION,
        siteCustomizations: normalizeSiteCustomizations(siteCustomizations),
    };
}

export function validateSiteCustomizations(siteCustomizations: SiteCustomization[]): string | null {
    const seen = new Set<string>();

    for (const customization of siteCustomizations) {
        const domainError = validateDomainRule(customization.domain);
        if (domainError !== null) {
            return domainError;
        }

        if (seen.has(customization.domain)) {
            return `Domain "${customization.domain}" already exists.`;
        }

        seen.add(customization.domain);

        for (const regex of customization.matchRules) {
            const regexError = validateRegexPattern(regex);
            if (regexError !== null) {
                return regexError;
            }
        }

        for (const queryParam of customization.referrerCleaner.stripQueryParams) {
            const queryParamError = validateReferrerQueryParam(queryParam);
            if (queryParamError !== null) {
                return queryParamError;
            }
        }

        for (const queryParam of customization.mediaCleaner.stripQueryParams) {
            const queryParamError = validateReferrerQueryParam(queryParam);
            if (queryParamError !== null) {
                return queryParamError;
            }
        }

        for (const strategy of customization.mediaCleaner.strategies) {
            if (!MEDIA_CLEANER_STRATEGIES.includes(strategy)) {
                return `Invalid media cleaner strategy "${strategy}".`;
            }
        }

        for (const rewriteRule of customization.mediaCleaner.rewriteRules) {
            if (rewriteRule.pattern.trim() === '') {
                return `Domain "${customization.domain}" has an empty media rewrite pattern.`;
            }

            const regexError = validateRegexPattern(rewriteRule.pattern);
            if (regexError !== null) {
                return regexError;
            }

            if (typeof rewriteRule.replace !== 'string') {
                return `Domain "${customization.domain}" has an invalid media rewrite replacement.`;
            }
        }
    }

    return null;
}

export function parseSiteCustomizationsImportJson(input: string): SiteCustomization[] {
    let parsed: unknown;

    try {
        parsed = JSON.parse(input);
    } catch {
        throw new Error('Invalid customization JSON.');
    }

    if (!parsed || typeof parsed !== 'object') {
        throw new Error('Invalid customization payload.');
    }

    const payload = parsed as Partial<SiteCustomizationsExport>;
    if (payload.version !== SITE_CUSTOMIZATIONS_EXPORT_VERSION) {
        throw new Error(`Unsupported customization export version "${String(payload.version ?? '')}".`);
    }

    if (!Array.isArray(payload.siteCustomizations)) {
        throw new Error('Customization payload must include a siteCustomizations array.');
    }

    const siteCustomizations = payload.siteCustomizations.map((entry, index) =>
        parseSiteCustomizationImportEntry(entry, index),
    );
    const validationError = validateSiteCustomizations(siteCustomizations);
    if (validationError !== null) {
        throw new Error(validationError);
    }

    return siteCustomizations;
}

export function createCustomizationMatchRules(customization: SiteCustomization): UrlMatchRule[] {
    if (customization.matchRules.length === 0) {
        return [];
    }

    return [{
        domain: customization.domain,
        regexes: [...customization.matchRules],
    }];
}
