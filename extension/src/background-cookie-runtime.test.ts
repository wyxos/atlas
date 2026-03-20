import { beforeEach, describe, expect, it, vi } from 'vitest';

describe('collectCookiesForUrls', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
    });

    it('maps, normalizes, filters, and deduplicates browser cookies across urls', async () => {
        const getAll = vi.fn()
            .mockImplementationOnce((_: unknown, callback: (cookies: unknown[]) => void) => {
                callback([
                    {
                        name: ' session ',
                        value: 'alpha',
                        domain: ' Example.COM ',
                        path: 'library',
                        secure: true,
                        httpOnly: true,
                        hostOnly: false,
                        expirationDate: 42.9,
                    },
                    {
                        name: 'session',
                        value: 'alpha',
                        domain: 'example.com',
                        path: '/library',
                        secure: true,
                        httpOnly: true,
                        hostOnly: false,
                        expirationDate: 42.1,
                    },
                    {
                        name: '',
                        value: 'ignored',
                        domain: 'example.com',
                    },
                ]);
            })
            .mockImplementationOnce((_: unknown, callback: (cookies: unknown[]) => void) => {
                callback([
                    {
                        name: 'prefs',
                        value: 'beta',
                        domain: 'example.com',
                        path: '',
                        secure: false,
                        httpOnly: false,
                        hostOnly: true,
                    },
                ]);
            });

        vi.stubGlobal('chrome', {
            cookies: {
                getAll,
            },
            runtime: {
                lastError: null,
            },
        });

        const { collectCookiesForUrls } = await import('./background-cookie-runtime');
        const result = await collectCookiesForUrls([
            'https://example.com/post#one',
            'https://example.com/post#two',
        ]);

        expect(getAll).toHaveBeenNthCalledWith(
            1,
            { url: 'https://example.com/post#one' },
            expect.any(Function),
        );
        expect(getAll).toHaveBeenNthCalledWith(
            2,
            { url: 'https://example.com/post#two' },
            expect.any(Function),
        );
        expect(result).toEqual([
            {
                name: 'session',
                value: 'alpha',
                domain: 'example.com',
                path: '/library',
                secure: true,
                http_only: true,
                host_only: false,
                expires_at: 42,
            },
            {
                name: 'prefs',
                value: 'beta',
                domain: 'example.com',
                path: '/',
                secure: false,
                http_only: false,
                host_only: true,
                expires_at: null,
            },
        ]);
    });

    it('returns an empty list when cookie access is unavailable or the runtime reports an error', async () => {
        const { collectCookiesForUrls } = await import('./background-cookie-runtime');

        vi.stubGlobal('chrome', {
            runtime: {
                lastError: null,
            },
        });
        await expect(collectCookiesForUrls(['https://example.com/post'])).resolves.toEqual([]);

        const getAll = vi.fn((_: unknown, callback: (cookies: unknown[]) => void) => {
            const chromeRuntime = (globalThis as {
                chrome: {
                    runtime: {
                        lastError: { message: string } | null;
                    };
                };
            }).chrome.runtime;

            chromeRuntime.lastError = { message: 'cookie failure' };
            callback([
                {
                    name: 'session',
                    value: 'alpha',
                    domain: 'example.com',
                },
            ]);
            chromeRuntime.lastError = null;
        });

        vi.stubGlobal('chrome', {
            cookies: {
                getAll,
            },
            runtime: {
                lastError: null,
            },
        });

        await expect(collectCookiesForUrls(['https://example.com/post'])).resolves.toEqual([]);
    });
});
