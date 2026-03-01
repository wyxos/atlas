import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetStoredOptions = vi.fn();

vi.mock('../atlas-options', () => ({
    getStoredOptions: mockGetStoredOptions,
}));

describe('submitBadgeReaction', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        history.replaceState({}, '', '/extension-test/reaction-submit');
    });

    it('submits through background runtime so requests survive tab close', async () => {
        mockGetStoredOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-api-token',
            matchRules: [],
        });

        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const runtimeSendMessage = vi.fn((payload: unknown, callback: (response: unknown) => void) => {
            const typed = payload as { type?: string };
            if (typed.type === 'ATLAS_GET_URL_COOKIES') {
                callback({
                    cookies: [
                        {
                            name: 'atlas_session',
                            value: 'abc123',
                            domain: '.atlas.test',
                            path: '/',
                            secure: true,
                            http_only: true,
                            host_only: false,
                            expires_at: null,
                        },
                    ],
                });
                return;
            }

            if (typed.type === 'ATLAS_SUBMIT_REACTION') {
                callback({
                    ok: true,
                    status: 200,
                    payload: {
                        reaction: 'like',
                        exists: true,
                        download: {
                            requested: false,
                            transfer_id: null,
                            status: null,
                            progress_percent: null,
                        },
                    },
                });
                return;
            }

            callback(null);
        });
        vi.stubGlobal('chrome', {
            runtime: {
                lastError: null,
                sendMessage: runtimeSendMessage,
            },
        });

        const { submitBadgeReaction } = await import('./reaction-submit');
        const { clearAtlasRequestLog, getAtlasRequestLogSnapshot } = await import('./atlas-request-log');
        clearAtlasRequestLog();
        const video = document.createElement('video');
        video.poster = 'https://cdn.example.com/poster.jpg';

        const result = await submitBadgeReaction(video, 'like');

        expect(result.ok).toBe(true);
        expect(fetchMock).not.toHaveBeenCalled();
        expect(runtimeSendMessage).toHaveBeenCalledTimes(2);

        const submitCall = runtimeSendMessage.mock.calls[1] as [Record<string, unknown>, (response: unknown) => void];
        expect(submitCall[0].type).toBe('ATLAS_SUBMIT_REACTION');
        const body = submitCall[0].body as Record<string, unknown>;

        expect(body.url).toBe(window.location.href);
        expect(body.page_url).toBe(window.location.href);
        expect(body.tag_name).toBe('video');
        expect(body.cookies).toEqual([
            {
                name: 'atlas_session',
                value: 'abc123',
                domain: '.atlas.test',
                path: '/',
                secure: true,
                http_only: true,
                host_only: false,
                expires_at: null,
            },
        ]);
        expect(body.user_agent).toBe(navigator.userAgent);

        const [requestLog] = getAtlasRequestLogSnapshot();
        expect(requestLog.endpoint).toBe('https://atlas.test/api/extension/reactions');
        expect(requestLog.method).toBe('POST');
        expect(requestLog.status).toBe(200);
        expect(requestLog.requestPayload).toMatchObject({
            type: 'like',
            url: window.location.href,
        });
        expect(requestLog.responsePayload).toEqual({
            reaction: 'like',
            exists: true,
            download: {
                requested: false,
                transfer_id: null,
                status: null,
                progress_percent: null,
            },
        });
    });

    it('falls back to page fetch with keepalive when runtime submit is unavailable', async () => {
        mockGetStoredOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-api-token',
            matchRules: [],
        });

        const fetchMock = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({
                reaction: 'like',
                exists: true,
                download: {
                    requested: false,
                    transfer_id: null,
                    status: null,
                    progress_percent: null,
                },
            }), { status: 200 }),
        );
        vi.stubGlobal('fetch', fetchMock);
        const runtimeSendMessage = vi.fn((payload: unknown, callback: (response: unknown) => void) => {
            const typed = payload as { type?: string };
            if (typed.type === 'ATLAS_GET_URL_COOKIES') {
                callback({
                    cookies: [
                        {
                            name: 'atlas_session',
                            value: 'abc123',
                            domain: '.atlas.test',
                            path: '/',
                            secure: true,
                            http_only: true,
                            host_only: false,
                            expires_at: null,
                        },
                    ],
                });
                return;
            }

            callback(undefined);
        });
        vi.stubGlobal('chrome', {
            runtime: {
                lastError: null,
                sendMessage: runtimeSendMessage,
            },
        });

        const { submitBadgeReaction } = await import('./reaction-submit');
        const { clearAtlasRequestLog, getAtlasRequestLogSnapshot } = await import('./atlas-request-log');
        clearAtlasRequestLog();
        const video = document.createElement('video');
        video.poster = 'https://cdn.example.com/poster.jpg';

        const result = await submitBadgeReaction(video, 'like');

        expect(result.ok).toBe(true);
        expect(runtimeSendMessage).toHaveBeenCalledTimes(2);
        expect(fetchMock).toHaveBeenCalledTimes(1);

        const fetchCall = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(fetchCall[0]).toBe('https://atlas.test/api/extension/reactions');
        expect(fetchCall[1].keepalive).toBe(true);
        const body = JSON.parse(String(fetchCall[1].body)) as Record<string, unknown>;
        expect(body.url).toBe(window.location.href);
        expect(body.page_url).toBe(window.location.href);
        expect(body.tag_name).toBe('video');
        expect(body.cookies).toEqual([
            {
                name: 'atlas_session',
                value: 'abc123',
                domain: '.atlas.test',
                path: '/',
                secure: true,
                http_only: true,
                host_only: false,
                expires_at: null,
            },
        ]);
        expect(body.user_agent).toBe(navigator.userAgent);

        const logs = getAtlasRequestLogSnapshot();
        expect(logs).toHaveLength(2);
        expect(logs[0].status).toBe(200);
        expect(logs[0].endpoint).toBe('https://atlas.test/api/extension/reactions');
        expect(logs[1].status).toBe('runtime_unavailable');
    });
});
