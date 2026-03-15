import type { ReverbConfig } from './reverb-types';

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
    };
}

function formatReverbEndpoint(config: ReverbConfig | null): string | null {
    if (!config || config.host === '' || !Number.isFinite(config.port) || config.port <= 0) {
        return null;
    }

    return `${config.scheme}://${config.host}:${config.port}`;
}

export {
    formatReverbEndpoint,
    parseReverbConfig,
};
