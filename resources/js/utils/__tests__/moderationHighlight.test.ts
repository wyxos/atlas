import { describe, it, expect } from 'vitest'
import { highlightPromptHtml, findMatchRanges, buildTermRegex } from '../moderationHighlight'

describe('moderationHighlight utility', () => {
  describe('double parentheses handling', () => {
    it('highlights word1 in ((word1))', () => {
      const result = highlightPromptHtml('Text with ((word1)) in it', ['word1'], {
        whole_word: true,
        case_sensitive: false,
      })
      
      expect(result).toContain('<mark')
      expect(result).toContain('word1')
      expect(result).toContain('Text with ((')
      expect(result).toContain(')) in it')
    })

    it('finds correct match range for word1 in ((word1))', () => {
      const ranges = findMatchRanges('Text with ((word1)) in it', ['word1'], {
        whole_word: true,
        case_sensitive: false,
      })
      
      expect(ranges.length).toBe(1)
      expect(ranges[0].start).toBe(12) // Position of 'w' in 'word1'
      expect(ranges[0].end).toBe(17)   // Position after 'word1'
      
      // Verify the extracted text
      const text = 'Text with ((word1)) in it'
      const matched = text.slice(ranges[0].start, ranges[0].end)
      expect(matched).toBe('word1')
    })

    it('handles multiple terms with double parentheses', () => {
      const result = highlightPromptHtml(
        'Text with ((word1)) and ((word2)) and ((word3))',
        ['word1', 'word2', 'word3'],
        { whole_word: true, case_sensitive: false }
      )
      
      expect(result).toContain('<mark')
      const markCount = (result.match(/<mark/g) || []).length
      expect(markCount).toBe(3)
    })

    it('regex matches word1 in ((word1)) correctly', () => {
      const regex = buildTermRegex('word1', { whole_word: true, case_sensitive: false })
      const text = 'Text with ((word1)) in it'
      const match = regex.exec(text)
      
      expect(match).not.toBeNull()
      if (match) {
        // The match should be 'word1' or might include boundary depending on pattern
        expect(match[0]).toMatch(/word1/)
      }
    })
  })
})

