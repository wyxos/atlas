import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import ModerationRulesManager from './ModerationRulesManager.vue';
import type { ModerationRule } from '@/types/moderation';

vi.mock('@/components/ui/button', () => ({
    Button: {
        name: 'Button',
        props: ['variant', 'color', 'size', 'disabled', 'loading'],
        template: `
            <button
                :disabled="disabled || loading"
                :data-variant="variant"
                :data-color="color"
            >
                <slot />
            </button>
        `,
    },
}));

vi.mock('@/components/ui/input', () => ({
    Input: {
        name: 'Input',
        props: ['modelValue', 'placeholder'],
        emits: ['update:modelValue'],
        template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    },
}));

vi.mock('@/components/ui/switch', () => ({
    Switch: {
        name: 'Switch',
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template: '<button type="button" role="switch" @click="$emit(\'update:modelValue\', !modelValue)">{{ modelValue }}</button>',
    },
}));

vi.mock('@/components/ui/select', () => ({
    Select: {
        name: 'Select',
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template: '<div><slot /></div>',
    },
    SelectTrigger: {
        name: 'SelectTrigger',
        template: '<button><slot /></button>',
    },
    SelectValue: {
        name: 'SelectValue',
        template: '<span></span>',
    },
    SelectContent: {
        name: 'SelectContent',
        template: '<div><slot /></div>',
    },
    SelectItem: {
        name: 'SelectItem',
        props: ['value'],
        template: '<div><slot /></div>',
    },
}));

vi.mock('@/components/ui/dialog', () => ({
    Dialog: {
        name: 'Dialog',
        props: ['open'],
        emits: ['update:open'],
        template: '<div><slot /></div>',
    },
    DialogContent: {
        name: 'DialogContent',
        template: '<section><slot /></section>',
    },
    DialogDescription: {
        name: 'DialogDescription',
        template: '<p><slot /></p>',
    },
    DialogHeader: {
        name: 'DialogHeader',
        template: '<header><slot /></header>',
    },
    DialogTitle: {
        name: 'DialogTitle',
        template: '<h2><slot /></h2>',
    },
}));

vi.mock('lucide-vue-next', () => ({
    AlertTriangle: { template: '<span />' },
    Loader2: { template: '<span />' },
    Plus: { template: '<span />' },
    Shield: { template: '<span />' },
    Trash2: { template: '<span />' },
}));

function createRule(overrides: Partial<ModerationRule> = {}): ModerationRule {
    return {
        id: 1,
        name: 'Feed removal rule',
        active: true,
        nsfw: false,
        action_type: 'blacklist',
        blacklist_previewed_count_mode: 'feed_removed',
        op: 'any',
        terms: [{ term: 'blocked', allow_digit_prefix: false }],
        min: null,
        options: { case_sensitive: false, whole_word: true },
        children: null,
        created_at: '2026-05-25T00:00:00Z',
        updated_at: '2026-05-25T00:00:00Z',
        ...overrides,
    };
}

function installAxios(rules: ModerationRule[]): void {
    (window as unknown as { axios: Record<string, ReturnType<typeof vi.fn>> }).axios = {
        get: vi.fn().mockResolvedValue({ data: rules }),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    };
}

function mountManager(): VueWrapper {
    return mount(ModerationRulesManager, {
        global: {
            stubs: {
                RuleEditor: {
                    name: 'RuleEditor',
                    props: ['modelValue'],
                    template: '<div data-test="rule-editor"></div>',
                },
            },
        },
    });
}

async function openDialog(wrapper: VueWrapper): Promise<void> {
    await wrapper.get('[data-test="moderation-rules-button"]').trigger('click');
    await flushPromises();
    await nextTick();
}

describe('ModerationRulesManager', () => {
    beforeEach(() => {
        installAxios([createRule()]);
    });

    it('uses danger styling for the browse header toolbar trigger', () => {
        const wrapper = mountManager();
        const trigger = wrapper.get('[data-test="moderation-rules-button"]');

        expect(trigger.attributes('data-variant')).toBe('ghost');
        expect(trigger.attributes('data-color')).toBe('danger');
    });

    it('keeps the create and edit action rows sticky at the modal bottom', async () => {
        const wrapper = mountManager();

        await openDialog(wrapper);
        await wrapper.get('[data-test="moderation-rules-add-new-button"]').trigger('click');

        let actionRow = wrapper.get('[data-test="moderation-rules-action-row"]');
        expect(actionRow.classes()).toContain('sticky');
        expect(actionRow.classes()).toContain('bottom-0');
        expect(actionRow.text()).toContain('Create Rule');
        expect(actionRow.text()).toContain('Close');
        expect(actionRow.element.parentElement?.classList.contains('flex-col')).toBe(true);
        expect(actionRow.element.previousElementSibling?.classList.contains('flex-1')).toBe(true);

        await wrapper.get('[data-test="moderation-rule-list-item-1"]').trigger('click');

        actionRow = wrapper.get('[data-test="moderation-rules-action-row"]');
        expect(actionRow.classes()).toContain('sticky');
        expect(actionRow.classes()).toContain('bottom-0');
        expect(actionRow.text()).toContain('Save Changes');
        expect(actionRow.text()).toContain('Close');
    });

    it('renders the previewed count editor value as a human-readable label', async () => {
        const wrapper = mountManager();

        await openDialog(wrapper);
        await wrapper.get('[data-test="moderation-rule-list-item-1"]').trigger('click');

        const trigger = wrapper.get('[data-test="previewed-count-select-trigger"]');

        expect(trigger.text()).toContain('Blacklist, set to 99,999');
        expect(trigger.text()).not.toContain('feed_removed');
    });

    it('renders rule list previewed count badges without raw mode values', async () => {
        const wrapper = mountManager();

        await openDialog(wrapper);

        const rule = wrapper.get('[data-test="moderation-rule-list-item-1"]');

        expect(rule.text()).toContain('99,999');
        expect(rule.text()).not.toContain('feed_removed');
    });
});
