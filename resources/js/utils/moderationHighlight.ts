/**
 * Moderation Highlight Utility
 * 
 * Mirrors the PHP Moderator::termMatches() logic for highlighting matched terms in prompts.
 * Respects rule options: whole_word (default true) and case_sensitive (default false).
 * Handles underscore/space interchangeability in phrases.
 */

export interface ModerationOptions {
  case_sensitive?: boolean
  whole_word?: boolean
  allow_digit_prefix?: boolean
}

export interface MatchRange {
  start: number
  end: number
}

/**
 * Escape HTML special characters
 */
export function escapeHtml(input: string): string {
  return input
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

/**
 * Escape regex metacharacters for use in a RegExp pattern
 */
function escapeRegex(str: string): string {
  return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * Build a RegExp that mirrors PHP Moderator::termMatches()
 * 
 * Handles:
 * - Whole word boundaries (treating underscores as separators, not word chars)
 * - Case sensitivity
 * - Phrase matching with space/underscore interchangeability
 */
export function buildTermRegex(term: string, options: ModerationOptions = {}): RegExp {
  const caseSensitive = options.case_sensitive ?? false
  const wholeWord = options.whole_word ?? true
  const flags = caseSensitive ? 'gu' : 'giu'

  // Split term into tokens by spaces/underscores
  const tokens = term.split(/[\s_]+/).filter(t => t.length > 0)
  
  if (tokens.length === 0) {
    // Empty term should never match
    return new RegExp('(?!.*)', flags)
  }

  // Escape each token for regex
  const escapedTokens = tokens.map(escapeRegex)
  
  // Join tokens with a separator pattern that matches spaces, underscores, and other non-word chars
  // This mirrors the PHP pattern: (?:\s|_)+
  // We use [\s_]+ to match one or more spaces or underscores
  const separatorPattern = '[\\s_]+'
  const patternBody = escapedTokens.join(separatorPattern)

  if (wholeWord) {
    const allowDigitPrefix = options.allow_digit_prefix ?? false
    
    // Require non-letter/digit boundaries around the entire term/phrase
    // Underscores are treated as boundaries, not word characters
    // PHP uses: (?:^|[^\p{L}\p{N}]) ... (?:$|[^\p{L}\p{N}])
    // In JS, we use: (?:^|[^A-Za-z0-9]) ... (?:$|[^A-Za-z0-9])
    
    // Use lookaheads/lookbehinds for non-capturing boundary checks
    let boundaryStart = '(?:^|(?<=[^A-Za-z0-9]))'
    if (allowDigitPrefix) {
      // Allow digits before the term
      boundaryStart = '(?:^|(?<=[^A-Za-z0-9])|(?<=[0-9]))'
    }
    
    // Allow a trailing 's' for plurals when allowDigitPrefix is true
    const boundaryEnd = allowDigitPrefix 
      ? '(?:s(?=$|[^A-Za-z0-9])|$|(?=[^A-Za-z0-9]))'
      : '(?:$|(?=[^A-Za-z0-9]))'
    
    const pattern = `${boundaryStart}${patternBody}${boundaryEnd}`
    
    try {
      return new RegExp(pattern, flags)
    } catch {
      // Fallback for environments without lookbehind support
      const fallbackBoundaryStart = allowDigitPrefix 
        ? '(?:^|([^A-Za-z0-9])|[0-9])'
        : '(?:^|([^A-Za-z0-9]))'
      const fallbackBoundaryEnd = allowDigitPrefix
        ? '(?:s(?=$|[^A-Za-z0-9])|$|(?=[^A-Za-z0-9]))'
        : '(?:$|(?=[^A-Za-z0-9]))'
      const fallbackPattern = `${fallbackBoundaryStart}${patternBody}${fallbackBoundaryEnd}`
      return new RegExp(fallbackPattern, flags)
    }
  } else {
    // Substring matching - no boundary enforcement
    return new RegExp(patternBody, flags)
  }
}

/**
 * Extract all terms with their per-term options from a rule node (recursively)
 */
export type TermEntry = string | { term: string; allow_digit_prefix?: boolean }
export type RuleNode = {
  op: 'any' | 'all' | 'not_any' | 'at_least' | 'and' | 'or'
  terms?: TermEntry[] | null
  options?: { case_sensitive?: boolean; whole_word?: boolean } | null
  children?: RuleNode[] | null
}

function extractTermsWithOptions(node: RuleNode | null | undefined): Map<string, ModerationOptions> {
  const termMap = new Map<string, ModerationOptions>()
  
  if (!node) return termMap
  
  const globalOptions: ModerationOptions = {
    case_sensitive: node.options?.case_sensitive ?? false,
    whole_word: node.options?.whole_word ?? true,
  }
  
  // Extract terms from this node
  if (node.terms && Array.isArray(node.terms)) {
    for (const termEntry of node.terms) {
      if (typeof termEntry === 'string') {
        const term = termEntry.trim()
        if (term && !termMap.has(term.toLowerCase())) {
          termMap.set(term.toLowerCase(), { ...globalOptions })
        }
      } else if (termEntry && typeof termEntry === 'object' && 'term' in termEntry) {
        const term = String(termEntry.term).trim()
        if (term && !termMap.has(term.toLowerCase())) {
          termMap.set(term.toLowerCase(), {
            ...globalOptions,
            allow_digit_prefix: termEntry.allow_digit_prefix ?? false,
          })
        }
      }
    }
  }
  
  // Recursively extract from children
  if (node.children && Array.isArray(node.children)) {
    for (const child of node.children) {
      const childTerms = extractTermsWithOptions(child)
      for (const [term, options] of childTerms) {
        // Prefer existing options if term already exists (first encountered wins)
        if (!termMap.has(term)) {
          termMap.set(term, options)
        }
      }
    }
  }
  
  return termMap
}

/**
 * Find all match ranges for the given terms in the prompt
 * Can accept either a simple terms array with global options, or a rule node for per-term options
 */
export function findMatchRanges(
  prompt: string,
  terms: string[],
  optionsOrRule: ModerationOptions | RuleNode = {}
): MatchRange[] {
  const ranges: MatchRange[] = []
  
  // Determine if we have a rule node or just options
  const isRuleNode = optionsOrRule && typeof optionsOrRule === 'object' && 'op' in optionsOrRule
  const ruleNode = isRuleNode ? (optionsOrRule as RuleNode) : null
  const globalOptions = isRuleNode ? {} : (optionsOrRule as ModerationOptions)
  
  // Build term-to-options map
  const termOptionsMap = ruleNode ? extractTermsWithOptions(ruleNode) : new Map<string, ModerationOptions>()
  
  // Deduplicate terms (case-insensitively) to avoid redundant processing
  const seenLower = new Set<string>()
  const uniqueTerms: string[] = []
  
  for (const term of terms) {
    const termLower = term.toLowerCase()
    if (!seenLower.has(termLower)) {
      seenLower.add(termLower)
      uniqueTerms.push(term)
    }
  }

  // Find all matches for each term
  for (const term of uniqueTerms) {
    if (!term || term.trim().length === 0) continue
    
    // Get per-term options if available, otherwise use global options
    const termLower = term.toLowerCase()
    const termSpecificOptions = termOptionsMap.has(termLower) 
      ? termOptionsMap.get(termLower)! 
      : globalOptions
    
    const regex = buildTermRegex(term, termSpecificOptions)
    let match: RegExpExecArray | null
    
    while ((match = regex.exec(prompt)) !== null) {
      // Check if this regex has a capturing group (fallback pattern)
      // If group 1 exists and captured a boundary char, adjust start position
      const hasBoundaryCapture = match[1] !== undefined
      const start = hasBoundaryCapture && match[1] ? match.index + match[1].length : match.index
      const end = start + (hasBoundaryCapture && match[1] ? match[0].length - match[1].length : match[0].length)
      
      ranges.push({ start, end })
      
      // Prevent infinite loop on zero-width matches
      if (match.index === regex.lastIndex) {
        regex.lastIndex++
      }
    }
  }

  return ranges
}

/**
 * Merge overlapping or adjacent ranges
 */
export function mergeRanges(ranges: MatchRange[]): MatchRange[] {
  if (ranges.length === 0) return []
  
  // Sort by start position, then by end position (descending)
  const sorted = [...ranges].sort((a, b) => {
    if (a.start !== b.start) return a.start - b.start
    return b.end - a.end
  })
  
  const merged: MatchRange[] = []
  let current = { ...sorted[0] }
  
  for (let i = 1; i < sorted.length; i++) {
    const next = sorted[i]
    
    if (next.start <= current.end) {
      // Overlapping or adjacent - merge
      current.end = Math.max(current.end, next.end)
    } else {
      // Non-overlapping - push current and start new range
      merged.push(current)
      current = { ...next }
    }
  }
  
  merged.push(current)
  return merged
}

/**
 * Highlight prompt HTML with matched terms wrapped in <mark> tags
 * 
 * @param prompt The raw prompt text
 * @param terms The list of matched terms from moderation hits
 * @param optionsOrRule Moderation rule options (case_sensitive, whole_word) OR a rule node for per-term options
 * @returns HTML string with highlighted terms
 */
export function highlightPromptHtml(
  prompt: string,
  terms: string[],
  optionsOrRule: ModerationOptions | RuleNode = {}
): string {
  if (!prompt) return ''
  if (!terms || terms.length === 0) return escapeHtml(prompt)
  
  // Find all match ranges (will handle per-term options if rule node is provided)
  const ranges = findMatchRanges(prompt, terms, optionsOrRule)
  if (ranges.length === 0) return escapeHtml(prompt)
  
  // Merge overlapping ranges
  const merged = mergeRanges(ranges)
  
  // Build HTML by interleaving escaped text and highlighted regions
  let html = ''
  let pos = 0
  
  for (const range of merged) {
    // Add non-highlighted text before this range
    if (pos < range.start) {
      html += escapeHtml(prompt.slice(pos, range.start))
    }
    
    // Add highlighted text
    html += '<mark class="rounded bg-amber-300/60 px-0.5 py-0">'
    html += escapeHtml(prompt.slice(range.start, range.end))
    html += '</mark>'
    
    pos = range.end
  }
  
  // Add remaining text after last highlight
  if (pos < prompt.length) {
    html += escapeHtml(prompt.slice(pos))
  }
  
  return html
}
