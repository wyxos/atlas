import { beforeEach, describe, expect, it, vi } from 'vitest';
import { handleAtlasApiRequestRuntimeMessage } from './background-runtime-message-handlers';

function sendAtlasApiRequest(message: Record<string, unknown>): Promise<unknown> {
    return new Promise((resolve) => {
        const handled = handleAtlasApiRequestRuntimeMessage(message, resolve);
        expect(handled).toBe(true);
    });
}

describe('background runtime settings message bridge', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.unstubAllGlobals();
    });

    it('relays Atlas extension settings writes through the background fetch path', async () => {
        const settings = {
            version: 1,
            siteCustomizations: [],
            closeTabAfterQueueByDomain: {},
            reactAllItemsInPostByDomain: {},
        };
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({ settings }), { status: 200 }),
        );
        vi.stubGlobal('fetch', fetchMock);

        const response = await sendAtlasApiRequest({
            type: 'ATLAS_API_REQUEST',
            atlasDomain: 'https://atlas.wyxos.com',
            apiToken: 'test-api-token',
            endpoint: 'https://atlas.wyxos.com/api/extension/settings',
            method: 'POST',
            body: { settings },
        });

        expect(fetchMock).toHaveBeenCalledWith('https://atlas.wyxos.com/api/extension/settings', {
            method: 'POST',
            headers: {
                'X-Atlas-Api-Key': 'test-api-token',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ settings }),
        });
        expect(response).toEqual({
            ok: true,
            status: 200,
            payload: { settings },
        });
    });
});
