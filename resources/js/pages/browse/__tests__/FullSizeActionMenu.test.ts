import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import Index from '@/pages/browse/Index.vue'

// Polyfill IntersectionObserver for VTU environment
class MockIntersectionObserver {
  private _cb: (entries: any[]) => void
  constructor(cb: (entries: any[]) => void) {
    this._cb = cb
  }
  observe() {
    // Immediately report intersecting to render media
    this._cb([{ isIntersecting: true }])
  }
  unobserve() {}
  disconnect() {}
}

(global as any).IntersectionObserver = MockIntersectionObserver as any

// Mock Inertia's useForm and Head
vi.mock('@inertiajs/vue3', () => {
  const state: any = {
    source: 'test-source',
    nsfw: 0,
    sort: 'Newest',
    limit: 20,
    page: 1,
    next: null,
  }
  return {
    Head: {
      name: 'Head',
      template: '<template><slot /></template>',
    },
    useForm: (initial: any) => {
      Object.assign(state, initial || {})
      return {
        ...state,
        data: () => ({ ...state }),
        defaults: (v: any) => Object.assign(state, v || {}),
        reset: () => void 0,
      } as any
    },
    usePage: () => ({ props: { auth: { user: { is_admin: false } } } }),
  }
})

// Mock axios to avoid network
vi.mock('axios', () => ({
  default: { post: vi.fn(() => Promise.resolve({ data: {} })) },
}))

// Provide a minimal Masonry stub that supports v-model:items and scroller methods
const MasonryStub = {
  name: 'Masonry',
  inheritAttrs: true,
  props: {
    items: { type: Array, default: () => [] },
    getNextPage: { type: Function, default: null },
    pageSize: { type: Number, default: 20 },
  },
  emits: ['update:items', 'backfill:start', 'backfill:tick', 'backfill:stop', 'retry:start', 'retry:tick', 'retry:stop'],
  methods: {
    init(files: any[]) {
      this.$emit('update:items', Array.isArray(files) ? files : [])
    },
    async loadNext() { return [] },
    reset() {},
    remove() {},
    async removeMany() {},
    refreshLayout() {},
    layout() {},
  },
  template: `
    <div data-test="masonry-stub" v-bind="$attrs">
      <slot name="item" v-for="(it, i) in items" :item="it" :index="i" />
    </div>
  `,
}

// Stubs for layouts to keep render minimal
const AppLayoutStub = { name: 'AppLayout', template: '<div data-test="app-layout"><slot /></div>' }
const ContentLayoutStub = { name: 'ContentLayout', template: '<div data-test="content-layout"><slot /></div>' }
const ScrollableLayoutStub = { name: 'ScrollableLayout', template: '<div data-test="scrollable-layout"><slot /></div>' }

function mountBrowse(overrides: Record<string, any> = {}) {
  const files = overrides.files ?? [
    { id: 1, type: 'image', title: 'Sample', preview: 'about:blank', original: 'about:blank', containers: [{ key: 'tag', value: 'x', label: 'x' }] },
  ]
  const filter = overrides.filter ?? { source: 'test-source', nsfw: 0, sort: 'Newest', limit: 20, page: 1, next: null }
  const services = overrides.services ?? []

  return mount(Index, {
    props: { files, filter, services },
    global: {
      stubs: {
        Masonry: MasonryStub,
        // Keep ActionMenu real to observe teleported panel
        AppLayout: AppLayoutStub,
        ContentLayout: ContentLayoutStub,
        ScrollableLayout: ScrollableLayoutStub,
        SectionHeader: true,
        Button: true,
        Label: true,
        LoaderOverlay: true,
        FileReactions: true,
      },
    },
    attachTo: document.body,
  })
}

describe('Full-size action menu', () => {
  it('opens in full-size mode via contextmenu on media', async () => {
    const wrapper = mountBrowse()
    await nextTick(); await nextTick()

    // Click a grid item to open dialog (GridItem emits 'open')
    const gridButton = wrapper.find('[data-test="grid-item"]')
    if (gridButton.exists()) {
      // If test uses previous stubbed Index test style; otherwise click preview image
      await gridButton.trigger('click')
    } else {
      const preview = wrapper.find('img')
      expect(preview.exists()).toBe(true)
      await preview.trigger('click')
    }
    await nextTick()

    // Find the full-size media and open context menu
    const fullImg = document.querySelector('img[alt="Full size"]') as HTMLElement | null
    const fullVideo = document.querySelector('video') as HTMLElement | null
    const target = fullImg || fullVideo
    expect(target).toBeTruthy()

    const evt = new MouseEvent('contextmenu', { bubbles: true, cancelable: true })
    target!.dispatchEvent(evt)
    await nextTick()

    // The ActionMenu renders a header with 'Options' and buttons like 'react'
    const menu = Array.from(document.querySelectorAll('div')).find((d) => /Options/i.test(d.textContent || ''))
    expect(menu).toBeTruthy()
  })
})
