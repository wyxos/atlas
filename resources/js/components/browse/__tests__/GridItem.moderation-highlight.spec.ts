/**
 * GridItem Moderation Highlighting Tests
 * 
 * Comprehensive test suite covering all moderation rule scenarios from BlockTermsTest.php
 * Tests verify that the frontend highlighting logic mirrors the PHP Moderator::termMatches() behavior
 */

import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import GridItem from '@/components/browse/GridItem.vue'

// Component stubs (consistent with GridItem.highlight.spec.ts)
const TP = { name: 'TooltipProvider', template: '<div><slot /></div>' }
const TT = { name: 'TooltipTrigger', template: '<div><slot /></div>' }
const TC = { name: 'TooltipContent', template: '<div data-test="tooltip-content"><slot /></div>' }
const T = { name: 'Tooltip', template: '<div><slot /><slot name="content" /></div>' }

// Test helpers
interface ModerationMeta {
  reason: string
  rule_id?: number
  rule_name?: string
  options?: { case_sensitive?: boolean; whole_word?: boolean }
  hits?: string[]
}

function createModerationMeta(config: {
  options?: { case_sensitive?: boolean; whole_word?: boolean }
  hits?: string[]
  id?: number
  name?: string
}): ModerationMeta {
  return {
    reason: 'moderation:rule',
    rule_id: config.id ?? 1,
    rule_name: config.name ?? 'Test Rule',
    options: config.options,
    hits: config.hits ?? [],
  }
}

function mountGridItem(item: any) {
  return mount(GridItem as any, {
    props: { item },
    global: {
      provide: {
        'browse-items': ref([item]),
        'browse-container-counts': new Map(),
        'browse-scroller': {
          removeMany: vi.fn(),
          remove: vi.fn(),
          refreshLayout: vi.fn(),
          loadNext: vi.fn(),
        },
        'browse-schedule-refresh': vi.fn(),
      },
      stubs: {
        FileReactions: { template: '<div />' },
        Button: { template: '<button><slot /></button>' },
        LoaderOverlay: { template: '<div />' },
        TooltipProvider: TP,
        Tooltip: T,
        TooltipTrigger: TT,
        TooltipContent: TC,
        ActionMenu: { template: '<div />' },
      },
    },
    attachTo: document.body,
  })
}

function getTooltipHtml(wrapper: any): string {
  const content = wrapper.find('[data-test="tooltip-content"]')
  if (!content.exists()) return ''
  return content.html()
}

function countMarks(html: string): number {
  const matches = html.match(/<mark[^>]*>/g)
  return matches ? matches.length : 0
}

function expectHighlighted(html: string, snippets: string[]) {
  for (const snippet of snippets) {
    const escaped = snippet
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;')
    
    const regex = new RegExp(`<mark[^>]*>${escaped.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}</mark>`)
    expect(html).toMatch(regex)
  }
}

describe('GridItem moderation highlighting', () => {
  describe('basic contains and negatives', () => {
    it('contains car - highlights the word', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'This is a car',
          moderation: createModerationMeta({
            hits: ['car'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(1)
      expectHighlighted(html, ['car'])
      wrapper.unmount()
    })

    it('does not contain car - no highlights', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'This is a bike',
          moderation: createModerationMeta({
            hits: ['car'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(0)
      wrapper.unmount()
    })

    it('any of multiple terms - highlights all present', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'The fast lane with a car',
          moderation: createModerationMeta({
            hits: ['car', 'red', 'fast'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(2)
      expectHighlighted(html, ['car', 'fast'])
      wrapper.unmount()
    })
  })

  describe('all terms must match', () => {
    it('contains all terms - highlights all', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'A red fast car zooms by',
          moderation: createModerationMeta({
            hits: ['car', 'red', 'fast'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(3)
      expectHighlighted(html, ['red', 'fast', 'car'])
      wrapper.unmount()
    })

    it('missing one term - only highlights present terms', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'A red car is slow',
          moderation: createModerationMeta({
            hits: ['car', 'red', 'fast'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      // Only red and car should be highlighted (fast is missing)
      expect(countMarks(html)).toBe(2)
      expectHighlighted(html, ['red', 'car'])
      wrapper.unmount()
    })
  })

  describe('word boundary enforcement', () => {
    it('whole_word true - car matches isolated car', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'This is a car',
          moderation: createModerationMeta({
            hits: ['car'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(1)
      expectHighlighted(html, ['car'])
      wrapper.unmount()
    })

    it('whole_word true - car does NOT match nascar', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'Watching nascar tonight',
          moderation: createModerationMeta({
            hits: ['car'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(0)
      wrapper.unmount()
    })

    it('whole_word true - car does NOT match scar', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'a scar on the arm',
          moderation: createModerationMeta({
            hits: ['car'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(0)
      wrapper.unmount()
    })

    it('whole_word false - car DOES match nascar', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'Watching nascar tonight',
          moderation: createModerationMeta({
            hits: ['car'],
            options: { whole_word: false, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(1)
      wrapper.unmount()
    })

    it('whole_word false - car DOES match carpet', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'carpet is soft',
          moderation: createModerationMeta({
            hits: ['car'],
            options: { whole_word: false, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(1)
      wrapper.unmount()
    })

    it('parenthesized whole word - (car) matches', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'Look (car) here',
          moderation: createModerationMeta({
            hits: ['car'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(1)
      expectHighlighted(html, ['car'])
      wrapper.unmount()
    })

    it('double parenthesized whole word - ((word1)) matches', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'Text with ((word1)) in it',
          moderation: createModerationMeta({
            hits: ['word1'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(1)
      expectHighlighted(html, ['word1'])
      wrapper.unmount()
    })

    it('multiple terms with double parentheses - ((word1)), word2, word3', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'Text with ((word1)) and word2 and word3 here',
          moderation: createModerationMeta({
            hits: ['word1', 'word2', 'word3'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(3)
      expectHighlighted(html, ['word1', 'word2', 'word3'])
      wrapper.unmount()
    })

    it('multiple terms all with double parentheses', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'Text with ((word1)) and ((word2)) and ((word3)) here',
          moderation: createModerationMeta({
            hits: ['word1', 'word2', 'word3'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(3)
      expectHighlighted(html, ['word1', 'word2', 'word3'])
      wrapper.unmount()
    })

    it('underscore separator - red_car contains car as whole word', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'Spotted a red_car nearby',
          moderation: createModerationMeta({
            hits: ['car'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(1)
      expectHighlighted(html, ['car'])
      wrapper.unmount()
    })

    it('name carrey should not match car with whole_word', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'Jim Carrey is funny',
          moderation: createModerationMeta({
            hits: ['car'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(0)
      wrapper.unmount()
    })
  })

  describe('case sensitivity', () => {
    it('case-insensitive (default) - Car matches car', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'Car and Truck',
          moderation: createModerationMeta({
            hits: ['car'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(1)
      expectHighlighted(html, ['Car'])
      wrapper.unmount()
    })

    it('case-sensitive true - Car does NOT match car', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'Car and Truck',
          moderation: createModerationMeta({
            hits: ['car'],
            options: { whole_word: true, case_sensitive: true },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(0)
      wrapper.unmount()
    })

    it('case-sensitive true - car matches car exactly', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'car and Truck',
          moderation: createModerationMeta({
            hits: ['car'],
            options: { whole_word: true, case_sensitive: true },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(1)
      expectHighlighted(html, ['car'])
      wrapper.unmount()
    })
  })

  describe('punctuation tolerance and phrases', () => {
    it('punctuation tolerant - highlights all variants', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'red, fast, car!',
          moderation: createModerationMeta({
            hits: ['car', 'red', 'fast'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(3)
      expectHighlighted(html, ['red', 'fast', 'car'])
      wrapper.unmount()
    })

    it('phrase match - red car highlights together', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'lovely red car on display',
          moderation: createModerationMeta({
            hits: ['red car'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(1)
      expectHighlighted(html, ['red car'])
      wrapper.unmount()
    })

    it('phrase with mixed case and underscores - green eyes matches green_eyes', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'She has Green eyes and her friend has green_eyes',
          moderation: createModerationMeta({
            hits: ['green eyes'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      // Should match both occurrences
      expect(countMarks(html)).toBe(2)
      wrapper.unmount()
    })

    it('underscore in rule matches spaced phrase - red_car matches red car', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'A lovely Red car on display',
          moderation: createModerationMeta({
            hits: ['red_car'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(1)
      wrapper.unmount()
    })

    it('underscore in rule with parentheses - matches (red car)', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'Look at (Red car) now',
          moderation: createModerationMeta({
            hits: ['red_car'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(1)
      wrapper.unmount()
    })

    it('space in rule matches underscored text - red car matches red_car', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'Spotted a red_car nearby',
          moderation: createModerationMeta({
            hits: ['red car'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(1)
      wrapper.unmount()
    })

    it('underscore in rule with parentheses - matches (green eyes)', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'They whispered about (green eyes) in the hall',
          moderation: createModerationMeta({
            hits: ['green_eyes'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(1)
      wrapper.unmount()
    })
  })

  describe('overlapping matches and merging', () => {
    it('overlapping - car and red car merge into single mark', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'A lovely red car here',
          moderation: createModerationMeta({
            hits: ['car', 'red car'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      // Should have single merged mark spanning "red car"
      expect(countMarks(html)).toBe(1)
      expectHighlighted(html, ['red car'])
      wrapper.unmount()
    })

    it('HTML escaping - angle brackets in prompt', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'Foo <b>bar</b>',
          moderation: createModerationMeta({
            hits: ['Foo', 'bar'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      // Angle brackets should be escaped
      expect(html).toContain('&lt;b&gt;')
      expect(html).toContain('&lt;/b&gt;')
      expect(countMarks(html)).toBe(2)
      wrapper.unmount()
    })
  })

  describe('complex scenarios from BlockTermsTest', () => {
    it('at_least scenario - three terms present', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'red fast car near a garage by the sea',
          moderation: createModerationMeta({
            hits: ['red', 'fast', 'car', 'sea', 'garage'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(5)
      expectHighlighted(html, ['red', 'fast', 'car', 'garage', 'sea'])
      wrapper.unmount()
    })

    it('three any groups AND - highlights terms from each group', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'a male wearing a red cap',
          moderation: createModerationMeta({
            hits: ['male', 'cap', 'red'],
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      expect(countMarks(html)).toBe(3)
      expectHighlighted(html, ['male', 'cap', 'red'])
      wrapper.unmount()
    })

    it('default options when not specified', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'Car and Truck',
          moderation: createModerationMeta({
            hits: ['car'],
            // No options specified - should use defaults
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      const html = getTooltipHtml(wrapper)
      
      // Default is whole_word=true, case_sensitive=false
      expect(countMarks(html)).toBe(1)
      expectHighlighted(html, ['Car'])
      wrapper.unmount()
    })

    it('banner text shows rule name', () => {
      const item = {
        id: 1,
        metadata: {
          prompt: 'This is a car',
          moderation: createModerationMeta({
            hits: ['car'],
            name: 'Santa Rule',
            options: { whole_word: true, case_sensitive: false },
          }),
        },
        containers: [],
      }

      const wrapper = mountGridItem(item)
      
      expect(wrapper.text()).toContain('Auto-blacklisted by Santa Rule')
      wrapper.unmount()
    })
  })
})
