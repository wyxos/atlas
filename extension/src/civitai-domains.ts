export const CIVITAI_PRIMARY_DOMAIN = 'civitai.com';
export const CIVITAI_SUPPORTED_DOMAINS = [CIVITAI_PRIMARY_DOMAIN, 'civitai.red'] as const;
export const CIVITAI_MEDIA_HOSTS = ['image.civitai.com', 'image.civitai.red'] as const;

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

    return CIVITAI_MEDIA_HOSTS.includes(normalized as typeof CIVITAI_MEDIA_HOSTS[number]);
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
