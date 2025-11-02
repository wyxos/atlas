import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import GridItem from '@/components/browse/GridItem.vue'

// Spy IntersectionObserver constructor options
class IOStub {
  constructor(public cb: any, public options: any) {}
  observe() {}
  unobserve() {}
  disconnect() {}
}

describe('GridItem fallback IO', () => {
  it('creates a local IntersectionObserver with expected options when no browse-io provided', async () => {
    const orig = (global as any).IntersectionObserver
    const ctorSpy = vi.fn((cb: any, opts: any) => new IOStub(cb, opts))
    ;(global as any).IntersectionObserver = ctorSpy as any

    const item = { id: 1, metadata: { prompt: 'foo', moderation: null }, containers: [] }

    const wrapper = mount(GridItem as any, {
      props: { item },
      global: {
        provide: {
          'browse-items': ref([item]),
          // no 'browse-io' on purpose to trigger fallback
          'browse-container-counts': new Map(),
          'browse-scroller': { removeMany: () => {}, remove: () => {}, refreshLayout: () => {}, loadNext: () => {} },
          'browse-schedule-refresh': () => {},
        },
        stubs: { FileReactions: true, Button: true, LoaderOverlay: true, Tooltip: true, TooltipProvider: true, TooltipTrigger: true, TooltipContent: true },
      },
      attachTo: document.body,
    })

    expect(ctorSpy).toHaveBeenCalled()
    const call = ctorSpy.mock.calls[0]
    expect(call[1]).toMatchObject({ root: null, rootMargin: '300px 0px' })

    wrapper.unmount()
    ;(global as any).IntersectionObserver = orig
  })
})