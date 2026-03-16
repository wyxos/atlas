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
            referrerQueryParamsToStripByDomain: {},
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
        expect(submitCall[0].endpoint).toBe('https://atlas.test/api/extension/reactions');
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
        expect(result.shouldCloseTabAfterQueue).toBe(false);

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

    it('falls back to page fetch when runtime submit is unavailable', async () => {
        mockGetStoredOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-api-token',
            matchRules: [],
            referrerQueryParamsToStripByDomain: {},
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
        expect(fetchCall[1].keepalive).toBeUndefined();
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

    it('uses the batch endpoint when multiple harvested items are provided', async () => {
        mockGetStoredOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-api-token',
            matchRules: [],
            referrerQueryParamsToStripByDomain: {},
        });

        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const runtimeSendMessage = vi.fn((payload: unknown, callback: (response: unknown) => void) => {
            const typed = payload as { type?: string };
            if (typed.type === 'ATLAS_GET_URL_COOKIES') {
                callback({ cookies: [] });
                return;
            }

            if (typed.type === 'ATLAS_SUBMIT_REACTION') {
                callback({
                    ok: true,
                    status: 200,
                    payload: {
                        reaction: 'love',
                        file: {
                            id: 42,
                            url: 'https://images.example.com/direct-image-1.jpg',
                            referrer_url: 'https://www.deviantart.com/artist/art/post-1',
                            preview_url: 'https://images.example.com/direct-image-1.jpg',
                        },
                        download: {
                            requested: true,
                            transfer_id: 99,
                            status: 'queued',
                            progress_percent: 0,
                        },
                        batch: {
                            count: 2,
                            primary_candidate_id: 'image-1',
                            download_requested: true,
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
        const image = document.createElement('img');
        image.src = 'https://images.example.com/direct-image-1.jpg';

        const result = await submitBadgeReaction(image, 'love', {
            batchItems: [
                {
                    candidateId: 'image-1',
                    url: 'https://images.example.com/direct-image-1.jpg',
                    referrerUrlHashAware: 'https://www.deviantart.com/artist/art/post-1',
                    pageUrl: 'https://www.deviantart.com/artist/art/post-1',
                    tagName: 'img',
                },
                {
                    candidateId: 'image-2',
                    url: 'https://images.example.com/direct-image-2.jpg',
                    referrerUrlHashAware: 'https://www.deviantart.com/artist/art/post-1#image-2',
                    pageUrl: 'https://www.deviantart.com/artist/art/post-1',
                    tagName: 'img',
                },
            ],
        });

        expect(result.ok).toBe(true);
        expect(fetchMock).not.toHaveBeenCalled();
        const submitCall = runtimeSendMessage.mock.calls[1] as [Record<string, unknown>, (response: unknown) => void];
        expect(submitCall[0].type).toBe('ATLAS_SUBMIT_REACTION');
        expect(submitCall[0].endpoint).toBe('https://atlas.test/api/extension/reactions/batch');
        const body = submitCall[0].body as Record<string, unknown>;
        expect(body.primary_candidate_id).toBe('image-1');
        expect(body.items).toEqual([
            {
                candidate_id: 'image-1',
                url: 'https://images.example.com/direct-image-1.jpg',
                referrer_url_hash_aware: 'https://www.deviantart.com/artist/art/post-1',
                page_url: 'https://www.deviantart.com/artist/art/post-1',
                tag_name: 'img',
            },
            {
                candidate_id: 'image-2',
                url: 'https://images.example.com/direct-image-2.jpg',
                referrer_url_hash_aware: 'https://www.deviantart.com/artist/art/post-1#image-2',
                page_url: 'https://www.deviantart.com/artist/art/post-1',
                tag_name: 'img',
            },
        ]);

        const submitPayload = runtimeSendMessage.mock.calls[1]?.[0] as Record<string, unknown>;
        expect(submitPayload.atlasDomain).toBe('https://atlas.test');
        expect(submitPayload.endpoint).toBe('https://atlas.test/api/extension/reactions/batch');
        expect(result.fileId).toBe(42);
        expect(result.downloadTransferId).toBe(99);
        expect(result.downloadStatus).toBe('queued');
        expect(result.shouldCloseTabAfterQueue).toBe(true);
        expect(result.downloadCloseTargets).toEqual([
            {
                fileId: 42,
                transferId: 99,
                status: 'queued',
                downloadedAt: null,
            },
        ]);
    });

    it('keeps batch auto-close enabled when only a non-primary item was newly queued', async () => {
        mockGetStoredOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-api-token',
            matchRules: [],
            referrerQueryParamsToStripByDomain: {},
        });

        vi.stubGlobal('fetch', vi.fn());
        const runtimeSendMessage = vi.fn((payload: unknown, callback: (response: unknown) => void) => {
            const typed = payload as { type?: string };
            if (typed.type === 'ATLAS_GET_URL_COOKIES') {
                callback({ cookies: [] });
                return;
            }

            if (typed.type === 'ATLAS_SUBMIT_REACTION') {
                callback({
                    ok: true,
                    status: 200,
                    payload: {
                        reaction: 'love',
                        file: {
                            id: 42,
                            url: 'https://images.example.com/direct-image-1.jpg',
                            referrer_url: 'https://www.deviantart.com/artist/art/post-1',
                            preview_url: 'https://images.example.com/direct-image-1.jpg',
                        },
                        download: {
                            requested: false,
                            transfer_id: null,
                            status: null,
                            progress_percent: null,
                        },
                        batch: {
                            count: 2,
                            primary_candidate_id: 'image-1',
                            items: [
                                {
                                    candidate_id: 'image-1',
                                    download: {
                                        requested: false,
                                        transfer_id: null,
                                        status: null,
                                        progress_percent: null,
                                    },
                                },
                                {
                                    candidate_id: 'image-2',
                                    download: {
                                        requested: true,
                                        transfer_id: 123,
                                        status: 'queued',
                                        progress_percent: 0,
                                    },
                                },
                            ],
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
        const image = document.createElement('img');
        image.src = 'https://images.example.com/direct-image-1.jpg';

        const result = await submitBadgeReaction(image, 'love', {
            batchItems: [
                {
                    candidateId: 'image-1',
                    url: 'https://images.example.com/direct-image-1.jpg',
                    referrerUrlHashAware: 'https://www.deviantart.com/artist/art/post-1',
                    pageUrl: 'https://www.deviantart.com/artist/art/post-1',
                    tagName: 'img',
                },
                {
                    candidateId: 'image-2',
                    url: 'https://images.example.com/direct-image-2.jpg',
                    referrerUrlHashAware: 'https://www.deviantart.com/artist/art/post-1#image-2',
                    pageUrl: 'https://www.deviantart.com/artist/art/post-1',
                    tagName: 'img',
                },
            ],
        });

        expect(result.ok).toBe(true);
        expect(result.downloadRequested).toBe(false);
        expect(result.shouldCloseTabAfterQueue).toBe(true);
        expect(result.downloadCloseTargets).toEqual([
            {
                fileId: null,
                transferId: 123,
                status: 'queued',
                downloadedAt: null,
            },
        ]);
    });

    it('closes on explicit batch download_requested even without item payload details', async () => {
        mockGetStoredOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-api-token',
            matchRules: [],
            referrerQueryParamsToStripByDomain: {},
        });

        vi.stubGlobal('fetch', vi.fn());
        const runtimeSendMessage = vi.fn((payload: unknown, callback: (response: unknown) => void) => {
            const typed = payload as { type?: string };
            if (typed.type === 'ATLAS_GET_URL_COOKIES') {
                callback({ cookies: [] });
                return;
            }

            if (typed.type === 'ATLAS_SUBMIT_REACTION') {
                callback({
                    ok: true,
                    status: 200,
                    payload: {
                        reaction: 'funny',
                        file: {
                            id: 77,
                            url: 'https://images.example.com/direct-image-1.jpg',
                            referrer_url: 'https://www.deviantart.com/artist/art/post-1',
                            preview_url: 'https://images.example.com/direct-image-1.jpg',
                        },
                        download: {
                            requested: false,
                            transfer_id: null,
                            status: null,
                            progress_percent: null,
                        },
                        batch: {
                            count: 2,
                            primary_candidate_id: 'image-1',
                            download_requested: true,
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
        const image = document.createElement('img');
        image.src = 'https://images.example.com/direct-image-1.jpg';

        const result = await submitBadgeReaction(image, 'funny', {
            batchItems: [
                {
                    candidateId: 'image-1',
                    url: 'https://images.example.com/direct-image-1.jpg',
                    referrerUrlHashAware: 'https://www.deviantart.com/artist/art/post-1',
                    pageUrl: 'https://www.deviantart.com/artist/art/post-1',
                    tagName: 'img',
                },
                {
                    candidateId: 'image-2',
                    url: 'https://images.example.com/direct-image-2.jpg',
                    referrerUrlHashAware: 'https://www.deviantart.com/artist/art/post-1#image-2',
                    pageUrl: 'https://www.deviantart.com/artist/art/post-1',
                    tagName: 'img',
                },
            ],
        });

        expect(result.ok).toBe(true);
        expect(result.downloadRequested).toBe(false);
        expect(result.shouldCloseTabAfterQueue).toBe(true);
        expect(result.downloadCloseTargets).toEqual([]);
    });

});
