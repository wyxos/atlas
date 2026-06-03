import { beforeEach, describe, expect, it, vi } from 'vitest';

describe('reverb-ping-cache', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    it('keeps ping results cached across short status polling windows', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-01-01T00:00:00.000Z'));

        const pingPayload = {
            ok: true,
            reverb: {
                enabled: true,
                key: 'atlas-key',
                host: 'atlas.test',
                port: 443,
                scheme: 'https',
                channel: 'private-extension-downloads.test-hash',
            },
        };
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(JSON.stringify(pingPayload), { status: 200 }),
        );
        vi.stubGlobal('fetch', fetchMock);

        const { fetchCachedReverbPing } = await import('./reverb-ping-cache');

        await expect(fetchCachedReverbPing('https://atlas.test', 'api-token')).resolves.toEqual({
            ok: true,
            status: 200,
            payload: pingPayload,
        });
        expect(fetchMock).toHaveBeenCalledTimes(1);

        vi.setSystemTime(new Date('2026-01-01T00:02:00.000Z'));
        await expect(fetchCachedReverbPing('https://atlas.test', 'api-token')).resolves.toEqual({
            ok: true,
            status: 200,
            payload: pingPayload,
        });
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});
