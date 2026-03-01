export type UrlMatchRule = {
    domain: string;
    regexes: string[];
};

export const DEFAULT_MATCH_RULES: UrlMatchRule[] = [
    {
        domain: 'deviantart.com',
        regexes: ['.*\\/art\\/.*', '.*images-wix.*'],
    },
];

export function normalizeMatchRules(rules: UrlMatchRule[]): UrlMatchRule[] {
    return rules
        .map((rule) => ({
            domain: rule.domain.trim().toLowerCase(),
            regexes: rule.regexes.map((regex) => regex.trim()).filter((regex) => regex !== ''),
        }))
        .filter((rule) => rule.domain !== '')
        .map((rule) => ({
            ...rule,
            regexes: Array.from(new Set(rule.regexes)),
        }));
}

export function validateDomainRule(domain: string): string | null {
    const trimmed = domain.trim().toLowerCase();
    if (trimmed === '') {
        return 'Domain is required.';
    }

    // Host-like validation for domain/subdomain entries.
    if (!/^[a-z0-9.-]+\.[a-z]{2,}$/i.test(trimmed)) {
        return `Invalid domain "${domain}".`;
    }

    return null;
}

export function validateRegexPattern(pattern: string): string | null {
    const trimmed = pattern.trim();
    if (trimmed === '') {
        return 'Regex pattern cannot be empty.';
    }

    try {
         
        new RegExp(trimmed, 'i');
    } catch {
        return `Invalid regex pattern "${pattern}".`;
    }

    return null;
}

export function parseStoredMatchRules(value: unknown): UrlMatchRule[] {
    if (!Array.isArray(value)) {
        return [];
    }

    const parsed = value
        .map((entry): UrlMatchRule | null => {
            if (typeof entry !== 'object' || entry === null) {
                return null;
            }

            const domain = typeof (entry as { domain?: unknown }).domain === 'string'
                ? ((entry as { domain: string }).domain)
                : '';

            const regexes = Array.isArray((entry as { regexes?: unknown }).regexes)
                ? ((entry as { regexes: unknown[] }).regexes)
                    .filter((regex): regex is string => typeof regex === 'string')
                : [];

            return { domain, regexes };
        })
        .filter((rule): rule is UrlMatchRule => rule !== null);

    return normalizeMatchRules(parsed);
}

export function hostMatchesRuleDomain(hostname: string, domain: string): boolean {
    const normalizedHost = hostname.toLowerCase();
    const normalizedDomain = domain.toLowerCase();

    return normalizedHost === normalizedDomain || normalizedHost.endsWith(`.${normalizedDomain}`);
}

function compileRulePattern(pattern: string): RegExp | null {
    const trimmed = pattern.trim();
    if (trimmed === '') {
        return null;
    }

    // Support slash-wrapped shorthand like /art/ from options defaults.
    const normalized = trimmed.startsWith('/') && trimmed.endsWith('/') && trimmed.length > 2
        ? trimmed.slice(1, -1)
        : trimmed;

    try {
        return new RegExp(normalized, 'i');
    } catch {
        return null;
    }
}

function activeRulesForPage(rules: UrlMatchRule[], pageHostname?: string): UrlMatchRule[] {
    if (!pageHostname || pageHostname.trim() === '') {
        return rules;
    }

    return rules.filter((rule) => hostMatchesRuleDomain(pageHostname, rule.domain));
}

export function urlMatchesAnyRule(url: string | null, rules: UrlMatchRule[], pageHostname?: string): boolean {
    if (!url || !/^https?:\/\//i.test(url)) {
        return false;
    }

    if (rules.length === 0) {
        return true;
    }

    const activeRules = activeRulesForPage(rules, pageHostname);
    if (activeRules.length === 0) {
        return true;
    }

    for (const rule of activeRules) {
        for (const pattern of rule.regexes) {
            const regex = compileRulePattern(pattern);
            if (regex && regex.test(url)) {
                return true;
            }
        }
    }

    return false;
}
