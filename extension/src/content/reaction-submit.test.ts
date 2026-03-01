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

    it('falls back to page url for poster-only videos and includes runtime cookies/user-agent payload fields', async () => {
        mockGetStoredOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-api-token',
            matchRules: [],
        });

        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                reaction: 'like',
                exists: true,
                download: {
                    requested: false,
                    transfer_id: null,
                    status: null,
                    progress_percent: null,
                },
            }),
        });
        vi.stubGlobal('fetch', fetchMock);
        const runtimeSendMessage = vi.fn((payload: unknown, callback: (response: unknown) => void) => {
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
        });
        vi.stubGlobal('chrome', {
            runtime: {
                lastError: null,
                sendMessage: runtimeSendMessage,
            },
        });

        const { submitBadgeReaction } = await import('./reaction-submit');
        const video = document.createElement('video');
        video.poster = 'https://cdn.example.com/poster.jpg';

        const result = await submitBadgeReaction(video, 'like');

        expect(result.ok).toBe(true);
        expect(fetchMock).toHaveBeenCalledTimes(1);

        const call = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(call[0]).toBe('https://atlas.test/api/extension/reactions');
        const body = JSON.parse(String(call[1].body)) as Record<string, unknown>;

        expect(body.url).toBe(window.location.href);
        expect(body.page_url).toBe(window.location.href);
        expect(body.tag_name).toBe('video');
        expect(runtimeSendMessage).toHaveBeenCalledTimes(1);
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
    });
});
