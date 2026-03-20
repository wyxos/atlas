import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { SiteCustomizationForm } from './options-site-customization-form';

function createCustomization(overrides: Partial<SiteCustomizationForm> = {}): SiteCustomizationForm {
    return {
        domain: overrides.domain ?? 'example.com',
        matchRules: overrides.matchRules ?? [],
        referrerCleanerQueryParamsText: overrides.referrerCleanerQueryParamsText ?? '',
        mediaCleanerQueryParamsText: overrides.mediaCleanerQueryParamsText ?? '',
        mediaCleanerRewriteRules: overrides.mediaCleanerRewriteRules ?? [],
        mediaCleanerStrategies: overrides.mediaCleanerStrategies ?? [],
    };
}

async function mountManager(
    overrides: Partial<{
        activeCustomizationTab: 'matchRules' | 'referrerCleaner' | 'mediaCleaner';
        customizations: SiteCustomizationForm[];
        isCustomizationJsonCopied: boolean;
        newCustomizationDomain: string;
        selectedCustomizationIndex: number;
    }> = {},
) {
    const component = await import('./SiteCustomizationManager.vue');

    return mount(component.default, {
        props: {
            customizations: overrides.customizations ?? [],
            selectedCustomizationIndex: overrides.selectedCustomizationIndex ?? 0,
            activeCustomizationTab: overrides.activeCustomizationTab ?? 'matchRules',
            newCustomizationDomain: overrides.newCustomizationDomain ?? '',
            isCustomizationJsonCopied: overrides.isCustomizationJsonCopied ?? false,
            mediaCleanerStrategies: ['civitaiCanonical'],
        },
        global: {
            stubs: {
                Trash2: {
                    template: '<svg />',
                },
            },
        },
    });
}

describe('SiteCustomizationManager', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
    });

    it('renders the empty state and emits toolbar actions', async () => {
        const wrapper = await mountManager({ isCustomizationJsonCopied: true });

        expect(wrapper.text()).toContain('Add a domain to start building a site profile.');
        expect(wrapper.text()).toContain('Copied to clipboard.');

        const fileInput = wrapper.get('[data-test-import-file-input]');
        const clickSpy = vi.fn();
        Object.defineProperty(fileInput.element, 'click', {
            configurable: true,
            value: clickSpy,
        });

        await wrapper.get('[data-test-new-customization-domain]').setValue('new.example.com');
        await wrapper.get('[data-test-add-customization-domain]').trigger('click');
        await wrapper.get('[data-test-copy-customizations]').trigger('click');
        await wrapper.get('[data-test-export-customizations]').trigger('click');
        await wrapper.get('[data-test-import-customizations]').trigger('click');
        await fileInput.trigger('change');

        expect(wrapper.emitted('update:newCustomizationDomain')?.at(-1)).toEqual(['new.example.com']);
        expect(wrapper.emitted('add-customization-domain')).toHaveLength(1);
        expect(wrapper.emitted('copy-customizations')).toHaveLength(1);
        expect(wrapper.emitted('export-customizations')).toHaveLength(1);
        expect(clickSpy).toHaveBeenCalledTimes(1);
        expect(wrapper.emitted('import-customizations')).toHaveLength(1);
    });

    it('emits selection, tab, and editor actions for the selected profile', async () => {
        const wrapper = await mountManager({
            customizations: [
                createCustomization({
                    domain: 'example.com',
                    matchRules: ['.*\\/gallery\\/.*'],
                    mediaCleanerRewriteRules: [
                        {
                            pattern: 'foo',
                            replace: 'bar',
                        },
                    ],
                }),
                createCustomization({ domain: 'civitai.com' }),
            ],
        });

        const domainButtons = wrapper.findAll('[data-test-customization-domain-button]');
        expect(domainButtons).toHaveLength(2);

        await domainButtons[1]!.trigger('click');
        await wrapper.get('[data-test-customization-tab="referrerCleaner"]').trigger('click');
        await wrapper.get('[data-test-add-match-rule]').trigger('click');
        await wrapper.get('[data-test-remove-customization-domain="example.com"]').trigger('click');

        expect(wrapper.emitted('update:selectedCustomizationIndex')?.at(-1)).toEqual([1]);
        expect(wrapper.emitted('update:activeCustomizationTab')?.at(-1)).toEqual(['referrerCleaner']);
        expect(wrapper.emitted('add-match-rule')).toHaveLength(1);
        expect(wrapper.emitted('remove-customization')?.at(-1)).toEqual([0]);
    });

    it('shows the media cleaner editor states and emits rewrite-rule actions', async () => {
        const wrapper = await mountManager({
            activeCustomizationTab: 'mediaCleaner',
            customizations: [
                createCustomization({
                    domain: 'example.com',
                    mediaCleanerStrategies: ['civitaiCanonical'],
                    mediaCleanerRewriteRules: [],
                }),
            ],
        });

        expect(wrapper.text()).toContain('No rewrite rules yet. Atlas will stop after strategies and query stripping.');
        expect(wrapper.get('[data-test-media-cleaner-strategy="civitaiCanonical"]').text()).toContain('Enabled');

        await wrapper.get('[data-test-media-cleaner-strategy="civitaiCanonical"]').trigger('click');
        await wrapper.get('[data-test-add-media-rewrite-rule]').trigger('click');
        expect(wrapper.emitted('toggle-media-cleaner-strategy')?.at(-1)).toEqual(['civitaiCanonical']);
        expect(wrapper.emitted('add-media-rewrite-rule')).toHaveLength(1);

        const wrapperWithRule = await mountManager({
            activeCustomizationTab: 'mediaCleaner',
            customizations: [
                createCustomization({
                    domain: 'example.com',
                    mediaCleanerRewriteRules: [
                        {
                            pattern: 'foo',
                            replace: 'bar',
                        },
                    ],
                }),
            ],
        });

        expect(wrapperWithRule.text()).toContain('Pattern');
        expect(wrapperWithRule.text()).toContain('Replace');
        await wrapperWithRule.findAll('button')
            .find((button) => button.attributes('title') === 'Delete rewrite rule')!
            .trigger('click');
        expect(wrapperWithRule.emitted('remove-media-rewrite-rule')?.at(-1)).toEqual([0]);
    });
});
