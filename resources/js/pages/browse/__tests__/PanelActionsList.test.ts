import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import Index from '@/pages/browse/Index.vue'

// Polyfill IntersectionObserver for VTU environment
class MockIntersectionObserver {
  observe() {}
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
import axios from 'axios'
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
  data: () => ({ internal: [] as any[] }),
  methods: {
    init(files: any[]) { this.$emit('update:items', Array.isArray(files) ? files : []) },
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

// Simple layout stubs
const AppLayoutStub = { name: 'AppLayout', template: '<div data-test="app-layout"><slot /></div>' }
const ContentLayoutStub = { name: 'ContentLayout', template: '<div data-test="content-layout"><slot /></div>' }
const ScrollableLayoutStub = { name: 'ScrollableLayout', template: '<div data-test="scrollable-layout"><slot /></div>' }

function mountBrowse(overrides: Record<string, any> = {}) {
  const files = overrides.files ?? [
    { id: 1, type: 'image', title: 'Sample', preview: 'about:blank', containers: [{ key: 'tag', value: 'x', label: 'x' }] },
  ]
  const filter = overrides.filter ?? { source: 'test-source', nsfw: 0, sort: 'Newest', limit: 20, page: 1, next: null }
  const services = overrides.services ?? []

  return mount(Index, {
    props: { files, filter, services },
    global: {
      stubs: {
        Masonry: MasonryStub,
        // Use REAL GridItem and ActionMenu to exercise panel actions
        // Keep other components shallow
        AppLayout: AppLayoutStub,
        ContentLayout: ContentLayoutStub,
        ScrollableLayout: ScrollableLayoutStub,
        SectionHeader: true,
        Button: true,
        Label: true,
        FileReactions: true,
        LoaderOverlay: true,
        Dialog: true,
        DialogContent: true,
        DialogTitle: true,
        DialogDescription: true,
      },
    },
    attachTo: document.body,
  })
}

async function clickByText(root: Element | Document, text: string) {
  const buttons = Array.from(root.querySelectorAll('button')) as HTMLButtonElement[]
  const target = buttons.find((b) => b.textContent?.trim().toLowerCase() === text.toLowerCase())
  if (!target) throw new Error(`button with text '${text}' not found`)
  target.click()
  await nextTick()
}

describe('Browse list mode panel actions', () => {
  it('opens the action panel and triggers a react action', async () => {
    const wrapper = mountBrowse()
    await nextTick(); await nextTick()

    // Open the first item panel via the More options button
    const more = wrapper.find('[aria-label="More options"]')
    expect(more.exists()).toBe(true)
    await more.trigger('click')
    await nextTick()

    // The real ActionMenu is teleported to body. Click into react -> like
    await clickByText(document.body, 'react')
    await clickByText(document.body, 'like')

    // Verify network side-effect happened (optimistic UI + axios request)
    expect((axios as any).post).toHaveBeenCalled()
  })
})
