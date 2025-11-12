<script setup lang="ts">
import ModerationRuleController from '@/actions/App/Http/Controllers/ModerationRuleController';
import RuleEditor, { type RuleNode, type TermEntry } from '@/components/moderation/RuleEditor.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import ContentLayout from '@/layouts/ContentLayout.vue';
import ScrollableLayout from '@/layouts/ScrollableLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { Check, X, AlertCircle } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { highlightPromptHtml } from '@/utils/moderationHighlight';
import type { BreadcrumbItem } from '@/types';
import { useDebounceFn } from '@vueuse/core';

type RuleOperator = 'any' | 'all' | 'not_any' | 'at_least' | 'and' | 'or';

interface Rule extends RuleNode {
    id?: number;
    name?: string | null;
    active: boolean;
    nsfw: boolean;
    created_at?: string;
    updated_at?: string;
}

interface TestResult {
    matches: boolean;
    hits: string[];
    rule: Rule;
}

const props = defineProps<{
    rules: Rule[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Moderation', href: '#' },
    { title: 'Test Rules', href: '/moderation/test' },
];

const page = usePage();
const testText = ref('');
const selectedRuleId = ref<number | null>(null);
const testResult = ref<TestResult | null>(null);
const isTesting = ref(false);
const testError = ref<string | null>(null);

const selectedRule = computed<Rule | null>(() => {
    if (!selectedRuleId.value) return null;
    return props.rules.find((r) => r.id === selectedRuleId.value) || null;
});

const ruleForm = ref<Rule | null>(null);

// Watch for rule selection changes
watch(selectedRuleId, (newId) => {
    if (newId && selectedRule.value) {
        ruleForm.value = JSON.parse(JSON.stringify(selectedRule.value));
        if (testText.value.trim()) {
            void runTest();
        }
    } else {
        ruleForm.value = null;
        testResult.value = null;
    }
});

// Debounced test function to avoid excessive API calls
const debouncedRunTest = useDebounceFn(runTest, 300);

// Watch for text changes
watch(testText, () => {
    if (selectedRuleId.value && testText.value.trim()) {
        void debouncedRunTest();
    } else {
        testResult.value = null;
    }
});

// Watch for rule form changes (when editing)
watch(
    () => ruleForm.value,
    () => {
        if (ruleForm.value && testText.value.trim()) {
            void debouncedRunTest();
        }
    },
    { deep: true },
);

async function runTest() {
    if (!selectedRuleId.value || !testText.value.trim()) {
        testResult.value = null;
        return;
    }

    isTesting.value = true;
    testError.value = null;

    try {
        // Use the rule from form if available (for testing edits), otherwise use selected rule
        const ruleToTest = ruleForm.value || selectedRule.value;
        if (!ruleToTest) {
            testResult.value = null;
            return;
        }

        // If rule has been edited (form differs from selected), send the rule object
        // Otherwise, just send the rule_id
        const payload: any = {
            text: testText.value,
        };

        if (ruleForm.value && ruleForm.value.id && JSON.stringify(ruleForm.value) !== JSON.stringify(selectedRule.value)) {
            // Rule has been edited - send the full rule object
            payload.rule = ruleForm.value;
        } else {
            // Use existing rule - just send rule_id
            payload.rule_id = ruleToTest.id;
        }

        const response = await axios.post(ModerationRuleController.testRule().url, payload);

        testResult.value = {
            matches: response.data.matches ?? false,
            hits: Array.isArray(response.data.hits) ? response.data.hits : [],
            rule: response.data.rule || ruleToTest,
        };
    } catch (error: any) {
        testError.value = error?.response?.data?.message || error?.message || 'Failed to test rule';
        testResult.value = null;
    } finally {
        isTesting.value = false;
    }
}

function onUpdateRuleNode(value: Rule | RuleNode): void {
    ruleForm.value = value as Rule;
}

async function onSaveRule(): Promise<void> {
    if (!ruleForm.value || !ruleForm.value.id) return;

    try {
        const url = ModerationRuleController.update({ rule: ruleForm.value.id }).url;
        const response = await axios.put(url, ruleForm.value);
        const saved: Rule = response.data?.rule || ruleForm.value;

        // Update the rule in the form
        ruleForm.value = JSON.parse(JSON.stringify(saved));
        
        // Refresh the rules list from server
        const rulesResponse = await axios.get(ModerationRuleController.index().url);
        const updatedRules = Array.isArray(rulesResponse.data?.rules) ? rulesResponse.data.rules : [];
        
        // Update the selected rule if it still exists
        if (selectedRuleId.value) {
            const updatedRule = updatedRules.find((r: Rule) => r.id === selectedRuleId.value);
            if (updatedRule) {
                ruleForm.value = JSON.parse(JSON.stringify(updatedRule));
            }
        }
        
        // Re-run test with updated rule
        if (testText.value.trim()) {
            await runTest();
        }
    } catch (error: any) {
        console.error('Failed to save rule:', error);
        alert('Failed to save rule: ' + (error?.response?.data?.message || error?.message || 'Unknown error'));
    }
}

const highlightedText = computed(() => {
    if (!testText.value || !testResult.value) return '';
    if (!testResult.value.hits || testResult.value.hits.length === 0) return testText.value;

    const rule = testResult.value.rule;
    const options = rule?.options || {};
    return highlightPromptHtml(testText.value, testResult.value.hits, {
        whole_word: options.whole_word ?? true,
        case_sensitive: options.case_sensitive ?? false,
    });
});

const matchStatus = computed(() => {
    if (!testResult.value) return null;
    return testResult.value.matches;
});
</script>

<template>
    <Head title="Test Moderation Rules" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <ContentLayout>
            <ScrollableLayout class="flex flex-col">
                <div class="grid gap-6 lg:grid-cols-2">
                <!-- Left Column: Input and Rule Selection -->
                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="rule-select">Select Rule</Label>
                        <select
                            id="rule-select"
                            v-model="selectedRuleId"
                            class="h-9 rounded-md border px-2 text-sm dark:bg-neutral-900"
                        >
                            <option :value="null">-- Select a rule --</option>
                            <option v-for="rule in rules" :key="rule.id" :value="rule.id">
                                {{ rule.name || 'Untitled' }} ({{ rule.active ? 'active' : 'inactive' }})
                            </option>
                        </select>
                    </div>

                    <div class="grid gap-2">
                        <Label for="test-text">Test Text</Label>
                        <Textarea
                            id="test-text"
                            v-model="testText"
                            placeholder="Paste text here to test against the selected rule..."
                            class="min-h-[200px] font-mono text-sm"
                        />
                    </div>

                    <div v-if="testError" class="rounded-md border border-destructive bg-destructive/10 p-3 text-sm text-destructive">
                        <div class="flex items-center gap-2">
                            <AlertCircle :size="16" />
                            <span>{{ testError }}</span>
                        </div>
                    </div>

                    <!-- Test Results -->
                    <div v-if="testResult" class="grid gap-3 rounded-md border p-4">
                        <div class="flex items-center justify-between">
                            <Label class="text-base font-semibold">Test Results</Label>
                            <div class="flex items-center gap-2">
                                <component
                                    :is="matchStatus ? Check : X"
                                    :size="20"
                                    :class="matchStatus ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                />
                                <span
                                    :class="matchStatus ? 'text-green-600 dark:text-green-400 font-semibold' : 'text-red-600 dark:text-red-400 font-semibold'"
                                >
                                    {{ matchStatus ? 'MATCHES' : 'NO MATCH' }}
                                </span>
                            </div>
                        </div>

                        <div v-if="testResult.hits && testResult.hits.length > 0" class="grid gap-2">
                            <Label class="text-sm text-muted-foreground">Matched Terms:</Label>
                            <div class="flex flex-wrap gap-2">
                                <span
                                    v-for="hit in testResult.hits"
                                    :key="hit"
                                    class="rounded bg-amber-300/60 px-2 py-1 text-sm dark:bg-amber-400/30"
                                >
                                    {{ hit }}
                                </span>
                            </div>
                        </div>
                        <div v-else class="text-sm text-muted-foreground">No terms matched.</div>

                        <div class="grid gap-2">
                            <Label class="text-sm text-muted-foreground">Highlighted Text:</Label>
                            <div
                                class="min-h-[100px] rounded-md border bg-muted/30 p-3 font-mono text-sm leading-relaxed"
                                v-html="highlightedText"
                            />
                        </div>
                    </div>

                    <div v-else-if="isTesting" class="flex items-center justify-center rounded-md border p-8">
                        <div class="text-muted-foreground">Testing...</div>
                    </div>
                </div>

                <!-- Right Column: Rule Editor -->
                <div class="grid gap-4">
                    <div v-if="selectedRule" class="grid gap-4 rounded-md border p-4">
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-semibold">Edit Rule</h2>
                            <Button v-if="ruleForm?.id" @click="onSaveRule" size="sm">Save Changes</Button>
                        </div>

                        <div class="grid gap-2">
                            <Label>Rule Name</Label>
                            <Input 
                                :modelValue="ruleForm?.name || ''" 
                                @update:modelValue="(val: string) => { if (ruleForm) ruleForm.name = val; }" 
                                placeholder="Untitled rule" 
                            />
                        </div>

                        <div class="flex items-center gap-6">
                            <label class="inline-flex items-center gap-2 text-sm">
                                <input 
                                    type="checkbox" 
                                    :checked="ruleForm?.active ?? false"
                                    @change="(e: any) => { if (ruleForm) ruleForm.active = !!e?.target?.checked; }"
                                />
                                <span>active</span>
                            </label>
                            <label class="inline-flex items-center gap-2 text-sm">
                                <input 
                                    type="checkbox" 
                                    :checked="ruleForm?.nsfw ?? false"
                                    @change="(e: any) => { if (ruleForm) ruleForm.nsfw = !!e?.target?.checked; }"
                                />
                                <span>nsfw</span>
                            </label>
                        </div>

                        <RuleEditor v-if="ruleForm" :modelValue="ruleForm as any" @update:modelValue="onUpdateRuleNode" />
                    </div>

                    <div v-else class="flex items-center justify-center rounded-md border p-8 text-muted-foreground">
                        Select a rule to edit
                    </div>
                </div>
                </div>
            </ScrollableLayout>
        </ContentLayout>
    </AppLayout>
</template>

