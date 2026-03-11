import { hostMatchesRuleDomain } from './match-rules';

export type ReferrerQueryParamsToStripByDomain = Record<string, string[]>;
export const STRIP_ALL_QUERY_PARAMS = '*';

const QUERY_PARAM_NAME_PATTERN = /^[^\s&#=?,]+$/;

function normalizeDomainKey(input: string): string {
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

export function normalizeReferrerQueryParams(params: string[]): string[] {
    const normalized = Array.from(new Set(
        params
            .map((param) => param.trim().toLowerCase())
            .filter((param) => param !== ''),
    ));

    return normalized.includes(STRIP_ALL_QUERY_PARAMS) ? [STRIP_ALL_QUERY_PARAMS] : normalized;
}

export function validateReferrerQueryParam(input: string): string | null {
    const trimmed = input.trim();
    if (trimmed === '') {
        return 'Referrer query parameter name cannot be empty.';
    }

    if (trimmed === STRIP_ALL_QUERY_PARAMS) {
        return null;
    }

    if (!QUERY_PARAM_NAME_PATTERN.test(trimmed)) {
        return `Invalid referrer query parameter "${input}".`;
    }

    return null;
}

function parseStoredQueryParams(value: unknown): string[] {
    if (Array.isArray(value)) {
        return normalizeReferrerQueryParams(value.filter((entry): entry is string => typeof entry === 'string'));
    }

    if (typeof value === 'string') {
        return normalizeReferrerQueryParams(value.split(/[,\n]+/));
    }

    return [];
}

export function parseStoredReferrerQueryParamsToStripByDomain(value: unknown): ReferrerQueryParamsToStripByDomain {
    if (!value || typeof value !== 'object') {
        return {};
    }

    const parsed: ReferrerQueryParamsToStripByDomain = {};

    for (const [rawDomain, rawParams] of Object.entries(value as Record<string, unknown>)) {
        const domain = normalizeDomainKey(rawDomain);
        const queryParams = parseStoredQueryParams(rawParams);
        if (domain === '' || queryParams.length === 0) {
            continue;
        }

        parsed[domain] = queryParams;
    }

    return parsed;
}

export function normalizeReferrerQueryParamsToStripByDomain(
    value: ReferrerQueryParamsToStripByDomain,
): ReferrerQueryParamsToStripByDomain {
    return parseStoredReferrerQueryParamsToStripByDomain(value);
}

export function cleanupReferrerUrl(
    value: string | null | undefined,
    referrerQueryParamsToStripByDomain: ReferrerQueryParamsToStripByDomain,
): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }

    if (!/^https?:\/\//i.test(trimmed)) {
        return null;
    }

    try {
        const parsed = new URL(trimmed);
        const queryParamsToStrip = new Set(
            Object.entries(referrerQueryParamsToStripByDomain)
                .filter(([domain]) => hostMatchesRuleDomain(parsed.hostname, domain))
                .flatMap(([, queryParams]) => queryParams),
        );

        if (queryParamsToStrip.size === 0) {
            return trimmed;
        }

        if (queryParamsToStrip.has(STRIP_ALL_QUERY_PARAMS)) {
            parsed.search = '';

            return parsed.toString();
        }

        for (const key of Array.from(parsed.searchParams.keys())) {
            if (queryParamsToStrip.has(key.toLowerCase())) {
                parsed.searchParams.delete(key);
            }
        }

        return parsed.toString();
    } catch {
        return trimmed;
    }
}
