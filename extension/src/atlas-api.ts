import { getStoredOptions } from './atlas-options';

export type AtlasApiConnectionStatus = {
    label: 'Ready' | 'Setup required' | 'Auth failed' | 'Offline';
    detail: string;
};

export async function resolveApiConnectionStatus(): Promise<AtlasApiConnectionStatus> {
    try {
        const stored = await getStoredOptions();
        const domain = stored.atlasDomain;
        const apiToken = stored.apiToken;

        if (apiToken === '') {
            return {
                label: 'Setup required',
                detail: 'Set the API key in extension options before using Atlas API actions.',
            };
        }

        const response = await fetch(`${domain}/api/extension/ping`, {
            method: 'GET',
            headers: {
                'X-Atlas-Api-Key': apiToken,
            },
        });

        if (response.ok) {
            return {
                label: 'Ready',
                detail: `Connected to ${domain}`,
            };
        }

        return {
            label: 'Auth failed',
            detail: 'API key or domain is invalid. Update extension options.',
        };
    } catch {
        return {
            label: 'Offline',
            detail: 'Unable to verify API access. Check extension options.',
        };
    }
}
