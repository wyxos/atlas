import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createBrowseCatalog } from './browseCatalog';

const mockAxios = {
    get: vi.fn(),
};

Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

vi.mock('@/actions/App/Http/Controllers/BrowseController', () => {
    const services = {
        url: vi.fn(() => '/api/browse/services'),
    };

    const sources = {
        url: vi.fn(() => '/api/browse/sources'),
    };

    return {
        services,
        sources,
    };
});

beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => {});
});

describe('browseCatalog', () => {
    it('initializes with empty state', () => {
        const catalog = createBrowseCatalog();

        expect(catalog.state.availableServices.value).toEqual([]);
        expect(catalog.state.availableSources.value).toEqual([]);
        expect(catalog.state.localService.value).toBeNull();
    });

    it('loads available services from API', async () => {
        const services = [
            { key: 'civit-ai-images', label: 'CivitAI Images' },
            { key: 'wallhaven', label: 'Wallhaven' },
        ];
        const localService = { key: 'local', label: 'Local' };

        mockAxios.get.mockResolvedValue({
            data: {
                services,
                local: localService,
            },
        });

        const catalog = createBrowseCatalog();

        await catalog.actions.loadServices();

        expect(mockAxios.get).toHaveBeenCalledWith('/api/browse/services', {
            headers: {
                'Cache-Control': 'no-cache',
                Pragma: 'no-cache',
            },
        });
        expect(catalog.state.availableServices.value).toEqual(services);
        expect(catalog.state.localService.value).toEqual(localService);
    });

    it('resets services to empty on load error', async () => {
        mockAxios.get.mockRejectedValueOnce(new Error('network'));

        const catalog = createBrowseCatalog();

        await catalog.actions.loadServices();

        expect(catalog.state.availableServices.value).toEqual([]);
        expect(catalog.state.localService.value).toBeNull();
    });

    it('loads available sources from API', async () => {
        mockAxios.get.mockResolvedValue({
            data: {
                sources: ['all', 'reddit'],
            },
        });

        const catalog = createBrowseCatalog();

        await catalog.actions.loadSources();

        expect(catalog.state.availableSources.value).toEqual(['all', 'reddit']);
    });

    it('falls back to all sources on load error', async () => {
        mockAxios.get.mockRejectedValueOnce(new Error('network'));

        const catalog = createBrowseCatalog();

        await catalog.actions.loadSources();

        expect(catalog.state.availableSources.value).toEqual(['all']);
    });
});
