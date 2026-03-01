import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    atlasLoggedFetch,
    atlasLoggedRuntimeRequest,
    clearAtlasRequestLog,
    getAtlasRequestLogSnapshot,
} from './atlas-request-log';

describe('atlas-request-log', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        clearAtlasRequestLog();
    });

    it('logs fetch requests with request and response payloads', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ ok: true }), { status: 200 })));

        const response = await atlasLoggedFetch(
            'https://atlas.test/api/extension/badges/checks',
            'POST',
            { items: [{ request_id: 'req-1' }] },
            {
                method: 'POST',
                body: JSON.stringify({ items: [{ request_id: 'req-1' }] }),
            },
        );

        expect(response.status).toBe(200);
        const [entry] = getAtlasRequestLogSnapshot();
        expect(entry.endpoint).toBe('https://atlas.test/api/extension/badges/checks');
        expect(entry.method).toBe('POST');
        expect(entry.status).toBe(200);
        expect(entry.requestPayload).toEqual({ items: [{ request_id: 'req-1' }] });
        expect(entry.responsePayload).toEqual({ ok: true });
        expect(entry.durationMs).toBeGreaterThanOrEqual(0);
        expect(entry.timestamp).toBeTypeOf('string');
    });

    it('logs runtime unavailable state and response payload when present', async () => {
        const unavailable = await atlasLoggedRuntimeRequest(
            'https://atlas.test/api/extension/reactions',
            'POST',
            { type: 'like' },
            async () => null,
        );

        expect(unavailable).toBeNull();
        const first = getAtlasRequestLogSnapshot()[0];
        expect(first.status).toBe('runtime_unavailable');

        const response = await atlasLoggedRuntimeRequest(
            'https://atlas.test/api/extension/reactions',
            'POST',
            { type: 'like' },
            async () => ({
                ok: true,
                status: 200,
                payload: { reaction: 'like' },
            }),
        );

        expect(response?.ok).toBe(true);
        const latest = getAtlasRequestLogSnapshot()[0];
        expect(latest.status).toBe(200);
        expect(latest.responsePayload).toEqual({ reaction: 'like' });
    });
});
