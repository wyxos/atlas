import { describe, it, expect, vi, beforeEach } from 'vitest';
import { useModerationRules } from './useModerationRules';
import type { ModerationRule } from '@/types/moderation';

// Mock axios
const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
};

vi.mock('axios', () => ({
    default: mockAxios,
}));

// Mock window.axios
Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

const createMockRule = (overrides: Partial<ModerationRule> = {}): ModerationRule => ({
    id: 1,
    name: 'Test Rule',
    active: true,
    nsfw: false,
    op: 'any',
    terms: ['test', 'example'],
    min: null,
    options: { case_sensitive: false, whole_word: true },
    children: null,
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-01T00:00:00Z',
    ...overrides,
});

beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => {});
});

describe('useModerationRules', () => {
    describe('initialization', () => {
        it('initializes with empty state', () => {
            const { rules, isLoading, error } = useModerationRules();

            expect(rules.value).toEqual([]);
            expect(isLoading.value).toBe(false);
            expect(error.value).toBeNull();
        });
    });

    describe('fetchRules', () => {
        it('fetches rules from API successfully', async () => {
            const mockRules = [
                createMockRule({ id: 1, name: 'Rule 1' }),
                createMockRule({ id: 2, name: 'Rule 2' }),
            ];

            mockAxios.get.mockResolvedValueOnce({ data: mockRules });

            const { rules, fetchRules, isLoading, error } = useModerationRules();

            await fetchRules();

            expect(mockAxios.get).toHaveBeenCalledWith('/api/moderation-rules');
            expect(rules.value).toHaveLength(2);
            expect(rules.value[0].name).toBe('Rule 1');
            expect(rules.value[1].name).toBe('Rule 2');
            expect(isLoading.value).toBe(false);
            expect(error.value).toBeNull();
        });

        it('handles fetch error gracefully', async () => {
            mockAxios.get.mockRejectedValueOnce(new Error('Network error'));

            const { rules, fetchRules, isLoading, error } = useModerationRules();

            await fetchRules();

            expect(rules.value).toEqual([]);
            expect(isLoading.value).toBe(false);
            expect(error.value).toBe('Failed to fetch moderation rules');
        });

        it('sets loading state during fetch', async () => {
            let resolvePromise: (value: unknown) => void;
            const promise = new Promise((resolve) => {
                resolvePromise = resolve;
            });
            mockAxios.get.mockReturnValueOnce(promise);

            const { fetchRules, isLoading } = useModerationRules();

            const fetchPromise = fetchRules();
            expect(isLoading.value).toBe(true);

            resolvePromise!({ data: [] });
            await fetchPromise;

            expect(isLoading.value).toBe(false);
        });
    });

    describe('createRule', () => {
        it('creates a rule successfully', async () => {
            const newRule = createMockRule({ id: 1, name: 'New Rule' });
            mockAxios.post.mockResolvedValueOnce({ data: newRule });

            const { rules, createRule, error } = useModerationRules();

            const payload = {
                name: 'New Rule',
                op: 'any' as const,
                terms: ['test'],
                active: true,
                nsfw: false,
                options: { case_sensitive: false, whole_word: true },
            };

            const result = await createRule(payload);

            expect(mockAxios.post).toHaveBeenCalledWith('/api/moderation-rules', payload);
            expect(result).toEqual(newRule);
            expect(rules.value).toHaveLength(1);
            expect(rules.value[0].name).toBe('New Rule');
            expect(error.value).toBeNull();
        });

        it('handles create error gracefully', async () => {
            mockAxios.post.mockRejectedValueOnce(new Error('Validation error'));

            const { rules, createRule, error } = useModerationRules();

            const payload = {
                name: 'Test',
                op: 'any' as const,
                terms: ['test'],
            };

            const result = await createRule(payload);

            expect(result).toBeNull();
            expect(rules.value).toEqual([]);
            expect(error.value).toBe('Failed to create moderation rule');
        });

        it('creates rule with at_least operation and min value', async () => {
            const newRule = createMockRule({
                id: 1,
                name: 'At Least Rule',
                op: 'at_least',
                min: 2,
                terms: ['term1', 'term2', 'term3'],
            });
            mockAxios.post.mockResolvedValueOnce({ data: newRule });

            const { createRule } = useModerationRules();

            const payload = {
                name: 'At Least Rule',
                op: 'at_least' as const,
                min: 2,
                terms: ['term1', 'term2', 'term3'],
            };

            const result = await createRule(payload);

            expect(result?.op).toBe('at_least');
            expect(result?.min).toBe(2);
        });

        it('creates NSFW-only rule', async () => {
            const newRule = createMockRule({
                id: 1,
                name: 'NSFW Rule',
                nsfw: true,
            });
            mockAxios.post.mockResolvedValueOnce({ data: newRule });

            const { createRule } = useModerationRules();

            const payload = {
                name: 'NSFW Rule',
                op: 'any' as const,
                terms: ['adult'],
                nsfw: true,
            };

            const result = await createRule(payload);

            expect(result?.nsfw).toBe(true);
        });
    });

    describe('updateRule', () => {
        it('updates a rule successfully', async () => {
            const existingRule = createMockRule({ id: 1, name: 'Original' });
            const updatedRule = createMockRule({ id: 1, name: 'Updated' });

            mockAxios.put.mockResolvedValueOnce({ data: updatedRule });

            const { rules, updateRule, error } = useModerationRules();
            rules.value = [existingRule];

            const result = await updateRule(1, { name: 'Updated' });

            expect(mockAxios.put).toHaveBeenCalledWith('/api/moderation-rules/1', { name: 'Updated' });
            expect(result).toEqual(updatedRule);
            expect(rules.value[0].name).toBe('Updated');
            expect(error.value).toBeNull();
        });

        it('handles update error gracefully', async () => {
            const existingRule = createMockRule({ id: 1, name: 'Original' });
            mockAxios.put.mockRejectedValueOnce(new Error('Not found'));

            const { rules, updateRule, error } = useModerationRules();
            rules.value = [existingRule];

            const result = await updateRule(1, { name: 'Updated' });

            expect(result).toBeNull();
            expect(rules.value[0].name).toBe('Original'); // Should remain unchanged
            expect(error.value).toBe('Failed to update moderation rule');
        });

        it('updates rule terms', async () => {
            const existingRule = createMockRule({ id: 1, terms: ['old1', 'old2'] });
            const updatedRule = createMockRule({ id: 1, terms: ['new1', 'new2', 'new3'] });

            mockAxios.put.mockResolvedValueOnce({ data: updatedRule });

            const { rules, updateRule } = useModerationRules();
            rules.value = [existingRule];

            const result = await updateRule(1, { terms: ['new1', 'new2', 'new3'] });

            expect(result?.terms).toEqual(['new1', 'new2', 'new3']);
            expect(rules.value[0].terms).toEqual(['new1', 'new2', 'new3']);
        });

        it('updates rule active state', async () => {
            const existingRule = createMockRule({ id: 1, active: true });
            const updatedRule = createMockRule({ id: 1, active: false });

            mockAxios.put.mockResolvedValueOnce({ data: updatedRule });

            const { rules, updateRule } = useModerationRules();
            rules.value = [existingRule];

            const result = await updateRule(1, { active: false });

            expect(result?.active).toBe(false);
            expect(rules.value[0].active).toBe(false);
        });
    });

    describe('deleteRule', () => {
        it('deletes a rule successfully', async () => {
            const existingRule = createMockRule({ id: 1, name: 'To Delete' });
            mockAxios.delete.mockResolvedValueOnce({});

            const { rules, deleteRule, error } = useModerationRules();
            rules.value = [existingRule];

            const result = await deleteRule(1);

            expect(mockAxios.delete).toHaveBeenCalledWith('/api/moderation-rules/1');
            expect(result).toBe(true);
            expect(rules.value).toHaveLength(0);
            expect(error.value).toBeNull();
        });

        it('handles delete error gracefully', async () => {
            const existingRule = createMockRule({ id: 1, name: 'To Delete' });
            mockAxios.delete.mockRejectedValueOnce(new Error('Not found'));

            const { rules, deleteRule, error } = useModerationRules();
            rules.value = [existingRule];

            const result = await deleteRule(1);

            expect(result).toBe(false);
            expect(rules.value).toHaveLength(1); // Should remain unchanged
            expect(error.value).toBe('Failed to delete moderation rule');
        });

        it('removes correct rule from list with multiple rules', async () => {
            const rule1 = createMockRule({ id: 1, name: 'Rule 1' });
            const rule2 = createMockRule({ id: 2, name: 'Rule 2' });
            const rule3 = createMockRule({ id: 3, name: 'Rule 3' });

            mockAxios.delete.mockResolvedValueOnce({});

            const { rules, deleteRule } = useModerationRules();
            rules.value = [rule1, rule2, rule3];

            await deleteRule(2);

            expect(rules.value).toHaveLength(2);
            expect(rules.value.find(r => r.id === 2)).toBeUndefined();
            expect(rules.value[0].id).toBe(1);
            expect(rules.value[1].id).toBe(3);
        });
    });

    describe('toggleRuleActive', () => {
        it('toggles active to inactive', async () => {
            const existingRule = createMockRule({ id: 1, active: true });
            const updatedRule = createMockRule({ id: 1, active: false });

            mockAxios.put.mockResolvedValueOnce({ data: updatedRule });

            const { rules, toggleRuleActive } = useModerationRules();
            rules.value = [existingRule];

            const result = await toggleRuleActive(1);

            expect(mockAxios.put).toHaveBeenCalledWith('/api/moderation-rules/1', { active: false });
            expect(result).toBe(true);
            expect(rules.value[0].active).toBe(false);
        });

        it('toggles inactive to active', async () => {
            const existingRule = createMockRule({ id: 1, active: false });
            const updatedRule = createMockRule({ id: 1, active: true });

            mockAxios.put.mockResolvedValueOnce({ data: updatedRule });

            const { rules, toggleRuleActive } = useModerationRules();
            rules.value = [existingRule];

            const result = await toggleRuleActive(1);

            expect(mockAxios.put).toHaveBeenCalledWith('/api/moderation-rules/1', { active: true });
            expect(result).toBe(true);
            expect(rules.value[0].active).toBe(true);
        });

        it('returns false for non-existent rule', async () => {
            const { rules, toggleRuleActive } = useModerationRules();
            rules.value = [];

            const result = await toggleRuleActive(999);

            expect(result).toBe(false);
            expect(mockAxios.put).not.toHaveBeenCalled();
        });
    });

    describe('getActiveRules', () => {
        it('returns only active rules', () => {
            const { rules, getActiveRules } = useModerationRules();

            rules.value = [
                createMockRule({ id: 1, active: true }),
                createMockRule({ id: 2, active: false }),
                createMockRule({ id: 3, active: true }),
            ];

            const activeRules = getActiveRules();

            expect(activeRules).toHaveLength(2);
            expect(activeRules.every(r => r.active)).toBe(true);
            expect(activeRules.map(r => r.id)).toEqual([1, 3]);
        });

        it('returns empty array when no active rules', () => {
            const { rules, getActiveRules } = useModerationRules();

            rules.value = [
                createMockRule({ id: 1, active: false }),
                createMockRule({ id: 2, active: false }),
            ];

            const activeRules = getActiveRules();

            expect(activeRules).toHaveLength(0);
        });
    });

    describe('getInactiveRules', () => {
        it('returns only inactive rules', () => {
            const { rules, getInactiveRules } = useModerationRules();

            rules.value = [
                createMockRule({ id: 1, active: true }),
                createMockRule({ id: 2, active: false }),
                createMockRule({ id: 3, active: false }),
            ];

            const inactiveRules = getInactiveRules();

            expect(inactiveRules).toHaveLength(2);
            expect(inactiveRules.every(r => !r.active)).toBe(true);
            expect(inactiveRules.map(r => r.id)).toEqual([2, 3]);
        });

        it('returns empty array when all rules are active', () => {
            const { rules, getInactiveRules } = useModerationRules();

            rules.value = [
                createMockRule({ id: 1, active: true }),
                createMockRule({ id: 2, active: true }),
            ];

            const inactiveRules = getInactiveRules();

            expect(inactiveRules).toHaveLength(0);
        });
    });

    describe('getRulesByNsfw', () => {
        it('returns NSFW rules when nsfw=true', () => {
            const { rules, getRulesByNsfw } = useModerationRules();

            rules.value = [
                createMockRule({ id: 1, nsfw: true }),
                createMockRule({ id: 2, nsfw: false }),
                createMockRule({ id: 3, nsfw: true }),
            ];

            const nsfwRules = getRulesByNsfw(true);

            expect(nsfwRules).toHaveLength(2);
            expect(nsfwRules.every(r => r.nsfw)).toBe(true);
        });

        it('returns SFW rules when nsfw=false', () => {
            const { rules, getRulesByNsfw } = useModerationRules();

            rules.value = [
                createMockRule({ id: 1, nsfw: true }),
                createMockRule({ id: 2, nsfw: false }),
                createMockRule({ id: 3, nsfw: false }),
            ];

            const sfwRules = getRulesByNsfw(false);

            expect(sfwRules).toHaveLength(2);
            expect(sfwRules.every(r => !r.nsfw)).toBe(true);
        });
    });

    describe('operation types', () => {
        it('handles all operation type', async () => {
            const newRule = createMockRule({ op: 'all', terms: ['a', 'b', 'c'] });
            mockAxios.post.mockResolvedValueOnce({ data: newRule });

            const { createRule } = useModerationRules();

            const result = await createRule({
                op: 'all',
                terms: ['a', 'b', 'c'],
            });

            expect(result?.op).toBe('all');
        });

        it('handles not_any operation type', async () => {
            const newRule = createMockRule({ op: 'not_any', terms: ['spam'] });
            mockAxios.post.mockResolvedValueOnce({ data: newRule });

            const { createRule } = useModerationRules();

            const result = await createRule({
                op: 'not_any',
                terms: ['spam'],
            });

            expect(result?.op).toBe('not_any');
        });
    });
});

