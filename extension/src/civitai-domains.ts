export const CIVITAI_PRIMARY_DOMAIN = 'civitai.com';
export const CIVITAI_NSFW_DOMAIN = 'civitai.red';
export const CIVITAI_SUPPORTED_DOMAINS = [CIVITAI_PRIMARY_DOMAIN, CIVITAI_NSFW_DOMAIN] as const;
export const CIVITAI_PRIMARY_MEDIA_HOST = 'image.civitai.com';

function normalizeHostname(hostname: string): string {
    return hostname.trim().toLowerCase().replace(/^\.+/, '').replace(/\.+$/, '');
}

export function isCivitAiHostname(hostname: string): boolean {
    const normalized = normalizeHostname(hostname);

    return CIVITAI_SUPPORTED_DOMAINS.some((domain) =>
        normalized === domain || normalized.endsWith(`.${domain}`));
}

export function isCivitAiMediaHostname(hostname: string): boolean {
    const normalized = normalizeHostname(hostname);

    return normalized === CIVITAI_PRIMARY_MEDIA_HOST;
}

export function normalizeCivitAiHostnameForMatching(hostname: string): string {
    const normalized = normalizeHostname(hostname);

    for (const domain of CIVITAI_SUPPORTED_DOMAINS) {
        if (normalized === domain) {
            return CIVITAI_PRIMARY_DOMAIN;
        }

        const suffix = `.${domain}`;
        if (normalized.endsWith(suffix)) {
            return `${normalized.slice(0, -suffix.length)}.${CIVITAI_PRIMARY_DOMAIN}`;
        }
    }

    return normalized;
}

export function createCivitAiLinkSelector(pathPrefix: string): string {
    return [
        `a[href^="${pathPrefix}"]`,
        ...CIVITAI_SUPPORTED_DOMAINS.map((domain) => `a[href*="://${domain}${pathPrefix}"]`),
    ].join(', ');
}

export function createCivitAiDomainAliasUrls(value: string | null | undefined): string[] {
    if (typeof value !== 'string' || value.trim() === '') {
        return [];
    }

    try {
        const parsed = new URL(value);
        const normalizedHost = normalizeHostname(parsed.hostname);
        const aliasHost = resolveCivitAiAliasHost(normalizedHost);
        if (aliasHost === null) {
            return [];
        }

        parsed.hostname = aliasHost;

        return [parsed.toString()];
    } catch {
        return [];
    }
}

function resolveCivitAiAliasHost(hostname: string): string | null {
    if (hostname === CIVITAI_PRIMARY_DOMAIN) {
        return CIVITAI_NSFW_DOMAIN;
    }

    if (hostname === CIVITAI_NSFW_DOMAIN) {
        return CIVITAI_PRIMARY_DOMAIN;
    }

    if (hostname === `www.${CIVITAI_PRIMARY_DOMAIN}`) {
        return `www.${CIVITAI_NSFW_DOMAIN}`;
    }

    if (hostname === `www.${CIVITAI_NSFW_DOMAIN}`) {
        return `www.${CIVITAI_PRIMARY_DOMAIN}`;
    }

    return null;
}
