import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import GridItem from '@/components/browse/GridItem.vue'

const TP = { name: 'TooltipProvider', template: '<div><slot /></div>' }
const TT = { name: 'TooltipTrigger', template: '<div><slot /></div>' }
const TC = { name: 'TooltipContent', template: '<div data-test="tooltip-content"><slot /></div>' }
const T = { name: 'Tooltip', template: '<div><slot /><slot name="content" /></div>' }

describe('GridItem highlight prompt rendering', () => {
  it('renders escaped prompt with <mark> wrappers for moderation hits', async () => {
    const item = {
      id: 1,
      metadata: {
        prompt: 'Foo <b>bar</b>',
        moderation: { reason: 'moderation:rule', rule_id: 9, hits: ['Foo','bar'] },
      },
      containers: [],
    }

    const wrapper = mount(GridItem as any, {
      props: { item },
      global: {
        provide: {
          'browse-items': ref([item]),
          'browse-container-counts': new Map(),
          'browse-scroller': { removeMany: () => {}, remove: () => {}, refreshLayout: () => {}, loadNext: () => {} },
          'browse-schedule-refresh': () => {},
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

    const content = wrapper.find('[data-test="tooltip-content"]')
    expect(content.exists()).toBe(true)
    const html = content.html()
    expect(html).toContain('<mark')
    // Angle brackets should be escaped around the second match
    expect(html).toContain('&lt;b&gt;')
    expect(html).toContain('&lt;/b&gt;')
    // Banner text rendered
    expect(wrapper.text()).toContain('Auto-blacklisted')

    wrapper.unmount()
  })
})