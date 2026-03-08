import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    atlasLoggedFetch,
    atlasLoggedRuntimeRequest,
    clearAtlasRequestLog,
    getAtlasRequestLogSnapshot,
} from './atlas-request-log';

describe('atlas-request-log', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
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

    it('shows a toast and logs to the console for non-ok fetch responses', async () => {
        const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ message: 'Server error' }), { status: 500 })));

        const response = await atlasLoggedFetch(
            'https://atlas.test/api/extension/badges/checks',
            'POST',
            { items: [{ request_id: 'req-1' }] },
            {
                method: 'POST',
                body: JSON.stringify({ items: [{ request_id: 'req-1' }] }),
            },
        );

        expect(response.status).toBe(500);
        expect(consoleError).toHaveBeenCalledWith(
            '[Atlas Extension] Request failed',
            expect.objectContaining({
                endpoint: 'https://atlas.test/api/extension/badges/checks',
                method: 'POST',
                status: 500,
                responsePayload: { message: 'Server error' },
            }),
        );

        const toast = document.querySelector('[data-atlas-request-failure-toast="1"]');
        expect(toast).not.toBeNull();
        expect(toast?.textContent).toContain('Atlas request failed (500).');
        expect((toast as HTMLElement).style.pointerEvents).toBe('auto');
    });

    it('logs runtime unavailable state and response payload when present', async () => {
        const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
        const unavailable = await atlasLoggedRuntimeRequest(
            'https://atlas.test/api/extension/reactions',
            'POST',
            { type: 'like' },
            async () => null,
        );

        expect(unavailable).toBeNull();
        const first = getAtlasRequestLogSnapshot()[0];
        expect(first.status).toBe('runtime_unavailable');
        expect(consoleError).not.toHaveBeenCalled();
        expect(document.querySelector('[data-atlas-request-failure-toast="1"]')).toBeNull();

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

    it('shows a toast and logs to the console for non-ok runtime responses', async () => {
        const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});

        const response = await atlasLoggedRuntimeRequest(
            'https://atlas.test/api/extension/reactions',
            'POST',
            { type: 'like' },
            async () => ({
                ok: false,
                status: 401,
                payload: { message: 'Invalid API key' },
            }),
        );

        expect(response?.ok).toBe(false);
        expect(consoleError).toHaveBeenCalledWith(
            '[Atlas Extension] Request failed',
            expect.objectContaining({
                endpoint: 'https://atlas.test/api/extension/reactions',
                method: 'POST',
                status: 401,
                responsePayload: { message: 'Invalid API key' },
            }),
        );

        const toast = document.querySelector('[data-atlas-request-failure-toast="1"]');
        expect(toast).not.toBeNull();
        expect(toast?.textContent).toContain('Atlas request failed (401).');
    });

    it('retains only the latest 20 request entries', async () => {
        for (let index = 0; index < 25; index += 1) {
            await atlasLoggedRuntimeRequest(
                `https://atlas.test/api/extension/reactions/${index}`,
                'POST',
                { index },
                async () => ({
                    ok: true,
                    status: 200,
                    payload: { index },
                }),
            );
        }

        const snapshot = getAtlasRequestLogSnapshot();
        expect(snapshot).toHaveLength(20);
        expect(snapshot[0]?.requestPayload).toEqual({ index: 24 });
        expect(snapshot[19]?.requestPayload).toEqual({ index: 5 });
    });
});
