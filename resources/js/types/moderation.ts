/**
 * Moderation rule operation types.
 * - 'any': Match if any term is present
 * - 'all': Match if all terms are present
 * - 'not_any': Match if none of the terms are present
 * - 'at_least': Match if at least `min` terms are present
 * - 'and': Match if all child rules match
 * - 'or': Match if any child rule matches
 */
export type ModerationRuleOp = 'any' | 'all' | 'not_any' | 'at_least' | 'and' | 'or';

/**
 * Options for term matching behavior.
 */
export interface ModerationRuleOptions {
    case_sensitive?: boolean;
    whole_word?: boolean;
}

/**
 * A node structure for nested rule logic (used in 'and'/'or' operations).
 */
export interface ModerationRuleNode {
    op: ModerationRuleOp;
    terms?: string[];
    min?: number;
    options?: ModerationRuleOptions;
    children?: ModerationRuleNode[];
}

/**
 * The main ModerationRule interface representing a database record.
 */
export interface ModerationRule {
    id: number;
    name: string | null;
    active: boolean;
    nsfw: boolean;
    op: ModerationRuleOp;
    terms: string[] | null;
    min: number | null;
    options: ModerationRuleOptions | null;
    children: ModerationRuleNode[] | null;
    created_at: string;
    updated_at: string;
}

/**
 * Payload for creating a new moderation rule.
 */
export interface CreateModerationRulePayload {
    name?: string | null;
    active?: boolean;
    nsfw?: boolean;
    op: ModerationRuleOp;
    terms?: string[] | null;
    min?: number | null;
    options?: ModerationRuleOptions | null;
    children?: ModerationRuleNode[] | null;
}

/**
 * Payload for updating an existing moderation rule.
 */
export interface UpdateModerationRulePayload {
    name?: string | null;
    active?: boolean;
    nsfw?: boolean;
    op?: ModerationRuleOp;
    terms?: string[] | null;
    min?: number | null;
    options?: ModerationRuleOptions | null;
    children?: ModerationRuleNode[] | null;
}

/**
 * Human-readable labels for operation types.
 */
export const OPERATION_LABELS: Record<ModerationRuleOp, string> = {
    any: 'Match any term',
    all: 'Match all terms',
    not_any: 'Exclude any term',
    at_least: 'Match at least N terms',
    and: 'All conditions must match',
    or: 'Any condition can match',
};

/**
 * Helper to determine if an operation uses terms (vs children).
 */
export function operationUsesTerms(op: ModerationRuleOp): boolean {
    return ['any', 'all', 'not_any', 'at_least'].includes(op);
}

/**
 * Helper to determine if an operation uses children (nested rules).
 */
export function operationUsesChildren(op: ModerationRuleOp): boolean {
    return ['and', 'or'].includes(op);
}

/**
 * Helper to determine if an operation requires the 'min' field.
 */
export function operationRequiresMin(op: ModerationRuleOp): boolean {
    return op === 'at_least';
}

