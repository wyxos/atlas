import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    createStoredOptions,
    mockClipboardWriteText,
    mockGetStoredOptions,
    mockResolveApiConnectionStatus,
    mockSaveSiteCustomizationsForCurrentConnection,
    mockSaveStoredConnectionOptions,
    mountOptionsApp,
    setupOptionsAppTestEnvironment,
} from './options-app-test-utils';

describe('OptionsApp', () => {
    beforeEach(() => {
        setupOptionsAppTestEnvironment();
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

        await wrapper.get('[data-test-customization-tab="widget"]').trigger('click');
        expect(wrapper.get('[data-test-customization-panel="widget"]').exists()).toBe(true);
        expect((wrapper.get('[data-test-widget-min-image-width]').element as HTMLInputElement).value)
            .toBe('160');
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
                                widget: {
                                    minImageWidth: 90,
                                },
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

        await wrapper.get('[data-test-customization-tab="widget"]').trigger('click');
        expect((wrapper.get('[data-test-widget-min-image-width]').element as HTMLInputElement).value)
            .toBe('90');

        await wrapper.get('[data-test-customization-tab="mediaCleaner"]').trigger('click');
        expect((wrapper.get('[data-test-media-cleaner-query-params]').element as HTMLTextAreaElement).value)
            .toBe('quality');
        expect(wrapper.get('[data-test-media-cleaner-strategy="civitaiCanonical"]').text())
            .toContain('Civitai canonical');
    });

    it('keeps profiles visible while editing widget width and saves the sanitized value', async () => {
        const wrapper = await mountOptionsApp();
        await flushPromises();

        await wrapper.findAll('[data-test-customization-domain-button]')[1]!.trigger('click');
        await wrapper.get('[data-test-customization-tab="widget"]').trigger('click');

        const input = wrapper.get('[data-test-widget-min-image-width]');
        await input.setValue('170px');

        expect((input.element as HTMLInputElement).value).toBe('170');
        expect(wrapper.findAll('[data-test-customization-domain-button]')).toHaveLength(2);

        await wrapper.get('[data-test-widget-min-image-width-increment]').trigger('click');

        expect((input.element as HTMLInputElement).value).toBe('171');
        expect(wrapper.findAll('[data-test-customization-domain-button]')).toHaveLength(2);

        await wrapper.get('[data-test-save-profiles]').trigger('click');
        await flushPromises();

        expect(mockSaveSiteCustomizationsForCurrentConnection).toHaveBeenCalledWith(
            expect.arrayContaining([
                expect.objectContaining({
                    domain: 'example.com',
                    widget: {
                        minImageWidth: 171,
                    },
                }),
            ]),
        );
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

    it('toggles API key visibility and keeps runtime status concise', async () => {
        const wrapper = await mountOptionsApp();
        await flushPromises();

        const apiKeyInput = wrapper.findAll('input')
            .find((input) => input.attributes('autocomplete') === 'off');
        expect(apiKeyInput).toBeTruthy();
        expect(apiKeyInput!.attributes('type')).toBe('password');

        const toggleButton = wrapper.findAll('button')
            .find((button) => button.text() === 'Show');
        expect(toggleButton).toBeTruthy();

        await toggleButton!.trigger('click');

        expect(apiKeyInput!.attributes('type')).toBe('text');
        expect(toggleButton!.text()).toBe('Hide');

        await wrapper.get('[data-test-options-tab="runtime"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Ready for extension requests.');
        expect(wrapper.text()).toContain('Reverb config is available.');
        expect(wrapper.text()).not.toContain('wss://atlas.test/reverb');
    });

    it('uses a full-width tabbed layout without redundant status summary copy', async () => {
        const wrapper = await mountOptionsApp();
        await flushPromises();

        expect(wrapper.get('[data-test-options-shell]').classes()).not.toContain('mx-auto');
        expect(wrapper.get('[data-test-extension-options-tabs]').exists()).toBe(true);
        expect(wrapper.get('[data-test-extension-options-tab-list]').classes()).toContain('border-smart-blue-500/25');
        expect(wrapper.get('[data-test-extension-options-tab-list]').classes()).toContain('!bg-prussian-blue-900/35');
        expect(wrapper.text()).toContain('Setup');
        expect(wrapper.text()).toContain('Runtime');
        expect(wrapper.text()).not.toContain('Connection Status');
        expect(wrapper.text()).not.toContain('Realtime Channel');
        expect(wrapper.text()).not.toContain('Connected to https://atlas.test');
        expect(wrapper.text()).not.toContain('https://atlas.test:443');
        expect(wrapper.text()).not.toContain('Extension Build');
    });

    it('keeps rounded surfaces small on the extension options screen', async () => {
        const wrapper = await mountOptionsApp();
        await flushPromises();

        expect(wrapper.html()).not.toMatch(/rounded-(?:2xl|xl|lg|md|full|\[[^\]]+\])/);
        expect(wrapper.html()).toContain('rounded-sm');
    });

    it('searches and filters the profile list without summary chips', async () => {
        mockGetStoredOptions.mockResolvedValue({
            ...createStoredOptions(),
            siteCustomizations: [
                ...createStoredOptions().siteCustomizations,
                {
                    enabled: false,
                    domain: 'deviantart.com',
                    matchRules: ['.*\\/art\\/.*'],
                    widget: {
                        minImageWidth: null,
                    },
                    referrerCleaner: {
                        stripQueryParams: ['token', 'signature'],
                    },
                    mediaCleaner: {
                        stripQueryParams: ['token'],
                        rewriteRules: [],
                        strategies: [],
                    },
                },
            ],
        });

        const wrapper = await mountOptionsApp();
        await flushPromises();

        const list = wrapper.get('[data-test-customization-domain-list]');
        expect(list.text()).toContain('civitai.com');
        expect(list.text()).toContain('example.com');
        expect(list.text()).toContain('deviantart.com');
        expect(list.text()).not.toContain('Enabled');
        expect(list.text()).not.toContain('Disabled');
        expect(list.findAll('[data-test-profile-status-icon]')).toHaveLength(3);
        expect(list.get('[data-test-profile-status-icon="deviantart.com"]').attributes('aria-label'))
            .toBe('Disabled profile');
        expect(list.text()).not.toContain('match rule');
        expect(list.text()).not.toContain('referrer param');
        expect(list.text()).not.toContain('media strategy');

        await wrapper.get('[data-test-profile-search]').setValue('deviant');

        expect(wrapper.findAll('[data-test-customization-domain-button]')).toHaveLength(1);
        expect(wrapper.get('[data-test-customization-domain-button="deviantart.com"]').exists()).toBe(true);

        await wrapper.get('[data-test-profile-search]').setValue('');
        await wrapper.get('[data-test-profile-status-filter="disabled"]').trigger('click');

        expect(wrapper.findAll('[data-test-customization-domain-button]')).toHaveLength(1);
        expect(wrapper.get('[data-test-customization-domain-button="deviantart.com"]').exists()).toBe(true);

        await wrapper.get('[data-test-profile-status-filter="enabled"]').trigger('click');

        expect(wrapper.findAll('[data-test-customization-domain-button]')).toHaveLength(2);
        expect(wrapper.find('[data-test-customization-domain-button="deviantart.com"]').exists()).toBe(false);
    });

    it('uses a grouped switch and delete action without redundant selected profile summaries', async () => {
        const wrapper = await mountOptionsApp();
        await flushPromises();

        const actions = wrapper.get('[data-test-profile-actions]');
        const switchControl = actions.get('[data-test-toggle-customization-enabled]');

        expect(switchControl.attributes('role')).toBe('switch');
        expect(switchControl.attributes('aria-checked')).toBe('true');
        expect(switchControl.text()).not.toContain('Disable Profile');
        expect(actions.text()).not.toContain('Enabled');
        expect(actions.text()).not.toContain('Disabled');
        expect(actions.get('[data-test-remove-customization-domain="civitai.com"]').exists()).toBe(true);
        expect(wrapper.get('[data-test-customization-editor]').text()).not.toContain('Disabled profiles stay saved');
        expect(wrapper.get('[data-test-customization-editor]').text()).not.toContain('media strategy');

        await switchControl.trigger('click');

        expect(switchControl.attributes('aria-checked')).toBe('false');
    });

    it('simplifies media cleaner copy and keeps site presets domain-specific', async () => {
        const wrapper = await mountOptionsApp();
        await flushPromises();

        await wrapper.findAll('[data-test-customization-domain-button]')[1]!.trigger('click');
        await wrapper.get('[data-test-customization-tab="mediaCleaner"]').trigger('click');

        const exampleCleaner = wrapper.get('[data-test-customization-panel="mediaCleaner"]');
        const activePanel = wrapper.get('[data-test-customization-active-panel]');
        expect(activePanel.classes()).not.toContain('rounded-sm');
        expect(activePanel.classes()).not.toContain('border');
        expect(exampleCleaner.text()).not.toContain('Media cleaner');
        expect(activePanel.text()).toContain('duplicate checks');
        expect(activePanel.text()).toContain('saved records');
        expect(exampleCleaner.text()).toContain('Remove query params');
        expect(exampleCleaner.text()).toContain('Rewrite URL text');
        expect(exampleCleaner.text()).not.toContain('Named Strategies');
        expect(exampleCleaner.text()).not.toContain('Civitai canonical');
        expect(exampleCleaner.text()).not.toContain('civitaiCanonical');
        expect(exampleCleaner.text()).not.toContain('Cleaner order');

        await wrapper.findAll('[data-test-customization-domain-button]')[0]!.trigger('click');
        await wrapper.get('[data-test-customization-tab="mediaCleaner"]').trigger('click');

        expect(wrapper.get('[data-test-media-cleaner-strategy="civitaiCanonical"]').text())
            .toContain('Civitai canonical');
    });

    it('does not repeat selected customization tab labels as panel headings', async () => {
        const wrapper = await mountOptionsApp();
        await flushPromises();

        for (const tab of ['matchRules', 'widget', 'referrerCleaner', 'mediaCleaner']) {
            await wrapper.get(`[data-test-customization-tab="${tab}"]`).trigger('click');

            const tabLabel = wrapper.get(`[data-test-customization-tab="${tab}"]`).text().trim();
            const editorHeadings = wrapper
                .get('[data-test-customization-editor]')
                .findAll('h4, h5')
                .map((heading) => heading.text().trim());

            expect(editorHeadings).not.toContain(tabLabel);
        }
    });

    it('marks unavailable reverb status as a danger state without repeating the endpoint', async () => {
        mockResolveApiConnectionStatus.mockResolvedValue({
            label: 'Ready',
            detail: 'Connected to https://atlas.test',
            reverbLabel: 'Unavailable',
            reverbDetail: 'Reverb is not configured on Atlas.',
            reverbEndpoint: 'https://atlas.test:443',
        });

        const wrapper = await mountOptionsApp();
        await flushPromises();

        await wrapper.get('[data-test-options-tab="runtime"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-test-reverb-status-detail]').classes()).toContain('text-danger-100');
        expect(wrapper.text()).toContain('Reverb is not configured on Atlas.');
        expect(wrapper.text()).not.toContain('https://atlas.test:443');
    });

    it('shows duplicate-profile validation errors from the site profile editor', async () => {
        const wrapper = await mountOptionsApp();
        await flushPromises();

        await wrapper.get('[data-test-new-customization-domain]').setValue('example.com');
        await wrapper.get('[data-test-add-customization-domain]').trigger('click');

        expect(wrapper.text()).toContain('Domain "example.com" already exists.');
        expect(wrapper.findAll('[data-test-customization-domain-button]')).toHaveLength(2);
    });

    it('saves normalized connection settings without saving profile changes', async () => {
        vi.useFakeTimers();

        mockResolveApiConnectionStatus.mockReset();
        mockResolveApiConnectionStatus
            .mockResolvedValueOnce({
                label: 'Ready',
                detail: 'Connected.',
                reverbLabel: 'Available',
                reverbDetail: 'Reverb config is available.',
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

        await wrapper.get('[data-test-save-connection]').trigger('click');
        await flushPromises();

        expect(mockSaveStoredConnectionOptions).toHaveBeenCalledWith(
            'https://atlas.example.com',
            ' next-token ',
        );
        expect(mockSaveSiteCustomizationsForCurrentConnection).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('Connection saved.');

        await wrapper.get('[data-test-options-tab="runtime"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('API key or domain is invalid. Update extension options.');
        expect(wrapper.text()).toContain('Cannot test Reverb until API auth succeeds.');
    });
});
