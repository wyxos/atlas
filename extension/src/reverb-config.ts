import type { ReverbAuthConfig, ReverbConfig } from './reverb-types';

function asString(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function asNumber(value: unknown): number | null {
    if (typeof value === 'number' && Number.isFinite(value)) {
        return value;
    }

    if (typeof value === 'string') {
        const trimmed = value.trim();
        if (trimmed === '') {
            return null;
        }

        const parsed = Number(trimmed);
        return Number.isFinite(parsed) ? parsed : null;
    }

    return null;
}

function parseReverbConfig(value: unknown): ReverbConfig | null {
    if (!value || typeof value !== 'object') {
        return null;
    }

    const row = value as Record<string, unknown>;
    const enabled = row.enabled === true;
    const key = asString(row.key) ?? '';
    const host = asString(row.host) ?? '';
    const channel = asString(row.channel) ?? '';
    const scheme = row.scheme === 'http' ? 'http' : 'https';
    const port = asNumber(row.port) ?? 443;

    return {
        enabled,
        key,
        host,
        port,
        scheme,
        channel,
        auth: null,
    };
}

function createExtensionReverbAuthConfig(atlasDomain: string, apiToken: string): ReverbAuthConfig | null {
    const normalizedDomain = atlasDomain.trim().replace(/\/+$/, '');
    const normalizedToken = apiToken.trim();
    if (normalizedDomain === '' || normalizedToken === '') {
        return null;
    }

    return {
        endpoint: `${normalizedDomain}/api/extension/broadcasting/auth`,
        headers: {
            'X-Atlas-Api-Key': normalizedToken,
        },
    };
}

function formatReverbEndpoint(config: ReverbConfig | null): string | null {
    if (!config || config.host === '' || !Number.isFinite(config.port) || config.port <= 0) {
        return null;
    }

    return `${config.scheme}://${config.host}:${config.port}`;
}

export {
    createExtensionReverbAuthConfig,
    formatReverbEndpoint,
    parseReverbConfig,
};
