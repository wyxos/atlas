import { describe, it, expect, beforeEach, vi } from 'vitest';
import { createApp } from 'vue';

vi.mock('@laravel/echo-vue', () => ({
    configureEcho: vi.fn(),
    echo: vi.fn(() => {
        throw new Error('Echo misconfigured');
    }),
}));
vi.mock('vue', () => ({
    createApp: vi.fn(() => ({
        use: vi.fn().mockReturnThis(),
        component: vi.fn().mockReturnThis(),
        mount: vi.fn(),
    })),
}));
vi.mock('vue-router', () => ({
    createRouter: vi.fn(() => ({})),
    createWebHistory: vi.fn(() => ({})),
}));
vi.mock('@oruga-ui/oruga-next', () => ({
    default: vi.fn(),
}));
vi.mock('@fortawesome/fontawesome-svg-core', () => ({
    library: { add: vi.fn() },
}));
vi.mock('@fortawesome/vue-fontawesome', () => ({
    FontAwesomeIcon: {},
}));
vi.mock('@fortawesome/free-solid-svg-icons', () => ({
    faChevronLeft: {},
    faChevronRight: {},
}));
vi.mock('./App.vue', () => ({
    default: {},
}));
vi.mock('./routes', () => ({
    default: [],
}));
vi.mock('./bootstrap', () => ({}));
vi.mock('./icons', () => ({}));

describe('Echo setup', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div id="app"></div><div></div>';
        delete (window as { Echo?: unknown }).Echo;
        vi.clearAllMocks();
        vi.resetModules();
    });

    it('loads the app module without throwing when Echo is misconfigured', async () => {
        await expect(import('./app')).resolves.toBeDefined();

        expect((window as { Echo?: unknown }).Echo).toBeUndefined();
        expect(createApp).not.toHaveBeenCalled();
    });

    it('mounts the SPA when the app container explicitly opts in', async () => {
        document.body.innerHTML = '<div id="app" data-vue-root="spa"></div><svg></svg>';

        await expect(import('./app')).resolves.toBeDefined();

        expect(createApp).toHaveBeenCalledTimes(1);
    });
});
