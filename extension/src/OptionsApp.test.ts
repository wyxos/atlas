import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const mockResolveApiConnectionStatus = vi.fn();
const mockGetStoredOptions = vi.fn();
const mockSaveStoredOptions = vi.fn();
const mockClipboardWriteText = vi.fn();

vi.mock('./atlas-api', () => ({
    resolveApiConnectionStatus: mockResolveApiConnectionStatus,
}));

vi.mock('./atlas-options', () => ({
    DEFAULT_ATLAS_DOMAIN: 'https://atlas.test',
    getStoredOptions: mockGetStoredOptions,
    normalizeDomain: (value: string) => value.trim().replace(/\/+$/, ''),
    saveStoredOptions: mockSaveStoredOptions,
    validateDomain: (value: string) => (value === '' ? 'Atlas domain is required.' : null),
}));

function createStoredOptions() {
    return {
        atlasDomain: 'https://atlas.test',
        apiToken: 'test-token',
        siteCustomizations: [
            {
                enabled: true,
                domain: 'civitai.com',
                matchRules: [],
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

async function mountOptionsApp() {
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

describe('OptionsApp', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();

        mockGetStoredOptions.mockResolvedValue(createStoredOptions());
        mockSaveStoredOptions.mockResolvedValue(undefined);
        mockResolveApiConnectionStatus.mockResolvedValue({
            label: 'Ready',
            detail: 'Connected.',
            reverbLabel: 'Connected',
            reverbDetail: 'Listening.',
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
        } as typeof Blob);

        Object.defineProperty(URL, 'createObjectURL', {
            configurable: true,
            value: vi.fn(() => 'blob:atlas-test'),
        });
        Object.defineProperty(URL, 'revokeObjectURL', {
            configurable: true,
            value: vi.fn(),
        });

        mockClipboardWriteText.mockResolvedValue(undefined);
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders one domain list and switches tabs for the selected domain', async () => {
        const wrapper = await mountOptionsApp();
        await flushPromises();

        const domainButtons = wrapper.findAll('[data-test-customization-domain-button]');
        expect(domainButtons).toHaveLength(2);
        expect(domainButtons[0]?.text()).toContain('civitai.com');
        expect(domainButtons[1]?.text()).toContain('example.com');

        await domainButtons[1]!.trigger('click');
        expect((wrapper.get('[data-test-selected-customization-domain]').element as HTMLInputElement).value)
            .toBe('example.com');

        await wrapper.get('[data-test-customization-tab="referrerCleaner"]').trigger('click');
        expect(wrapper.get('[data-test-customization-panel="referrerCleaner"]').exists()).toBe(true);
        expect((wrapper.get('[data-test-referrer-cleaner-query-params]').element as HTMLTextAreaElement).value)
            .toBe('tag');

        await wrapper.get('[data-test-customization-tab="mediaCleaner"]').trigger('click');
        expect(wrapper.get('[data-test-customization-panel="mediaCleaner"]').exists()).toBe(true);
    });

    it('imports customizations and replaces the current form state', async () => {
        const wrapper = await mountOptionsApp();
        await flushPromises();

        const input = wrapper.get('[data-test-import-file-input]');
        Object.defineProperty(input.element, 'files', {
            configurable: true,
            value: [
                {
                    text: async () => JSON.stringify({
                        version: 1,
                        siteCustomizations: [
                            {
                                domain: 'imported.example.com',
                                matchRules: ['.*\\/art\\/.*'],
                                referrerCleaner: {
                                    stripQueryParams: ['tag'],
                                },
                                mediaCleaner: {
                                    stripQueryParams: ['quality'],
                                    rewriteRules: [
                                        {
                                            pattern: '/foo/',
                                            replace: 'bar',
                                        },
                                    ],
                                    strategies: ['civitaiCanonical'],
                                },
                            },
                        ],
                    }),
                },
            ],
        });

        await input.trigger('change');
        await flushPromises();

        const domainButtons = wrapper.findAll('[data-test-customization-domain-button]');
        expect(domainButtons).toHaveLength(1);
        expect(domainButtons[0]?.text()).toContain('imported.example.com');
        expect((wrapper.get('[data-test-selected-customization-domain]').element as HTMLInputElement).value)
            .toBe('imported.example.com');

        await wrapper.get('[data-test-customization-tab="mediaCleaner"]').trigger('click');
        expect((wrapper.get('[data-test-media-cleaner-query-params]').element as HTMLTextAreaElement).value)
            .toBe('quality');
        expect(wrapper.get('[data-test-media-cleaner-strategy="civitaiCanonical"]').text())
            .toContain('civitaiCanonical');
    });

    it('exports the current customization set as versioned json', async () => {
        const originalCreateElement = document.createElement.bind(document);
        const clickSpy = vi.fn();
        vi.spyOn(document, 'createElement').mockImplementation((tagName: string) => {
            const element = originalCreateElement(tagName);
            if (tagName.toLowerCase() === 'a') {
                Object.defineProperty(element, 'click', {
                    configurable: true,
                    value: clickSpy,
                });
            }

            return element;
        });

        const wrapper = await mountOptionsApp();
        await flushPromises();

        await wrapper.get('[data-test-export-customizations]').trigger('click');

        expect(URL.createObjectURL).toHaveBeenCalledTimes(1);
        const blob = vi.mocked(URL.createObjectURL).mock.calls[0]?.[0];
        expect(blob).toBeInstanceOf(Blob);
        expect(clickSpy).toHaveBeenCalledTimes(1);

        const payload = JSON.parse(String((blob as { parts: unknown[] }).parts[0])) as {
            version: number;
            siteCustomizations: Array<{ domain: string }>;
        };
        expect(payload.version).toBe(1);
        expect(payload.siteCustomizations.map((customization) => customization.domain)).toEqual([
            'civitai.com',
            'example.com',
        ]);
    });

    it('copies the current customization set to the clipboard as versioned json', async () => {
        const wrapper = await mountOptionsApp();
        await flushPromises();

        await wrapper.get('[data-test-copy-customizations]').trigger('click');
        await flushPromises();

        expect(mockClipboardWriteText).toHaveBeenCalledTimes(1);
        const copiedPayload = JSON.parse(String(mockClipboardWriteText.mock.calls[0]?.[0])) as {
            version: number;
            siteCustomizations: Array<{ domain: string }>;
        };
        expect(copiedPayload.version).toBe(1);
        expect(copiedPayload.siteCustomizations.map((customization) => customization.domain)).toEqual([
            'civitai.com',
            'example.com',
        ]);
        expect(wrapper.text()).toContain('Copied to clipboard.');
    });

    it('toggles API key visibility and renders the live connection endpoint', async () => {
        const wrapper = await mountOptionsApp();
        await flushPromises();

        const apiKeyInput = wrapper.findAll('input')
            .find((input) => input.attributes('autocomplete') === 'off');
        expect(apiKeyInput).toBeTruthy();
        expect(apiKeyInput!.attributes('type')).toBe('password');
        expect(wrapper.text()).toContain('Connected.');
        expect(wrapper.text()).toContain('wss://atlas.test/reverb');

        const toggleButton = wrapper.findAll('button')
            .find((button) => button.text() === 'Show');
        expect(toggleButton).toBeTruthy();

        await toggleButton!.trigger('click');

        expect(apiKeyInput!.attributes('type')).toBe('text');
        expect(toggleButton!.text()).toBe('Hide');
    });

    it('shows duplicate-profile validation errors from the site profile editor', async () => {
        const wrapper = await mountOptionsApp();
        await flushPromises();

        await wrapper.get('[data-test-new-customization-domain]').setValue('example.com');
        await wrapper.get('[data-test-add-customization-domain]').trigger('click');

        expect(wrapper.text()).toContain('Domain "example.com" already exists.');
        expect(wrapper.findAll('[data-test-customization-domain-button]')).toHaveLength(2);
    });

    it('saves normalized connection settings and refreshes the status cards', async () => {
        vi.useFakeTimers();

        mockResolveApiConnectionStatus.mockReset();
        mockResolveApiConnectionStatus
            .mockResolvedValueOnce({
                label: 'Ready',
                detail: 'Connected.',
                reverbLabel: 'Connected',
                reverbDetail: 'Listening.',
                reverbEndpoint: 'wss://atlas.test/reverb',
            })
            .mockResolvedValueOnce({
                label: 'Auth failed',
                detail: 'API key or domain is invalid. Update extension options.',
                reverbLabel: 'Unavailable',
                reverbDetail: 'Cannot test Reverb until API auth succeeds.',
                reverbEndpoint: null,
            });

        const wrapper = await mountOptionsApp();
        await flushPromises();

        await wrapper.get('input[type="url"]').setValue('https://atlas.example.com///');
        const apiKeyInput = wrapper.findAll('input')
            .find((input) => input.attributes('autocomplete') === 'off');
        expect(apiKeyInput).toBeTruthy();
        await apiKeyInput!.setValue(' next-token ');
        await wrapper.get('[data-test-toggle-customization-enabled]').trigger('click');

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(mockSaveStoredOptions).toHaveBeenCalledWith(
            'https://atlas.example.com',
            ' next-token ',
            [
                {
                    enabled: false,
                    domain: 'civitai.com',
                    matchRules: [],
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
        );
        expect(wrapper.text()).toContain('Saved.');
        expect(wrapper.text()).toContain('API key or domain is invalid. Update extension options.');
        expect(wrapper.text()).toContain('Cannot test Reverb until API auth succeeds.');
    });
});
