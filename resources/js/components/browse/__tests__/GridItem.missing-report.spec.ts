import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import axios from 'axios'
import GridItem from '@/components/browse/GridItem.vue'
import BrowseController from '@/actions/App/Http/Controllers/BrowseController'

vi.mock('axios', () => ({ default: { post: vi.fn(() => Promise.resolve({ data: { ok: true, not_found: true } })) } }))

function makeItem(overrides: any = {}) {
  return {
    id: 123,
    type: 'image',
    preview: 'https://example.com/missing.jpg',
    metadata: { prompt: '', moderation: null },
    containers: [],
    ...overrides,
  }
}

describe('GridItem missing media reporting', () => {
  it('reports missing on image error and shows overlay', async () => {
    const item = makeItem()
    const wrapper = mount(GridItem as any, {
      props: { item },
      global: {
        provide: {
          'browse-items': ref([item]),
          'browse-container-counts': new Map(),
          'browse-scroller': { removeMany: () => {}, remove: () => {}, refreshLayout: () => {}, loadNext: () => {} },
          'browse-schedule-refresh': () => {},
          'browse-io': { observer: { disconnect: () => {} }, register: (_el: Element, fn: () => void) => fn(), unregister: () => {} },
        },
        stubs: {
          FileReactions: true,
          Button: true,
          LoaderOverlay: true,
          Tooltip: true,
          TooltipProvider: true,
          TooltipTrigger: true,
          TooltipContent: true,
        },
      },
      attachTo: document.body,
    })

    // Force visible state
    await wrapper.vm.$nextTick()

    const img = wrapper.find('img')
    expect(img.exists()).toBe(true)

    // Trigger error
    await img.trigger('error')

    // Assert axios called with correct URL
    const expectedUrl = (BrowseController as any).reportMissing({ file: item.id }).url
  expect((axios.post as any)).toHaveBeenCalledWith(expectedUrl, { verify: true })

    // Overlay should render text
    expect(wrapper.text()).toContain('Not found')

    wrapper.unmount()
  })
})
