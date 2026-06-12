import type {
    ModerationRule,
    ModerationRuleBlacklistPreviewedCountMode,
    ModerationRuleNode,
    ModerationRuleTerm,
} from '@/types/moderation';

function extractTermString(term: ModerationRuleTerm): string {
    return typeof term === 'string' ? term : term.term;
}

function joinTerms(termList: ModerationRuleTerm[]): string {
    const termStrings = termList.map(extractTermString);

    return termStrings.slice(0, 3).join(', ') + (termStrings.length > 3 ? '...' : '');
}

export function summarizeRule(rule: ModerationRule | ModerationRuleNode): string {
    const op = rule.op;
    const terms = rule.terms ?? [];
    const min = rule.min;
    const children = rule.children ?? [];

    switch (op) {
        case 'any':
            return `any of: ${joinTerms(terms)}`;
        case 'all':
            return `all of: ${joinTerms(terms)}`;
        case 'not_any':
            return `none of: ${joinTerms(terms)}`;
        case 'at_least':
            return `>=${min ?? 0} of: ${joinTerms(terms)}`;
        case 'and':
            return `AND (${children.length} rules)`;
        case 'or':
            return `OR (${children.length} rules)`;
        default:
            return op;
    }
}

export function previewedCountModeBadgeLabel(mode?: ModerationRuleBlacklistPreviewedCountMode): string {
    return mode === 'feed_removed' ? '99,999' : 'keep count';
}

export function previewedCountModeSelectLabel(mode?: ModerationRuleBlacklistPreviewedCountMode): string {
    return mode === 'feed_removed' ? 'Blacklist, set to 99,999' : 'Blacklist, keep current count';
}
