<script setup lang="ts">
import ModerationRuleController from '@/actions/App/Http/Controllers/ModerationRuleController';
import RuleEditor from '@/components/moderation/RuleEditor.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogDescription, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

type RuleOperator = 'any' | 'all' | 'not_any' | 'at_least' | 'and' | 'or';

type TermEntry = string | { term: string; allow_digit_prefix?: boolean };

interface RuleNode {
    op: RuleOperator;
    terms?: TermEntry[] | null;
    min?: number | null;
    options?: { case_sensitive?: boolean; whole_word?: boolean } | null;
    children?: RuleNode[] | null;
}

interface Rule extends RuleNode {
    id?: number;
    name?: string | null;
    active: boolean;
    nsfw: boolean;
    created_at?: string;
    updated_at?: string;
}

const props = withDefaults(
    defineProps<{
        nsfw?: boolean;
        buttonClass?: string;
        buttonVariant?: 'default' | 'destructive' | 'outline' | 'secondary' | 'ghost' | 'link';
        disabled?: boolean;
    }>(),
    {
        nsfw: false,
        buttonClass: 'h-9 px-3 cursor-pointer',
        buttonVariant: 'outline',
        disabled: false,
    },
);

const rulesModalOpen = ref(false);
const rulesList = ref<Rule[]>([]);
const selectedRule = ref<Rule | null>(null);
const ruleForm = ref<Rule | null>(null);
const rulesLoading = ref(false);
const ruleSaving = ref(false);
const ruleDeleting = ref(false);
const showOnlyNsfw = ref<boolean>(!!props.nsfw);

const page = usePage();
const isAdmin = computed(() => {
    try {
        const user = (page as any)?.props?.auth?.user;
        const admin = !!(user?.is_admin);
        return admin;
    } catch (error) {
        console.error('Error checking admin status:', error);
        return false;
    }
});

watch(
    () => props.nsfw,
    (value) => {
        showOnlyNsfw.value = !!value;
    },
);

function defaultRule(nsfwFlag: boolean): Rule {
    return {
        name: '',
        active: true,
        nsfw: !!nsfwFlag,
        op: 'any',
        terms: [],
        min: null,
        options: { case_sensitive: false, whole_word: true },
        children: null,
    };
}

function openRulesModal(): void {
    showOnlyNsfw.value = !!props.nsfw;
    rulesModalOpen.value = true;
    void fetchRules();
}

async function fetchRules(): Promise<void> {
    rulesLoading.value = true;
    try {
        const params = showOnlyNsfw.value ? { nsfw: 1 } : {};
        const res = await axios.get(ModerationRuleController.index().url, { params });
        rulesList.value = Array.isArray(res.data?.rules) ? res.data.rules : [];
        if (selectedRule.value) {
            const match = rulesList.value.find((r) => r.id === selectedRule.value?.id) || null;
            selectedRule.value = match;
            ruleForm.value = match ? JSON.parse(JSON.stringify(match)) : null;
        }
    } catch (error) {
        console.error('Failed to fetch rules', error);
    } finally {
        rulesLoading.value = false;
    }
}

function onSelectRule(rule: Rule): void {
    selectedRule.value = rule;
    ruleForm.value = JSON.parse(JSON.stringify(rule));
}

function onAddNewRule(): void {
    const rule = defaultRule(showOnlyNsfw.value);
    selectedRule.value = { ...rule } as Rule;
    ruleForm.value = JSON.parse(JSON.stringify(rule));
}

function onUpdateRuleNode(value: Rule | RuleNode): void {
    ruleForm.value = value as Rule;
}

async function onSaveRule(): Promise<void> {
    if (!ruleForm.value) return;
    ruleSaving.value = true;
    const payload: any = { ...ruleForm.value };
    try {
        if (payload.id) {
            const url = ModerationRuleController.update({ rule: payload.id }).url;
            const res = await axios.put(url, payload);
            const saved: Rule = res.data?.rule || payload;
            await fetchRules();
            const match = rulesList.value.find((r) => r.id === saved.id) || null;
            selectedRule.value = match;
            ruleForm.value = match ? JSON.parse(JSON.stringify(match)) : null;
        } else {
            const url = ModerationRuleController.store().url;
            const res = await axios.post(url, payload);
            const created: Rule = res.data?.rule || payload;
            await fetchRules();
            const match = rulesList.value.find((r) => r.id === created.id) || null;
            selectedRule.value = match;
            ruleForm.value = match ? JSON.parse(JSON.stringify(match)) : null;
        }
    } catch (error) {
        console.error('Failed to save rule', error);
    } finally {
        ruleSaving.value = false;
    }
}

function summarizeRule(rule: Rule | RuleNode, depth = 0): string {
    try {
        const op = (rule as any)?.op as RuleOperator;
        const terms = ((rule as any)?.terms || []) as TermEntry[];
        const min = (rule as any)?.min as number | null | undefined;
        const options = ((rule as any)?.options || {}) as { case_sensitive?: boolean; whole_word?: boolean };
        const children = ((rule as any)?.children || []) as RuleNode[];

        const optionSummaries: string[] = [];
        optionSummaries.push(options.case_sensitive ? 'case-sensitive' : 'case-insensitive');
        optionSummaries.push((options.whole_word ?? true) ? 'whole-word' : 'substring');
        const optionSuffix = optionSummaries.length ? ` (${optionSummaries.join(', ')})` : '';

        const joinTerms = (list: TermEntry[]) => {
            if (!Array.isArray(list) || list.length === 0) return '';
            return list.map((t) => {
                if (typeof t === 'string') return t;
                if (typeof t === 'object' && t && 'term' in t) {
                    const term = String(t.term || '');
                    const prefix = t.allow_digit_prefix ? '#' : '';
                    return prefix ? `${prefix}${term}` : term;
                }
                return String(t || '');
            }).filter(Boolean).join(', ');
        };

        switch (op) {
            case 'any':
                return `matches any of: ${joinTerms(terms)}${optionSuffix}`;
            case 'all':
                return `matches all of: ${joinTerms(terms)}${optionSuffix}`;
            case 'not_any':
                return `matches none of: ${joinTerms(terms)}${optionSuffix}`;
            case 'at_least':
                return `matches at least ${min ?? 0} of: ${joinTerms(terms)}${optionSuffix}`;
            case 'and': {
                const parts = (children || []).map((child) => summarizeRule(child, depth + 1));
                return parts.length ? parts.map((part) => (depth > 0 ? `(${part})` : part)).join(' AND ') : 'AND()';
            }
            case 'or': {
                const parts = (children || []).map((child) => summarizeRule(child, depth + 1));
                return parts.length ? parts.map((part) => (depth > 0 ? `(${part})` : part)).join(' OR ') : 'OR()';
            }
            default:
                return op || 'unknown';
        }
    } catch (error) {
        console.error('Error summarizing rule:', error, rule);
        return 'error';
    }
}

async function onDeleteRule(): Promise<void> {
    if (!selectedRule.value?.id) return;
    if (!confirm('Delete this rule?')) return;
    ruleDeleting.value = true;
    try {
        const url = ModerationRuleController.destroy({ rule: selectedRule.value.id }).url;
        await axios.delete(url);
        selectedRule.value = null;
        ruleForm.value = null;
        await fetchRules();
    } catch (error) {
        console.error('Failed to delete rule', error);
    } finally {
        ruleDeleting.value = false;
    }
}
</script>

<template>
    <div class="contents">
        <Button
            v-if="isAdmin"
            :variant="buttonVariant"
            :disabled="disabled"
            :class="buttonClass"
            @click="openRulesModal"
            data-testid="manage-rules-button"
        >
            Manage Rules
        </Button>
        <!-- Temporary debug - remove after fixing -->
        <!-- <div class="text-xs">Admin: {{ isAdmin }}, User: {{ (page as any)?.props?.auth?.user?.is_admin }}</div> -->

        <Dialog v-model:open="rulesModalOpen">
            <DialogScrollContent class="w-[90vw] max-w-[1200px]">
                <DialogTitle>Moderation Rules</DialogTitle>
                <DialogDescription>Define and manage text block rules used by the moderator.</DialogDescription>
                <div class="mt-2 grid gap-3" style="grid-template-columns: minmax(0, 1fr) minmax(280px, 420px)">
                    <div class="grid gap-3">
                        <template v-if="ruleForm">
                            <div class="grid gap-2">
                                <Label>Name</Label>
                                <Input v-model="(ruleForm as any).name" placeholder="Untitled rule" />
                            </div>
                            <div class="flex items-center gap-6">
                                <label class="inline-flex items-center gap-2 text-sm">
                                    <input type="checkbox" v-model="(ruleForm as any).active" />
                                    <span>active</span>
                                </label>
                                <label class="inline-flex items-center gap-2 text-sm">
                                    <input type="checkbox" v-model="(ruleForm as any).nsfw" />
                                    <span>nsfw</span>
                                </label>
                            </div>
                            <div>
                                <RuleEditor :modelValue="ruleForm as any" @update:modelValue="onUpdateRuleNode" />
                            </div>
                            <div class="flex gap-2">
                                <Button :disabled="!ruleForm || ruleSaving" @click="onSaveRule">{{ (ruleForm as any)?.id ? 'Save' : 'Create' }}</Button>
                                <Button variant="secondary" @click="() => (rulesModalOpen = false)">Close</Button>
                            </div>
                        </template>
                        <template v-else>
                            <div class="text-sm text-muted-foreground">Select a rule from the list or click "Add New".</div>
                        </template>
                    </div>

                    <div class="flex h-[70vh] flex-col rounded-md border">
                        <div class="flex items-center justify-between border-b p-2">
                            <div class="flex items-center gap-3">
                                <label class="inline-flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        :checked="showOnlyNsfw"
                                        @change="(event: any) => {
                                            showOnlyNsfw = !!event?.target?.checked;
                                            void fetchRules();
                                        }"
                                    />
                                    <span>NSFW only</span>
                                </label>
                            </div>
                            <div class="flex items-center gap-2">
                                <Button size="sm" variant="outline" @click="onAddNewRule">Add New</Button>
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    :disabled="!selectedRule || !selectedRule?.id || ruleDeleting"
                                    @click="onDeleteRule"
                                >
                                    Delete
                                </Button>
                            </div>
                        </div>
                        <div class="flex-1 overflow-y-auto">
                            <ul>
                                <li
                                    v-for="rule in rulesList"
                                    :key="rule.id"
                                    @click="onSelectRule(rule)"
                                    class="cursor-pointer border-b p-2 text-sm hover:bg-muted"
                                    :class="[selectedRule?.id === rule.id ? 'bg-muted' : '']"
                                >
                                    <div class="flex items-center justify-between">
                                        <div class="truncate font-medium">{{ rule.name || 'Untitled' }}</div>
                                        <div class="flex items-center gap-2 text-[11px]">
                                            <span v-if="rule.active" class="rounded bg-emerald-500 px-1.5 py-0.5 text-background">active</span>
                                            <span v-if="rule.nsfw" class="rounded bg-amber-500 px-1.5 py-0.5 text-background">nsfw</span>
                                        </div>
                                    </div>
                                    <div class="mt-1 break-words text-[11px] text-muted-foreground">
                                        {{ summarizeRule(rule) }}
                                    </div>
                                </li>
                                <li v-if="!rulesLoading && rulesList.length === 0" class="p-2 text-sm text-muted-foreground">
                                    No rules found.
                                </li>
                                <li v-if="rulesLoading" class="p-2 text-sm text-muted-foreground">Loading...</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </DialogScrollContent>
        </Dialog>
    </div>
</template>
