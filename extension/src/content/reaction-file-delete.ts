import { getStoredOptions } from '../atlas-options';
import { createAtlasApiHeaders, createAtlasFetchAuthOptions, hasAtlasApiAuth } from '../atlas-auth';
import { requestAtlasViaRuntime } from '../atlas-runtime-request';
import { atlasLoggedFetch, atlasLoggedRuntimeRequest } from './atlas-request-log';

export type DeleteBadgeFileResult = {
    ok: boolean;
};

function normalizeFileId(fileId: number): number | null {
    if (!Number.isInteger(fileId) || fileId <= 0) {
        return null;
    }

    return fileId;
}

export async function deleteBadgeFile(fileId: number): Promise<DeleteBadgeFileResult> {
    const normalizedFileId = normalizeFileId(fileId);
    if (normalizedFileId === null) {
        return { ok: false };
    }

    try {
        const stored = await getStoredOptions();
        if (!hasAtlasApiAuth(stored.atlasDomain, stored.apiToken)) {
            return { ok: false };
        }

        const endpoint = `${stored.atlasDomain}/api/extension/files/${normalizedFileId}`;
        const requestBody = {
            also_from_disk: true,
            also_delete_record: true,
        };
        const runtimeResponse = await atlasLoggedRuntimeRequest(
            endpoint,
            'DELETE',
            requestBody,
            () => requestAtlasViaRuntime({
                endpoint,
                atlasDomain: stored.atlasDomain,
                apiToken: stored.apiToken,
                method: 'DELETE',
                body: requestBody,
            }),
        );

        if (runtimeResponse !== null) {
            return { ok: runtimeResponse.ok };
        }

        const response = await atlasLoggedFetch(endpoint, 'DELETE', requestBody, {
            method: 'DELETE',
            headers: createAtlasApiHeaders(stored.apiToken, true),
            ...createAtlasFetchAuthOptions(stored.apiToken),
            body: JSON.stringify(requestBody),
        });

        return { ok: response.ok };
    } catch {
        return { ok: false };
    }
}
