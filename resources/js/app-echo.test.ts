import { describe, it, expect, beforeEach, vi } from 'vitest';

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
vi.mock('vue-toastification', () => ({
    default: vi.fn(),
    POSITION: { BOTTOM_RIGHT: 'bottom-right' },
}));
vi.mock('@fortawesome/fontawesome-svg-core', () => ({
    library: { add: vi.fn() },
}));
vi.mock('@fortawesome/vue-fontawesome', () => ({
    FontAwesomeIcon: {},
}));
vi.mock('@fortawesome/free-solid-svg-icons', () => ({
    fas: {},
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
        vi.resetModules();
    });

    it('loads the app module without throwing when Echo is misconfigured', async () => {
        await expect(import('./app')).resolves.toBeDefined();

        expect((window as { Echo?: unknown }).Echo).toBeUndefined();
    });
});
