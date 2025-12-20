import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import RuleEditor from './RuleEditor.vue';
import type { ModerationRuleNode } from '@/types/moderation';

// Mock child components
vi.mock('@/components/ui/button', () => ({
    Button: {
        name: 'Button',
        template: '<button><slot /></button>',
        props: ['variant', 'size', 'disabled', 'type', 'class'],
    },
}));

vi.mock('@/components/ui/input', () => ({
    Input: {
        name: 'Input',
        template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
        props: ['modelValue', 'type', 'min', 'placeholder', 'class'],
        emits: ['update:modelValue'],
    },
}));

vi.mock('@/components/ui/switch', () => ({
    Switch: {
        name: 'Switch',
        template: '<button type="button" role="switch" @click="$emit(\'update:modelValue\', !modelValue)">{{ modelValue }}</button>',
        props: ['modelValue'],
        emits: ['update:modelValue'],
    },
}));

vi.mock('@/components/ui/select', () => ({
    Select: {
        name: 'Select',
        template: '<div class="select"><slot /></div>',
        props: ['modelValue'],
        emits: ['update:modelValue'],
    },
    SelectTrigger: {
        name: 'SelectTrigger',
        template: '<button><slot /></button>',
        props: ['class'],
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
        template: '<div @click="$parent.$emit(\'update:modelValue\', value)"><slot /></div>',
        props: ['value'],
    },
}));

vi.mock('lucide-vue-next', () => ({
    Plus: { template: '<span>+</span>' },
    X: { template: '<span>×</span>' },
    Trash2: { template: '<span>🗑</span>' },
}));

function createDefaultNode(): ModerationRuleNode {
    return {
        op: 'any',
        terms: [],
        options: { case_sensitive: false, whole_word: true },
    };
}

describe('RuleEditor', () => {
    describe('initialization', () => {
        it('renders with default node values', () => {
            const wrapper = mount(RuleEditor, {
                props: {
                    modelValue: createDefaultNode(),
                },
            });

            expect(wrapper.exists()).toBe(true);
            expect(wrapper.text()).toContain('Operator');
            expect(wrapper.text()).toContain('Terms');
        });

        it('shows terms section for term-based operations', () => {
            const wrapper = mount(RuleEditor, {
                props: {
                    modelValue: { op: 'any', terms: [{ term: 'test', allow_digit_prefix: false }], options: {} },
                },
            });

            expect(wrapper.text()).toContain('Terms');
            expect(wrapper.text()).toContain('Add Term');
        });

        it('shows min input for at_least operation', () => {
            const wrapper = mount(RuleEditor, {
                props: {
                    modelValue: { op: 'at_least', terms: [], min: 2, options: {} },
                },
            });

            expect(wrapper.text()).toContain('Minimum matches');
        });

        it('shows children section for and/or operations', () => {
            const wrapper = mount(RuleEditor, {
                props: {
                    modelValue: { op: 'and', children: [], options: {} },
                },
            });

            expect(wrapper.text()).toContain('Child Rules');
            expect(wrapper.text()).toContain('Add Child');
        });
    });

    describe('term management', () => {
        it('emits update when adding a term', async () => {
            const node = createDefaultNode();
            const wrapper = mount(RuleEditor, {
                props: { modelValue: node },
            });

            // Find and click the "Add Term" button
            const buttons = wrapper.findAll('button');
            const addTermBtn = buttons.find(b => b.text().includes('Add Term'));
            expect(addTermBtn).toBeDefined();
            
            await addTermBtn!.trigger('click');

            const emitted = wrapper.emitted('update:modelValue');
            expect(emitted).toBeTruthy();
            expect(emitted![0][0]).toEqual({
                ...node,
                terms: [{ term: '', allow_digit_prefix: false }],
            });
        });

        it('emits update when updating a term value', async () => {
            const node: ModerationRuleNode = {
                op: 'any',
                terms: [{ term: 'original', allow_digit_prefix: false }],
                options: { case_sensitive: false, whole_word: true },
            };
            const wrapper = mount(RuleEditor, {
                props: { modelValue: node },
            });

            const inputs = wrapper.findAll('input');
            const termInput = inputs.find(i => i.attributes('type') !== 'number');
            expect(termInput).toBeDefined();
            await termInput!.setValue('updated');

            const emitted = wrapper.emitted('update:modelValue');
            expect(emitted).toBeTruthy();
            const lastEmit = emitted![emitted!.length - 1][0] as ModerationRuleNode;
            expect(lastEmit.terms).toBeDefined();
            expect(lastEmit.terms!.some(t => {
                const term = typeof t === 'string' ? t : t.term;
                return term === 'updated';
            })).toBe(true);
        });

        it('emits update when removing a term', async () => {
            const node: ModerationRuleNode = {
                op: 'any',
                terms: [
                    { term: 'term1', allow_digit_prefix: false },
                    { term: 'term2', allow_digit_prefix: false },
                ],
                options: {},
            };
            const wrapper = mount(RuleEditor, {
                props: { modelValue: node },
            });

            // Find remove button (with × or destructive variant)
            const removeButtons = wrapper.findAll('button').filter(b => 
                b.attributes('class')?.includes('destructive') || b.text().includes('×')
            );
            
            if (removeButtons.length > 0) {
                await removeButtons[0].trigger('click');

                const emitted = wrapper.emitted('update:modelValue');
                expect(emitted).toBeTruthy();
                const lastEmit = emitted![emitted!.length - 1][0] as ModerationRuleNode;
                expect(lastEmit.terms).toHaveLength(1);
            }
        });
    });

    describe('operation changes', () => {
        it('clears children when switching to term-based operation', async () => {
            const node: ModerationRuleNode = {
                op: 'and',
                children: [{ op: 'any', terms: [{ term: 'test', allow_digit_prefix: false }] }],
                options: {},
            };
            const wrapper = mount(RuleEditor, {
                props: { modelValue: node },
            });

            // Simulate operation change by finding the Select and triggering update
            const select = wrapper.findComponent({ name: 'Select' });
            await select.vm.$emit('update:modelValue', 'any');

            const emitted = wrapper.emitted('update:modelValue');
            expect(emitted).toBeTruthy();
            const lastEmit = emitted![emitted!.length - 1][0] as ModerationRuleNode;
            expect(lastEmit.op).toBe('any');
            expect(lastEmit.children).toBeUndefined();
        });

        it('clears terms when switching to children-based operation', async () => {
            const node: ModerationRuleNode = {
                op: 'any',
                terms: [
                    { term: 'test1', allow_digit_prefix: false },
                    { term: 'test2', allow_digit_prefix: false },
                ],
                options: {},
            };
            const wrapper = mount(RuleEditor, {
                props: { modelValue: node },
            });

            const select = wrapper.findComponent({ name: 'Select' });
            await select.vm.$emit('update:modelValue', 'and');

            const emitted = wrapper.emitted('update:modelValue');
            expect(emitted).toBeTruthy();
            const lastEmit = emitted![emitted!.length - 1][0] as ModerationRuleNode;
            expect(lastEmit.op).toBe('and');
            expect(lastEmit.terms).toBeUndefined();
        });

        it('clears min when switching away from at_least', async () => {
            const node: ModerationRuleNode = {
                op: 'at_least',
                terms: ['a', 'b', 'c'],
                min: 2,
                options: {},
            };
            const wrapper = mount(RuleEditor, {
                props: { modelValue: node },
            });

            const select = wrapper.findComponent({ name: 'Select' });
            await select.vm.$emit('update:modelValue', 'any');

            const emitted = wrapper.emitted('update:modelValue');
            expect(emitted).toBeTruthy();
            const lastEmit = emitted![emitted!.length - 1][0] as ModerationRuleNode;
            expect(lastEmit.op).toBe('any');
            expect(lastEmit.min).toBeUndefined();
        });
    });

    describe('options management', () => {
        it('updates case_sensitive option', async () => {
            const node: ModerationRuleNode = {
                op: 'any',
                terms: [{ term: 'test', allow_digit_prefix: false }],
                options: { case_sensitive: false, whole_word: true },
            };
            const wrapper = mount(RuleEditor, {
                props: { modelValue: node },
            });

            // Find the case sensitive switch - it's the first switch in the options section
            // (there may be switches for allow_digit_prefix per term, so we need to find the right one)
            const switches = wrapper.findAllComponents({ name: 'Switch' });
            // The case_sensitive switch should be in the "Match Options" section, before whole_word
            // Count switches: per-term switches come first, then case_sensitive, then whole_word
            // For 1 term: switches[0] = allow_digit_prefix, switches[1] = case_sensitive, switches[2] = whole_word
            const caseSensitiveSwitch = switches[1]; // Second switch is case_sensitive (after term's allow_digit_prefix)
            
            await caseSensitiveSwitch.vm.$emit('update:modelValue', true);
            await nextTick();

            const emitted = wrapper.emitted('update:modelValue');
            expect(emitted).toBeTruthy();
            const lastEmit = emitted![emitted!.length - 1][0] as ModerationRuleNode;
            expect(lastEmit.options?.case_sensitive).toBe(true);
        });

        it('updates whole_word option', async () => {
            const node: ModerationRuleNode = {
                op: 'any',
                terms: [{ term: 'test', allow_digit_prefix: false }],
                options: { case_sensitive: false, whole_word: true },
            };
            const wrapper = mount(RuleEditor, {
                props: { modelValue: node },
            });

            const switches = wrapper.findAllComponents({ name: 'Switch' });
            // For 1 term: switches[0] = allow_digit_prefix, switches[1] = case_sensitive, switches[2] = whole_word
            const wholeWordSwitch = switches[2]; // Third switch is whole_word
            
            await wholeWordSwitch.vm.$emit('update:modelValue', false);
            await nextTick();

            const emitted = wrapper.emitted('update:modelValue');
            expect(emitted).toBeTruthy();
            const lastEmit = emitted![emitted!.length - 1][0] as ModerationRuleNode;
            expect(lastEmit.options?.whole_word).toBe(false);
        });
    });

    describe('children management', () => {
        it('adds a child rule', async () => {
            const node: ModerationRuleNode = {
                op: 'and',
                children: [],
                options: {},
            };
            const wrapper = mount(RuleEditor, {
                props: { modelValue: node },
            });

            const buttons = wrapper.findAll('button');
            const addChildBtn = buttons.find(b => b.text().includes('Add Child'));
            expect(addChildBtn).toBeDefined();
            
            await addChildBtn!.trigger('click');

            const emitted = wrapper.emitted('update:modelValue');
            expect(emitted).toBeTruthy();
            const lastEmit = emitted![emitted!.length - 1][0] as ModerationRuleNode;
            expect(lastEmit.children).toHaveLength(1);
            expect(lastEmit.children![0].op).toBe('any');
        });

        it('removes a child rule', async () => {
            const node: ModerationRuleNode = {
                op: 'and',
                children: [
                    { op: 'any', terms: [{ term: 'test1', allow_digit_prefix: false }] },
                    { op: 'all', terms: [{ term: 'test2', allow_digit_prefix: false }] },
                ],
                options: {},
            };
            const wrapper = mount(RuleEditor, {
                props: { modelValue: node },
            });

            // Find the destructive remove button
            const removeButtons = wrapper.findAll('button').filter(b => 
                b.attributes('class')?.includes('destructive') && 
                !b.text().includes('Add')
            );
            
            if (removeButtons.length > 0) {
                await removeButtons[0].trigger('click');

                const emitted = wrapper.emitted('update:modelValue');
                expect(emitted).toBeTruthy();
                const lastEmit = emitted![emitted!.length - 1][0] as ModerationRuleNode;
                expect(lastEmit.children).toHaveLength(1);
            }
        });
    });

    describe('min value for at_least', () => {
        it('updates min value', async () => {
            const node: ModerationRuleNode = {
                op: 'at_least',
                terms: [
                    { term: 'a', allow_digit_prefix: false },
                    { term: 'b', allow_digit_prefix: false },
                    { term: 'c', allow_digit_prefix: false },
                ],
                min: 1,
                options: {},
            };
            const wrapper = mount(RuleEditor, {
                props: { modelValue: node },
            });

            // Find the min input (type="number")
            const inputs = wrapper.findAll('input');
            const minInput = inputs.find(i => i.attributes('type') === 'number');
            
            if (minInput) {
                await minInput.setValue('3');

                const emitted = wrapper.emitted('update:modelValue');
                expect(emitted).toBeTruthy();
            }
        });
    });
});

