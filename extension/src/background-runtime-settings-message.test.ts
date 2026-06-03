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

    it('coalesces repeated Atlas extension settings reads and refreshes the cache after writes', async () => {
        const initialSettings = {
            version: 1,
            siteCustomizations: [],
            closeTabAfterQueueByDomain: {},
            reactAllItemsInPostByDomain: {},
        };
        const updatedSettings = {
            ...initialSettings,
            reactAllItemsInPostByDomain: {
                'example.com': true,
            },
        };
        let remoteSettings = initialSettings;
        const fetchMock = vi.fn(async (_endpoint: string, init?: RequestInit) => {
            if (init?.method === 'POST') {
                const body = JSON.parse(String(init.body ?? '{}')) as { settings?: typeof initialSettings };
                remoteSettings = body.settings ?? initialSettings;
            }

            return new Response(JSON.stringify({ settings: remoteSettings }), { status: 200 });
        });
        vi.stubGlobal('fetch', fetchMock);

        const getMessage = {
            type: 'ATLAS_API_REQUEST',
            atlasDomain: 'https://atlas.wyxos.com',
            apiToken: 'cache-token',
            endpoint: 'https://atlas.wyxos.com/api/extension/settings',
            method: 'GET',
        };

        const [first, second] = await Promise.all([
            sendAtlasApiRequest(getMessage),
            sendAtlasApiRequest(getMessage),
        ]);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(first).toEqual({
            ok: true,
            status: 200,
            payload: { settings: initialSettings },
        });
        expect(second).toEqual(first);

        await sendAtlasApiRequest(getMessage);
        expect(fetchMock).toHaveBeenCalledTimes(1);

        await sendAtlasApiRequest({
            ...getMessage,
            method: 'POST',
            body: { settings: updatedSettings },
        });
        expect(fetchMock).toHaveBeenCalledTimes(2);

        const cachedAfterWrite = await sendAtlasApiRequest(getMessage);
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(cachedAfterWrite).toEqual({
            ok: true,
            status: 200,
            payload: { settings: updatedSettings },
        });
    });

    it('coalesces repeated Atlas extension ping reads through the background fetch path', async () => {
        const pingPayload = {
            ok: true,
            reverb: {
                enabled: true,
                key: 'atlas-key',
                host: 'atlas.wyxos.com',
                port: 443,
                scheme: 'https',
                channel: 'private-extension-downloads.test-hash',
            },
        };
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(JSON.stringify(pingPayload), { status: 200 }),
        );
        vi.stubGlobal('fetch', fetchMock);

        const getMessage = {
            type: 'ATLAS_API_REQUEST',
            atlasDomain: 'https://atlas.wyxos.com',
            apiToken: 'ping-cache-token',
            endpoint: 'https://atlas.wyxos.com/api/extension/ping',
            method: 'GET',
        };

        const [first, second] = await Promise.all([
            sendAtlasApiRequest(getMessage),
            sendAtlasApiRequest(getMessage),
        ]);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(first).toEqual({
            ok: true,
            status: 200,
            payload: pingPayload,
        });
        expect(second).toEqual(first);

        await sendAtlasApiRequest(getMessage);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('relays extension file deletion through the background fetch path', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({
                deleted: true,
                file_id: 123,
            }), { status: 200 }),
        );
        vi.stubGlobal('fetch', fetchMock);

        const response = await sendAtlasApiRequest({
            type: 'ATLAS_API_REQUEST',
            atlasDomain: 'https://atlas.wyxos.com',
            apiToken: 'test-api-token',
            endpoint: 'https://atlas.wyxos.com/api/extension/files/123',
            method: 'DELETE',
            body: {
                also_from_disk: true,
                also_delete_record: true,
            },
        });

        expect(fetchMock).toHaveBeenCalledWith('https://atlas.wyxos.com/api/extension/files/123', {
            method: 'DELETE',
            headers: {
                'X-Atlas-Api-Key': 'test-api-token',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                also_from_disk: true,
                also_delete_record: true,
            }),
        });
        expect(response).toEqual({
            ok: true,
            status: 200,
            payload: {
                deleted: true,
                file_id: 123,
            },
        });
    });
});
