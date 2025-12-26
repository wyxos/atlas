<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import PageLayout from '@/components/PageLayout.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { AlertCircle, Check, X, Loader2 } from 'lucide-vue-next';
import { useDebounceFn } from '@vueuse/core';

interface ModerationRule {
    id: number;
    name?: string | null;
    active: boolean;
    nsfw: boolean;
    action_type?: string;
    op: string;
    terms?: unknown[] | null;
    min?: number | null;
    options?: Record<string, unknown> | null;
    children?: unknown[] | null;
    created_at?: string;
    updated_at?: string;
}

interface TestResult {
    matches: boolean;
    hits: string[];
    rule: ModerationRule;
}

const route = useRoute();
const testText = ref('');
const selectedRuleId = ref<number | null>(null);
const rules = ref<ModerationRule[]>([]);
const testResult = ref<TestResult | null>(null);
const isTesting = ref(false);
const isLoadingRules = ref(false);
const testError = ref<string | null>(null);

const selectedRule = computed<ModerationRule | null>(() => {
    if (!selectedRuleId.value) return null;
    return rules.value.find((r) => r.id === selectedRuleId.value) || null;
});

// Load rules on mount
async function loadRules(): Promise<void> {
    isLoadingRules.value = true;
    try {
        const response = await window.axios.get('/api/moderation-rules');
        rules.value = Array.isArray(response.data) ? response.data : [];
    } catch (error) {
        console.error('Failed to load rules:', error);
        testError.value = 'Failed to load moderation rules';
    } finally {
        isLoadingRules.value = false;
    }
}

// Test function
async function runTest(): Promise<void> {
    if (!selectedRuleId.value || !testText.value.trim()) {
        testResult.value = null;
        return;
    }

    isTesting.value = true;
    testError.value = null;

    try {
        const response = await window.axios.post('/api/moderation-rules/test', {
            text: testText.value,
            rule_id: selectedRuleId.value,
        });

        testResult.value = {
            matches: response.data.matches ?? false,
            hits: Array.isArray(response.data.hits) ? response.data.hits : [],
            rule: response.data.rule || selectedRule.value!,
        };
    } catch (error: unknown) {
        const axiosError = error as { response?: { data?: { message?: string } }; message?: string };
        testError.value = axiosError.response?.data?.message || axiosError.message || 'Failed to test rule';
        testResult.value = null;
    } finally {
        isTesting.value = false;
    }
}

// Debounced test function
const debouncedRunTest = useDebounceFn(runTest, 300);

// Watch for text changes
watch(testText, () => {
    if (selectedRuleId.value && testText.value.trim()) {
        void debouncedRunTest();
    } else {
        testResult.value = null;
    }
});

// Watch for rule selection changes
watch(selectedRuleId, (newId) => {
    if (newId && testText.value.trim()) {
        void debouncedRunTest();
    } else {
        testResult.value = null;
    }
});

// Read query parameters on mount
onMounted(() => {
    void loadRules();

    const textParam = route.query.text as string | undefined;
    const ruleIdParam = route.query.rule_id as string | undefined;

    if (textParam) {
        testText.value = decodeURIComponent(textParam);
    }

    if (ruleIdParam) {
        const ruleId = parseInt(ruleIdParam, 10);
        if (!isNaN(ruleId)) {
            selectedRuleId.value = ruleId;
        }
    }
});

const matchStatus = computed(() => {
    if (!testResult.value) return null;
    return testResult.value.matches;
});
</script>

<template>
    <PageLayout>
        <div class="space-y-6">
            <div>
                <h4 class="text-2xl font-semibold text-regal-navy-100 mb-2">Test Moderation Rules</h4>
                <p class="text-twilight-indigo-200 text-sm">
                    Test a moderation rule against a prompt to see if it matches and which terms were found.
                </p>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Left Column: Input and Results -->
                <div class="space-y-4">
                    <div class="space-y-2">
                        <label for="rule-select" class="text-sm font-medium text-regal-navy-100">Select Rule</label>
                        <Select v-model="selectedRuleId" :disabled="isLoadingRules">
                            <SelectTrigger id="rule-select" class="w-full">
                                <SelectValue placeholder="-- Select a rule --" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="null">-- Select a rule --</SelectItem>
                                <SelectItem
                                    v-for="rule in rules"
                                    :key="rule.id"
                                    :value="rule.id">
                                    {{ rule.name || 'Untitled' }} ({{ rule.active ? 'active' : 'inactive' }})
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="space-y-2">
                        <label for="test-text" class="text-sm font-medium text-regal-navy-100">Test Text</label>
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
                    <div v-if="testResult" class="space-y-3 rounded-md border p-4">
                        <div class="flex items-center justify-between">
                            <label class="text-base font-semibold">Test Results</label>
                            <div class="flex items-center gap-2">
                                <component
                                    :is="matchStatus ? Check : X"
                                    :size="20"
                                    :class="matchStatus ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                />
                                <span
                                    :class="
                                        matchStatus
                                            ? 'font-semibold text-green-600 dark:text-green-400'
                                            : 'font-semibold text-red-600 dark:text-red-400'
                                    "
                                >
                                    {{ matchStatus ? 'MATCHES' : 'NO MATCH' }}
                                </span>
                            </div>
                        </div>

                        <div v-if="testResult.hits && testResult.hits.length > 0" class="space-y-2">
                            <label class="text-sm text-muted-foreground">Matched Terms:</label>
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
                    </div>

                    <div v-else-if="isTesting" class="flex items-center justify-center rounded-md border p-8">
                        <div class="flex items-center gap-2 text-muted-foreground">
                            <Loader2 :size="16" class="animate-spin" />
                            <span>Testing...</span>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Rule Details -->
                <div class="space-y-4">
                    <div v-if="selectedRule" class="space-y-4 rounded-md border p-4">
                        <h5 class="text-lg font-semibold">Rule Details</h5>
                        <div class="space-y-2 text-sm">
                            <div>
                                <span class="font-medium text-muted-foreground">Name:</span>
                                <span class="ml-2">{{ selectedRule.name || 'Untitled' }}</span>
                            </div>
                            <div>
                                <span class="font-medium text-muted-foreground">Status:</span>
                                <span class="ml-2">{{ selectedRule.active ? 'Active' : 'Inactive' }}</span>
                            </div>
                            <div>
                                <span class="font-medium text-muted-foreground">NSFW:</span>
                                <span class="ml-2">{{ selectedRule.nsfw ? 'Yes' : 'No' }}</span>
                            </div>
                            <div>
                                <span class="font-medium text-muted-foreground">Operator:</span>
                                <span class="ml-2">{{ selectedRule.op }}</span>
                            </div>
                            <div v-if="selectedRule.action_type">
                                <span class="font-medium text-muted-foreground">Action Type:</span>
                                <span class="ml-2">{{ selectedRule.action_type }}</span>
                            </div>
                            <div v-if="selectedRule.terms && selectedRule.terms.length > 0">
                                <span class="font-medium text-muted-foreground">Terms:</span>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    <span
                                        v-for="(term, idx) in selectedRule.terms"
                                        :key="idx"
                                        class="rounded bg-muted px-2 py-1 text-xs"
                                    >
                                        {{ typeof term === 'string' ? term : term.term }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="flex items-center justify-center rounded-md border p-8 text-muted-foreground">
                        Select a rule to view details
                    </div>
                </div>
            </div>
        </div>
    </PageLayout>
</template>

