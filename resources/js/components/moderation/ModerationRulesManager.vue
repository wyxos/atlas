<script setup lang="ts">
import { ref, computed } from 'vue';
import { Shield, Plus, Loader2, AlertTriangle, Trash2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import RuleEditor from './RuleEditor.vue';
import { useModerationRules } from '@/composables/useModerationRules';
import type {
    ModerationRule,
    ModerationRuleNode,
    ModerationRuleOp,
    ModerationRuleActionType,
    ModerationRuleTerm,
    CreateModerationRulePayload,
} from '@/types/moderation';

interface Props {
    disabled?: boolean;
}

withDefaults(defineProps<Props>(), {
    disabled: false,
});

const emit = defineEmits<{
    'rules-changed': [];
}>();

const {
    rules,
    isLoading,
    error,
    fetchRules,
    createRule,
    updateRule,
    deleteRule,
} = useModerationRules();

// Dialog state
const isDialogOpen = ref(false);

// Selection and form state
const selectedRule = ref<ModerationRule | null>(null);
const ruleForm = ref<ModerationRuleForm | null>(null);
const isSaving = ref(false);
const isDeleting = ref(false);

interface ModerationRuleForm {
    id?: number;
    name: string;
    active: boolean;
    nsfw: boolean;
    action_type: ModerationRuleActionType;
    op: ModerationRuleOp;
    terms: ModerationRuleTerm[];
    min: number | null;
    options: { case_sensitive: boolean; whole_word: boolean };
    children: ModerationRuleNode[];
}

function defaultRuleForm(): ModerationRuleForm {
    return {
        name: '',
        active: true,
        nsfw: false,
        action_type: 'ui_countdown',
        op: 'any',
        terms: [],
        min: null,
        options: { case_sensitive: false, whole_word: true },
        children: [],
    };
}

function ruleToForm(rule: ModerationRule): ModerationRuleForm {
    return {
        id: rule.id,
        name: rule.name ?? '',
        active: rule.active,
        nsfw: rule.nsfw,
        action_type: rule.action_type ?? 'ui_countdown',
        op: rule.op,
        terms: rule.terms ?? [],
        min: rule.min,
        options: {
            case_sensitive: rule.options?.case_sensitive ?? false,
            whole_word: rule.options?.whole_word ?? true,
        },
        children: rule.children ?? [],
    };
}

// Open dialog and fetch rules
async function openDialog(): Promise<void> {
    isDialogOpen.value = true;
    selectedRule.value = null;
    ruleForm.value = null;
    await fetchRules();
}

// Select a rule from the list
function selectRule(rule: ModerationRule): void {
    selectedRule.value = rule;
    ruleForm.value = ruleToForm(rule);
}

// Create new rule
function startNewRule(): void {
    selectedRule.value = null;
    ruleForm.value = defaultRuleForm();
}

// Handle rule node updates from RuleEditor
function onRuleNodeUpdate(node: ModerationRuleNode): void {
    if (!ruleForm.value) return;

    ruleForm.value.op = node.op;
    ruleForm.value.terms = node.terms ?? [];
    ruleForm.value.min = node.min ?? null;
    ruleForm.value.options = {
        case_sensitive: node.options?.case_sensitive ?? false,
        whole_word: node.options?.whole_word ?? true,
    };
    ruleForm.value.children = node.children ?? [];
}

// Convert form to node for RuleEditor
const ruleNodeFromForm = computed<ModerationRuleNode | null>(() => {
    if (!ruleForm.value) return null;
    return {
        op: ruleForm.value.op,
        terms: ruleForm.value.terms.length > 0 ? ruleForm.value.terms : undefined,
        min: ruleForm.value.min ?? undefined,
        options: ruleForm.value.options,
        children: ruleForm.value.children.length > 0 ? ruleForm.value.children : undefined,
    };
});

// Save rule
async function saveRule(): Promise<void> {
    if (!ruleForm.value) return;

    isSaving.value = true;

    try {
        const payload: CreateModerationRulePayload = {
            name: ruleForm.value.name || null,
            active: ruleForm.value.active,
            nsfw: ruleForm.value.nsfw,
            action_type: ruleForm.value.action_type,
            op: ruleForm.value.op,
            terms: ['any', 'all', 'not_any', 'at_least'].includes(ruleForm.value.op)
                ? ruleForm.value.terms
                : null,
            min: ruleForm.value.op === 'at_least' ? ruleForm.value.min : null,
            options: ruleForm.value.options,
            children: ['and', 'or'].includes(ruleForm.value.op)
                ? ruleForm.value.children
                : null,
        };

        if (ruleForm.value.id) {
            // Update existing
            const updated = await updateRule(ruleForm.value.id, payload);
            if (updated) {
                selectedRule.value = updated;
                ruleForm.value = ruleToForm(updated);
            }
        } else {
            // Create new
            const created = await createRule(payload);
            if (created) {
                selectedRule.value = created;
                ruleForm.value = ruleToForm(created);
            }
        }

        emit('rules-changed');
    } finally {
        isSaving.value = false;
    }
}

// Delete rule
async function confirmDeleteRule(): Promise<void> {
    if (!selectedRule.value?.id) return;
    if (!confirm('Are you sure you want to delete this rule?')) return;

    isDeleting.value = true;

    try {
        const success = await deleteRule(selectedRule.value.id);
        if (success) {
            selectedRule.value = null;
            ruleForm.value = null;
            emit('rules-changed');
        }
    } finally {
        isDeleting.value = false;
    }
}

// Summarize rule for display
function summarizeRule(rule: ModerationRule | ModerationRuleNode): string {
    const op = rule.op;
    const terms = rule.terms ?? [];
    const min = rule.min;
    const children = rule.children ?? [];

    // Extract term strings from term objects or strings
    const extractTermString = (term: string | { term: string; allow_digit_prefix?: boolean }): string => {
        return typeof term === 'string' ? term : term.term;
    };

    const joinTerms = (termList: (string | { term: string; allow_digit_prefix?: boolean })[]) => {
        const termStrings = termList.map(extractTermString);
        return termStrings.slice(0, 3).join(', ') + (termStrings.length > 3 ? '...' : '');
    };

    switch (op) {
        case 'any':
            return `any of: ${joinTerms(terms)}`;
        case 'all':
            return `all of: ${joinTerms(terms)}`;
        case 'not_any':
            return `none of: ${joinTerms(terms)}`;
        case 'at_least':
            return `≥${min ?? 0} of: ${joinTerms(terms)}`;
        case 'and':
            return `AND (${children.length} rules)`;
        case 'or':
            return `OR (${children.length} rules)`;
        default:
            return op;
    }
}
</script>

<template>
    <div>
        <!-- Trigger Button -->
        <Button size="sm" variant="ghost" class="h-10 w-10" data-test="moderation-rules-button" title="Moderation Rules"
            :disabled="disabled" @click="openDialog">
            <Shield :size="14" />
        </Button>

        <!-- Main Dialog -->
        <Dialog v-model:open="isDialogOpen">
            <DialogContent class="w-[90vw] max-w-[1100px] max-h-[85vh] bg-prussian-blue-600 p-0 overflow-hidden">
                <DialogHeader class="px-6 pt-6 pb-4 border-b border-twilight-indigo-500/30">
                    <div class="flex items-center gap-3">
                        <Shield :size="20" class="text-smart-blue-400" />
                        <div>
                            <DialogTitle class="text-regal-navy-100">Moderation Rules</DialogTitle>
                            <DialogDescription class="text-twilight-indigo-300">
                                Define rules to filter content based on text patterns.
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <div class="flex h-[65vh]">
                    <!-- Left Panel: Rule Editor -->
                    <div class="flex-1 p-6 overflow-y-auto border-r border-twilight-indigo-500/30">
                        <template v-if="ruleForm">
                            <div class="space-y-5">
                                <!-- Name -->
                                <div class="space-y-2">
                                    <label class="text-sm font-medium text-regal-navy-100">Rule Name</label>
                                    <Input v-model="ruleForm.name" placeholder="Untitled rule"
                                        class="bg-prussian-blue-500 border-twilight-indigo-500 text-regal-navy-100 placeholder:text-twilight-indigo-400" />
                                </div>

                                <!-- Flags -->
                                <div class="flex items-center gap-6">
                                    <label class="inline-flex items-center gap-2 cursor-pointer">
                                        <Switch v-model="ruleForm.active" />
                                        <span class="text-sm text-twilight-indigo-200">Active</span>
                                    </label>
                                    <label class="inline-flex items-center gap-2 cursor-pointer">
                                        <Switch v-model="ruleForm.nsfw" />
                                        <span class="text-sm text-twilight-indigo-200">NSFW</span>
                                    </label>
                                </div>

                                <!-- Action Type -->
                                <div class="space-y-2">
                                    <label class="text-sm font-medium text-regal-navy-100">Action Type</label>
                                    <Select v-model="ruleForm.action_type">
                                        <SelectTrigger
                                            class="w-full bg-prussian-blue-500 border-twilight-indigo-500 text-regal-navy-100">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="ui_countdown">
                                                UI Countdown (5s delay)
                                            </SelectItem>
                                            <SelectItem value="auto_dislike">
                                                Immediate Auto-Dislike
                                            </SelectItem>
                                            <SelectItem value="blacklist">
                                                Immediate Blacklist
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <p class="text-xs text-twilight-indigo-400">
                                        <span v-if="ruleForm.action_type === 'ui_countdown'">
                                            Files matching this rule will show a 5-second countdown before being
                                            auto-disliked.
                                        </span>
                                        <span v-else-if="ruleForm.action_type === 'auto_dislike'">
                                            Files matching this rule will be immediately auto-disliked and removed from
                                            results.
                                        </span>
                                        <span v-else>
                                            Files matching this rule will be immediately blacklisted and removed from
                                            results.
                                        </span>
                                    </p>
                                </div>

                                <!-- Rule Editor -->
                                <div class="pt-4 border-t border-twilight-indigo-500/30">
                                    <RuleEditor v-if="ruleNodeFromForm" :model-value="ruleNodeFromForm"
                                        @update:model-value="onRuleNodeUpdate" />
                                </div>

                                <!-- Actions -->
                                <div class="flex items-center gap-3 pt-4 border-t border-twilight-indigo-500/30">
                                    <Button @click="saveRule" :disabled="isSaving" class="h-10">
                                        <Loader2 v-if="isSaving" :size="14" class="mr-2 animate-spin" />
                                        {{ ruleForm.id ? 'Save Changes' : 'Create Rule' }}
                                    </Button>
                                    <Button variant="outline" @click="isDialogOpen = false" class="h-10">
                                        Close
                                    </Button>
                                </div>
                            </div>
                        </template>
                        <template v-else>
                            <div class="flex flex-col items-center justify-center h-full text-center">
                                <Shield :size="48" class="text-twilight-indigo-400 mb-3 opacity-50" />
                                <p class="text-sm text-twilight-indigo-300">
                                    Select a rule from the list<br />or click "Add New" to create one.
                                </p>
                            </div>
                        </template>
                    </div>

                    <!-- Right Panel: Rules List -->
                    <div class="w-[320px] flex flex-col bg-prussian-blue-700/30">
                        <!-- List Header -->
                        <div class="flex items-center justify-between p-3 border-b border-twilight-indigo-500/30">
                            <span class="text-xs font-medium text-twilight-indigo-300 uppercase tracking-wider">
                                Rules ({{ rules.length }})
                            </span>
                            <div class="flex items-center gap-2">
                                <Button size="sm" variant="outline" @click="startNewRule" class="h-8">
                                    <Plus :size="14" class="mr-1" />
                                    Add New
                                </Button>
                                <Button size="sm" variant="destructive" :disabled="!selectedRule?.id || isDeleting"
                                    @click="confirmDeleteRule" class="h-8">
                                    <Trash2 :size="14" />
                                </Button>
                            </div>
                        </div>

                        <!-- List Content -->
                        <div class="flex-1 overflow-y-auto">
                            <!-- Loading -->
                            <div v-if="isLoading && rules.length === 0" class="flex items-center justify-center py-8">
                                <Loader2 :size="24" class="animate-spin text-twilight-indigo-300" />
                            </div>

                            <!-- Error -->
                            <div v-else-if="error"
                                class="flex flex-col items-center justify-center py-8 px-4 text-center">
                                <AlertTriangle :size="24" class="text-danger-400 mb-2" />
                                <p class="text-xs text-danger-300">{{ error }}</p>
                                <Button variant="outline" size="sm" class="mt-2 h-8" @click="fetchRules">
                                    Retry
                                </Button>
                            </div>

                            <!-- Empty -->
                            <div v-else-if="rules.length === 0" class="py-8 px-4 text-center">
                                <p class="text-sm text-twilight-indigo-400">No rules yet.</p>
                            </div>

                            <!-- Rules List -->
                            <ul v-else>
                                <li v-for="rule in rules" :key="rule.id" @click="selectRule(rule)"
                                    class="cursor-pointer border-b border-twilight-indigo-500/20 p-3 hover:bg-prussian-blue-500/50 transition-colors"
                                    :class="selectedRule?.id === rule.id ? 'bg-prussian-blue-500/70' : ''">
                                    <div class="flex items-center justify-between mb-1">
                                        <span class="text-sm font-medium text-regal-navy-100 truncate">
                                            {{ rule.name || 'Untitled' }}
                                        </span>
                                        <div class="flex items-center gap-1.5 shrink-0 ml-2">
                                            <span v-if="rule.active"
                                                class="px-1.5 py-0.5 text-[10px] font-medium rounded bg-emerald-500/20 text-emerald-400">
                                                active
                                            </span>
                                            <span v-if="rule.nsfw"
                                                class="px-1.5 py-0.5 text-[10px] font-medium rounded bg-amber-500/20 text-amber-400">
                                                nsfw
                                            </span>
                                        </div>
                                    </div>
                                    <p class="text-[11px] text-twilight-indigo-400 truncate">
                                        {{ summarizeRule(rule) }}
                                    </p>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
