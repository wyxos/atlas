import {
  isValidRegexSource,
  normalizeDomain,
  normalizeRegexSource,
  parseDomainIncludeRules,
  type DomainIncludeRule,
} from '../../shared/domainIncludeRules';

export type DomainRule = DomainIncludeRule;

export type EditablePattern = {
  value: string;
  isEditing: boolean;
  draft: string;
};

export type EditableDomainRule = {
  domain: string;
  draftDomain: string;
  isEditingDomain: boolean;
  addPattern: string;
  patterns: EditablePattern[];
};

export function normalizeDomainInput(input: string): string {
  return normalizeDomain(input);
}

export function normalizePatternInput(input: string): string {
  return normalizeRegexSource(input);
}

export function isValidPatternSource(source: string): boolean {
  return isValidRegexSource(source);
}

export function toEditableDomainRule(rule: DomainRule): EditableDomainRule {
  return {
    domain: rule.domain,
    draftDomain: rule.domain,
    isEditingDomain: false,
    addPattern: '',
    patterns: rule.patterns.map((pattern) => ({
      value: pattern,
      isEditing: false,
      draft: pattern,
    })),
  };
}

export function parseDomainRules(raw: string): DomainRule[] {
  return parseDomainIncludeRules(raw);
}

export function serializeDomainRules(rules: EditableDomainRule[]): DomainRule[] {
  const normalized: DomainRule[] = rules.map((rule) => ({
    domain: rule.domain,
    patterns: rule.patterns.map((pattern) => pattern.value),
  }));

  return parseDomainIncludeRules(normalized);
}
