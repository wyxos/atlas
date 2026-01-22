import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('@laravel/echo-vue', () => ({
    configureEcho: vi.fn(),
    echo: vi.fn(() => {
        throw new Error('Echo misconfigured');
    }),
}));

describe('Echo setup', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div id="app"></div><div></div>';
        delete (window as { Echo?: unknown }).Echo;
        vi.resetModules();
    });

    it('loads the app module without throwing when Echo is misconfigured', async () => {
        await expect(import('./app')).resolves.toBeDefined();

        expect((window as { Echo?: unknown }).Echo).toBeUndefined();
    });
});
