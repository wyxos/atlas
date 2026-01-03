import { describe, it, expect, vi, beforeEach } from 'vitest';
import { useBrowseService } from './useBrowseService';

// Mock axios
const mockAxios = {
    get: vi.fn(),
};

Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

// Mock browseIndex and browseServices actions
vi.mock('@/actions/App/Http/Controllers/BrowseController', () => {
    const services = {
        url: vi.fn(() => '/api/browse/services'),
    };

    const sources = {
        url: vi.fn(() => '/api/browse/sources'),
    };

    const index = {
        url: vi.fn((params: any) => {
            const query = new URLSearchParams(params?.query || {}).toString();
            return `/api/browse?${query}`;
        }),
    };

    return {
        default: {
            index,
            services,
            sources,
        },
        index,
        services,
        sources,
    };
});

beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => {});
});

describe('useBrowseService', () => {
    it('initializes with empty state', () => {
        const { availableServices } = useBrowseService();

        expect(availableServices.value).toEqual([]);
    });

    it('fetches available services from API', async () => {
        const mockServices = [
            { key: 'civit-ai-images', label: 'CivitAI Images' },
            { key: 'wallhaven', label: 'Wallhaven' },
        ];

        mockAxios.get.mockResolvedValue({
            data: {
                items: [],
                services: mockServices,
            },
        });

        const { fetchServices, availableServices } = useBrowseService();

        await fetchServices();

        expect(mockAxios.get).toHaveBeenCalled();
        expect(availableServices.value).toEqual(mockServices);
    });

    it('resets services to empty on fetch error', async () => {
        mockAxios.get.mockRejectedValueOnce(new Error('network'));

        const { fetchServices, availableServices } = useBrowseService();

        await fetchServices();

        expect(availableServices.value).toEqual([]);
    });

    it('gets current service from tab query params', () => {
        const { getCurrentService } = useBrowseService();

        expect(getCurrentService({ service: 'civit-ai-images' })).toBe('civit-ai-images');
        expect(getCurrentService({})).toBeNull();
        expect(getCurrentService()).toBeNull();
    });
});
