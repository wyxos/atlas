import { mount } from '@vue/test-utils';
import { vi } from 'vitest';

export const mockResolveApiConnectionStatus = vi.fn();
export const mockGetStoredOptions = vi.fn();
export const mockSaveSiteCustomizationsForCurrentConnection = vi.fn();
export const mockSaveStoredConnectionOptions = vi.fn();
export const mockClipboardWriteText = vi.fn();

vi.mock('./atlas-api', () => ({
    resolveApiConnectionStatus: mockResolveApiConnectionStatus,
}));

vi.mock('./atlas-options', () => ({
    DEFAULT_ATLAS_DOMAIN: 'https://atlas.test',
    getStoredOptions: mockGetStoredOptions,
    normalizeDomain: (value: string) => value.trim().replace(/\/+$/, ''),
    saveSiteCustomizationsForCurrentConnection: mockSaveSiteCustomizationsForCurrentConnection,
    saveStoredConnectionOptions: mockSaveStoredConnectionOptions,
    validateDomain: (value: string) => (value === '' ? 'Atlas domain is required.' : null),
}));

export function createStoredOptions() {
    return {
        atlasDomain: 'https://atlas.test',
        apiToken: 'test-token',
        siteCustomizations: [
            {
                enabled: true,
                domain: 'civitai.com',
                matchRules: [],
                widget: {
                    minImageWidth: null,
                },
                referrerCleaner: {
                    stripQueryParams: [],
                },
                mediaCleaner: {
                    stripQueryParams: [],
                    rewriteRules: [],
                    strategies: ['civitaiCanonical'],
                },
            },
            {
                enabled: true,
                domain: 'example.com',
                matchRules: ['.*\\/gallery\\/.*'],
                widget: {
                    minImageWidth: 160,
                },
                referrerCleaner: {
                    stripQueryParams: ['tag'],
                },
                mediaCleaner: {
                    stripQueryParams: [],
                    rewriteRules: [],
                    strategies: [],
                },
            },
        ],
    };
}

export function setupOptionsAppTestEnvironment(): void {
    vi.resetModules();
    vi.clearAllMocks();
    vi.unstubAllGlobals();

    mockGetStoredOptions.mockResolvedValue(createStoredOptions());
    mockSaveSiteCustomizationsForCurrentConnection.mockResolvedValue(createStoredOptions());
    mockSaveStoredConnectionOptions.mockResolvedValue(createStoredOptions());
    mockResolveApiConnectionStatus.mockResolvedValue({
        label: 'Ready',
        detail: 'Connected.',
        reverbLabel: 'Available',
        reverbDetail: 'Reverb config is available.',
        reverbEndpoint: 'wss://atlas.test/reverb',
    });

    vi.stubGlobal('chrome', {
        runtime: {
            getManifest: () => ({
                version: '1.2.3',
            }),
        },
    });
    Object.defineProperty(window.navigator, 'clipboard', {
        configurable: true,
        value: {
            writeText: mockClipboardWriteText,
        },
    });
    vi.stubGlobal('Blob', class MockBlob {
        parts: unknown[];
        type: string;

        constructor(parts: unknown[], options?: { type?: string }) {
            this.parts = parts;
            this.type = options?.type ?? '';
        }
    } as unknown as typeof Blob);

    Object.defineProperty(URL, 'createObjectURL', {
        configurable: true,
        value: vi.fn(() => 'blob:atlas-test'),
    });
    Object.defineProperty(URL, 'revokeObjectURL', {
        configurable: true,
        value: vi.fn(),
    });

    mockClipboardWriteText.mockResolvedValue(undefined);
}

export async function mountOptionsApp() {
    const component = await import('./OptionsApp.vue');

    return mount(component.default, {
        global: {
            stubs: {
                Badge: {
                    template: '<span><slot /></span>',
                },
                OptionsBackgroundRelayFeed: {
                    template: '<div />',
                },
                OptionsReverbFeed: {
                    template: '<div />',
                },
            },
        },
    });
}
