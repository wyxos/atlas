<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Plus, X, Trash2 } from 'lucide-vue-next';
import type { ModerationRuleNode, ModerationRuleOp } from '@/types/moderation';

// Ensure recursive self-reference works in <script setup>
defineOptions({ name: 'RuleEditor' });

const props = defineProps<{
    modelValue: ModerationRuleNode;
    depth?: number;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: ModerationRuleNode];
}>();

const node = computed<ModerationRuleNode>({
    get: () => props.modelValue,
    set: (v) => emit('update:modelValue', v),
});

const currentDepth = computed(() => props.depth ?? 0);

const operatorOptions: { value: ModerationRuleOp; label: string }[] = [
    { value: 'any', label: 'Match ANY term' },
    { value: 'all', label: 'Match ALL terms' },
    { value: 'not_any', label: 'Match NONE (exclude)' },
    { value: 'at_least', label: 'Match AT LEAST N' },
    { value: 'and', label: 'AND (all children must match)' },
    { value: 'or', label: 'OR (any child can match)' },
];

const usesTerms = computed(() => ['any', 'all', 'not_any', 'at_least'].includes(node.value.op));
const usesChildren = computed(() => ['and', 'or'].includes(node.value.op));
const usesMin = computed(() => node.value.op === 'at_least');

function update<K extends keyof ModerationRuleNode>(key: K, value: ModerationRuleNode[K]): void {
    emit('update:modelValue', { ...node.value, [key]: value });
}

function updateOp(newOp: ModerationRuleOp): void {
    const updates: Partial<ModerationRuleNode> = { op: newOp };
    
    // Reset fields based on new operation type
    if (['any', 'all', 'not_any', 'at_least'].includes(newOp)) {
        updates.children = undefined;
        if (!node.value.terms) {
            updates.terms = [];
        }
    } else {
        updates.terms = undefined;
        updates.min = undefined;
        if (!node.value.children) {
            updates.children = [];
        }
    }
    
    if (newOp !== 'at_least') {
        updates.min = undefined;
    }
    
    emit('update:modelValue', { ...node.value, ...updates });
}

// Terms management
// Terms can be strings or objects with {term: string, allow_digit_prefix: boolean}
function normalizeTerm(term: any): { term: string; allow_digit_prefix: boolean } {
    if (typeof term === 'string') {
        return { term, allow_digit_prefix: false };
    }
    if (typeof term === 'object' && term !== null && 'term' in term) {
        return {
            term: String(term.term ?? ''),
            allow_digit_prefix: Boolean(term.allow_digit_prefix ?? false),
        };
    }
    return { term: '', allow_digit_prefix: false };
}

function getTermValue(term: any): string {
    return normalizeTerm(term).term;
}

function getTermAllowDigitPrefix(term: any): boolean {
    return normalizeTerm(term).allow_digit_prefix;
}

function addTerm(): void {
    const terms = Array.isArray(node.value.terms) ? [...node.value.terms] : [];
    terms.push({ term: '', allow_digit_prefix: false });
    update('terms', terms);
}

function updateTerm(idx: number, value: string): void {
    const terms = Array.isArray(node.value.terms) ? [...node.value.terms] : [];
    const current = normalizeTerm(terms[idx]);
    terms[idx] = { term: value, allow_digit_prefix: current.allow_digit_prefix };
    update('terms', terms);
}

function updateTermAllowDigitPrefix(idx: number, checked: boolean): void {
    const terms = Array.isArray(node.value.terms) ? [...node.value.terms] : [];
    const current = normalizeTerm(terms[idx]);
    terms[idx] = { term: current.term, allow_digit_prefix: checked };
    update('terms', terms);
}

function removeTerm(idx: number): void {
    const terms = Array.isArray(node.value.terms) ? [...node.value.terms] : [];
    terms.splice(idx, 1);
    update('terms', terms);
}

// Options management
function updateOption(name: 'case_sensitive' | 'whole_word', checked: boolean): void {
    const opts = { ...(node.value.options || { case_sensitive: false, whole_word: true }) };
    opts[name] = checked;
    update('options', opts);
}

// Children management
function addChild(): void {
    const children = Array.isArray(node.value.children) ? [...node.value.children] : [];
    children.push({
        op: 'any',
        terms: [],
        options: { case_sensitive: false, whole_word: true },
    });
    update('children', children);
}

function updateChild(idx: number, child: ModerationRuleNode): void {
    const children = Array.isArray(node.value.children) ? [...node.value.children] : [];
    children[idx] = child;
    update('children', children);
}

function removeChild(idx: number): void {
    const children = Array.isArray(node.value.children) ? [...node.value.children] : [];
    children.splice(idx, 1);
    update('children', children);
}
</script>

<template>
    <div class="space-y-4">
        <!-- Operator Selection -->
        <div class="space-y-2">
            <label class="text-sm font-medium text-regal-navy-100">Operator</label>
            <Select :model-value="node.op" @update:model-value="(v) => updateOp(v as ModerationRuleOp)">
                <SelectTrigger class="w-full bg-prussian-blue-500 border-twilight-indigo-500 text-regal-navy-100">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opt in operatorOptions"
                        :key="opt.value"
                        :value="opt.value"
                    >
                        {{ opt.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <!-- Min matches (for at_least) -->
        <div v-if="usesMin" class="space-y-2">
            <label class="text-sm font-medium text-regal-navy-100">Minimum matches</label>
            <Input
                type="number"
                min="1"
                :model-value="node.min ?? 1"
                @update:model-value="(v) => update('min', v ? Number(v) : null)"
                class="w-32 bg-prussian-blue-500 border-twilight-indigo-500 text-regal-navy-100"
            />
        </div>

        <!-- Terms (for term-based operations) -->
        <div v-if="usesTerms" class="space-y-2">
            <div class="flex items-center justify-between">
                <label class="text-sm font-medium text-regal-navy-100">Terms</label>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addTerm"
                    class="h-8"
                >
                    <Plus :size="14" class="mr-1" />
                    Add Term
                </Button>
            </div>
            <div class="space-y-2">
                <div
                    v-for="(term, idx) in (node.terms || [])"
                    :key="idx"
                    class="flex items-center gap-2"
                >
                    <Input
                        :model-value="getTermValue(term)"
                        @update:model-value="(v) => updateTerm(idx, String(v ?? ''))"
                        placeholder="Enter term..."
                        class="flex-1 bg-prussian-blue-500 border-twilight-indigo-500 text-regal-navy-100 placeholder:text-twilight-indigo-400"
                    />
                    <label class="inline-flex items-center gap-1.5 cursor-pointer shrink-0">
                        <Switch
                            :model-value="getTermAllowDigitPrefix(term)"
                            @update:model-value="(v) => updateTermAllowDigitPrefix(idx, v)"
                        />
                        <span class="text-xs text-twilight-indigo-300 whitespace-nowrap" title="Allow digit prefix (e.g., '2cars', '3cars' matches 'car')">
                            # prefix
                        </span>
                    </label>
                    <Button
                        type="button"
                        variant="destructive"
                        size="sm"
                        @click="removeTerm(idx)"
                        class="h-9 w-9 p-0"
                    >
                        <X :size="16" />
                    </Button>
                </div>
                <p v-if="!node.terms?.length" class="text-sm text-twilight-indigo-400">
                    No terms added. Click "Add Term" to add one.
                </p>
            </div>
        </div>

        <!-- Options -->
        <div v-if="usesTerms" class="space-y-3 pt-3 border-t border-twilight-indigo-500/30">
            <label class="text-sm font-medium text-regal-navy-100">Match Options</label>
            <div class="flex flex-wrap gap-6">
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <Switch
                        :model-value="node.options?.case_sensitive ?? false"
                        @update:model-value="(v) => updateOption('case_sensitive', v)"
                    />
                    <span class="text-sm text-twilight-indigo-200">Case sensitive</span>
                </label>
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <Switch
                        :model-value="node.options?.whole_word ?? true"
                        @update:model-value="(v) => updateOption('whole_word', v)"
                    />
                    <span class="text-sm text-twilight-indigo-200">Whole word</span>
                </label>
            </div>
        </div>

        <!-- Children (for and/or operations) -->
        <div v-if="usesChildren" class="space-y-3">
            <div class="flex items-center justify-between">
                <label class="text-sm font-medium text-regal-navy-100">
                    Child Rules
                    <span class="text-twilight-indigo-400 font-normal">
                        ({{ node.op === 'and' ? 'all must match' : 'any can match' }})
                    </span>
                </label>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addChild"
                    class="h-8"
                >
                    <Plus :size="14" class="mr-1" />
                    Add Child
                </Button>
            </div>
            <div class="space-y-3">
                <div
                    v-for="(child, idx) in (node.children || [])"
                    :key="idx"
                    class="rounded-lg border border-twilight-indigo-500/50 bg-prussian-blue-700/30 p-4"
                >
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium text-twilight-indigo-300 uppercase tracking-wider">
                            Child {{ idx + 1 }}
                        </span>
                        <Button
                            type="button"
                            variant="destructive"
                            size="sm"
                            @click="removeChild(idx)"
                            class="h-7 w-7 p-0"
                        >
                            <Trash2 :size="14" />
                        </Button>
                    </div>
                    <!-- Recursive RuleEditor -->
                    <RuleEditor
                        :model-value="child"
                        :depth="currentDepth + 1"
                        @update:model-value="(v) => updateChild(idx, v)"
                    />
                </div>
                <p v-if="!node.children?.length" class="text-sm text-twilight-indigo-400">
                    No child rules. Click "Add Child" to create nested conditions.
                </p>
            </div>
        </div>
    </div>
</template>

