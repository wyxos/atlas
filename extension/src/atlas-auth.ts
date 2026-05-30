function normalizeAtlasDomain(input: string): string {
    return input.trim().replace(/\/+$/, '');
}

function isAtlasTestDomain(atlasDomain: string): boolean {
    const normalizedDomain = normalizeAtlasDomain(atlasDomain);
    if (normalizedDomain === '') {
        return false;
    }

    try {
        return new URL(normalizedDomain).hostname.toLowerCase() === 'atlas.test';
    } catch {
        return false;
    }
}

function hasAtlasApiAuth(atlasDomain: string, apiToken: string): boolean {
    return apiToken.trim() !== '' || isAtlasTestDomain(atlasDomain);
}

function createAtlasApiHeaders(apiToken: string, includeJson = false): Record<string, string> {
    const headers: Record<string, string> = {};
    if (includeJson) {
        headers['Content-Type'] = 'application/json';
    }

    const normalizedToken = apiToken.trim();
    if (normalizedToken !== '') {
        headers['X-Atlas-Api-Key'] = normalizedToken;
    } else {
        headers['X-Atlas-Local-Extension'] = '1';
    }

    return headers;
}

function createAtlasFetchAuthOptions(apiToken: string): Pick<RequestInit, 'credentials'> {
    return apiToken.trim() === '' ? { credentials: 'include' } : {};
}

export {
    createAtlasApiHeaders,
    createAtlasFetchAuthOptions,
    hasAtlasApiAuth,
    normalizeAtlasDomain,
};
