import { ref } from 'vue';
import type {
    ModerationRule,
    CreateModerationRulePayload,
    UpdateModerationRulePayload,
} from '@/types/moderation';

const API_BASE = '/api/moderation-rules';

/**
 * Composable for managing moderation rules state and API interactions.
 */
export function useModerationRules() {
    const rules = ref<ModerationRule[]>([]);
    const isLoading = ref(false);
    const error = ref<string | null>(null);

    /**
     * Fetch all moderation rules from the API.
     */
    async function fetchRules(): Promise<void> {
        isLoading.value = true;
        error.value = null;

        try {
            const response = await window.axios.get<ModerationRule[]>(API_BASE);
            rules.value = response.data;
        } catch (e) {
            error.value = 'Failed to fetch moderation rules';
            console.error('Failed to fetch moderation rules:', e);
        } finally {
            isLoading.value = false;
        }
    }

    /**
     * Create a new moderation rule.
     */
    async function createRule(payload: CreateModerationRulePayload): Promise<ModerationRule | null> {
        isLoading.value = true;
        error.value = null;

        try {
            const response = await window.axios.post<ModerationRule>(API_BASE, payload);
            const newRule = response.data;
            rules.value.push(newRule);
            return newRule;
        } catch (e) {
            error.value = 'Failed to create moderation rule';
            console.error('Failed to create moderation rule:', e);
            return null;
        } finally {
            isLoading.value = false;
        }
    }

    /**
     * Update an existing moderation rule.
     */
    async function updateRule(id: number, payload: UpdateModerationRulePayload): Promise<ModerationRule | null> {
        isLoading.value = true;
        error.value = null;

        try {
            const response = await window.axios.put<ModerationRule>(`${API_BASE}/${id}`, payload);
            const updatedRule = response.data;

            // Update local state
            const index = rules.value.findIndex(r => r.id === id);
            if (index !== -1) {
                rules.value[index] = updatedRule;
            }

            return updatedRule;
        } catch (e) {
            error.value = 'Failed to update moderation rule';
            console.error('Failed to update moderation rule:', e);
            return null;
        } finally {
            isLoading.value = false;
        }
    }

    /**
     * Delete a moderation rule.
     */
    async function deleteRule(id: number): Promise<boolean> {
        isLoading.value = true;
        error.value = null;

        try {
            await window.axios.delete(`${API_BASE}/${id}`);

            // Remove from local state
            const index = rules.value.findIndex(r => r.id === id);
            if (index !== -1) {
                rules.value.splice(index, 1);
            }

            return true;
        } catch (e) {
            error.value = 'Failed to delete moderation rule';
            console.error('Failed to delete moderation rule:', e);
            return false;
        } finally {
            isLoading.value = false;
        }
    }

    /**
     * Toggle the active state of a rule.
     */
    async function toggleRuleActive(id: number): Promise<boolean> {
        const rule = rules.value.find(r => r.id === id);
        if (!rule) {
            return false;
        }

        const result = await updateRule(id, { active: !rule.active });
        return result !== null;
    }

    /**
     * Get active rules only.
     */
    function getActiveRules(): ModerationRule[] {
        return rules.value.filter(r => r.active);
    }

    /**
     * Get inactive rules only.
     */
    function getInactiveRules(): ModerationRule[] {
        return rules.value.filter(r => !r.active);
    }

    /**
     * Get rules filtered by NSFW flag.
     */
    function getRulesByNsfw(nsfw: boolean): ModerationRule[] {
        return rules.value.filter(r => r.nsfw === nsfw);
    }

    return {
        rules,
        isLoading,
        error,
        fetchRules,
        createRule,
        updateRule,
        deleteRule,
        toggleRuleActive,
        getActiveRules,
        getInactiveRules,
        getRulesByNsfw,
    };
}

