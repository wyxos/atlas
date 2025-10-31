import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import Index from '@/pages/browse/Index.vue'

// Mock Inertia's useForm and Head
vi.mock('@inertiajs/vue3', () => {
  const state: any = { source: 'test-source', nsfw: 0, sort: 'Newest', limit: 20, page: 1, next: null }
  return {
    Head: { name: 'Head', template: '<template><slot /></template>' },
    useForm: (initial: any) => {
      Object.assign(state, initial || {})
      return { ...state, data: () => ({ ...state }), defaults: (v: any) => Object.assign(state, v || {}), reset: () => void 0 } as any
    },
    usePage: () => ({ props: { auth: { user: { is_admin: false } } } }),
  }
})

// Mock axios to avoid network
const axiosMocks = vi.hoisted(() => ({ post: vi.fn(() => Promise.resolve({ data: {} })) }))
vi.mock('axios', () => ({ default: { post: axiosMocks.post } }))

// Mock BrowseController URLs used by Index.vue
vi.mock('@/actions/App/Http/Controllers/BrowseController', () => ({
  reactDownload: ({ file }: any) => ({ url: `/browse/react-download/${file}` }),
  react: ({ file }: any) => ({ url: `/browse/react/${file}` }),
  dislikeBlacklist: ({ file }: any) => ({ url: `/browse/dislike-blacklist/${file}` }),
  batchReact: () => ({ url: '/browse/batch-react' }),
  batchUnblacklist: () => ({ url: '/browse/batch-unblacklist' }),
  unblacklist: ({ file }: any) => ({ url: `/browse/unblacklist/${file}` }),
}), { virtual: true })

// Masonry stub with scroller API and backfillEnabled prop
const MasonryStub = {
  name: 'Masonry',
  inheritAttrs: true,
  props: {
    items: { type: Array, default: () => [] },
    getNextPage: { type: Function, default: null },
    pageSize: { type: Number, default: 20 },
    backfillEnabled: { type: Boolean, default: true },
  },
  emits: ['update:items', 'backfill:start', 'backfill:tick', 'backfill:stop', 'retry:start', 'retry:tick', 'retry:stop'],
  data() {
    return { internalItems: this.items }
  },
  watch: {
    items(v: any[]) { (this as any).internalItems = v },
  },
  methods: {
    init(files: any[]) { this.$emit('update:items', Array.isArray(files) ? files : []) },
    async loadNext() { return [] },
    reset() {},
    remove() {},
    async removeMany(batch: any[]) {
      const ids = new Set((Array.isArray(batch) ? batch : []).map((x: any) => x?.id))
      const next = (this as any).internalItems.filter((it: any) => !ids.has(it?.id))
      ;(this as any).internalItems = next
      this.$emit('update:items', next)
    },
    refreshLayout() {},
    layout() {},
    scrollToTop() {},
  },
  template: `
    <div data-test="masonry-stub" :data-backfill="String(backfillEnabled)" v-bind="$attrs">
      <slot name="item" v-for="(it, i) in internalItems" :item="it" :index="i" />
    </div>
  `,
}

// GridItem stub with like/dislike/open triggers
const GridItemStub = {
  name: 'GridItem',
  props: { item: { type: Object, required: true }, fileForReactions: { type: Object, required: false } },
  emits: ['open', 'like', 'dislike', 'favorite', 'laughed-at'],
  template: `
    <div data-test="grid-item">
      <button data-test="open" @click="$emit('open', item)">open</button>
      <button data-test="like" @click="$emit('like', item, $event)">like</button>
      <button data-test="dislike" @click="$emit('dislike', item, $event)">dislike</button>
    </div>
  `,
}

// FullSizeViewer stub to capture v-model:item
const FullSizeViewerStub = {
  name: 'FullSizeViewer',
  props: { open: { type: Boolean, default: false }, item: { type: Object, default: null }, items: { type: Array, default: () => [] }, scroller: { type: Object, default: null } },
  emits: ['update:open', 'update:item', 'favorite', 'like', 'dislike', 'laughed-at'],
  template: `<div data-test="full-viewer" :data-item-id="item?.id || ''"></div>`,
}

function mountBrowse(files?: any[]) {
  const initialFiles = files ?? [
    { id: 1, type: 'image', title: 'A', preview: 'about:blank', containers: [{ key: 'tag', value: 'x', label: 'x' }] },
    { id: 2, type: 'image', title: 'B', preview: 'about:blank', containers: [{ key: 'tag', value: 'x', label: 'x' }] },
  ]
  const filter = { source: 'test-source', nsfw: 0, sort: 'Newest', limit: 20, page: 1, next: null }
  const services: any[] = []

  const AppLayoutStub = { name: 'AppLayout', template: '<div data-test="app-layout"><slot /></div>' }
  const ContentLayoutStub = { name: 'ContentLayout', template: '<div data-test="content-layout"><slot /></div>' }
  const ScrollableLayoutStub = { name: 'ScrollableLayout', template: '<div data-test="scrollable-layout"><slot /></div>' }

  const wrapper = mount(Index, {
    props: { files: initialFiles, filter, services },
    global: {
      stubs: {
        Masonry: MasonryStub,
        GridItem: GridItemStub,
        FullSizeViewer: FullSizeViewerStub,
        Dialog: { template: '<div><slot /></div>' },
        DialogScrollContent: { template: '<div><slot /></div>' },
        DialogTitle: { template: '<div><slot /></div>' },
        DialogDescription: { template: '<div><slot /></div>' },
        AppLayout: AppLayoutStub,
        ContentLayout: ContentLayoutStub,
        ScrollableLayout: ScrollableLayoutStub,
        SectionHeader: true,
        Button: true,
        Label: true,
        ActionMenu: true,
        FileReactions: true,
      },
    },
    attachTo: document.body,
  })
  return wrapper
}

describe('Browse/Index.vue removal batching', () => {
  const rafQueue: FrameRequestCallback[] = []
  beforeEach(() => {
    axiosMocks.post.mockClear()
    rafQueue.length = 0
    vi.spyOn(window, 'requestAnimationFrame').mockImplementation((cb: FrameRequestCallback) => { rafQueue.push(cb); return rafQueue.length as unknown as number })
  })
  afterEach(() => { vi.restoreAllMocks() })

  it('coalesces multiple removals per frame via scheduleRemoveItem', async () => {
    const wrapper = mountBrowse()
    await nextTick(); await nextTick()

    const masonry = wrapper.getComponent(MasonryStub)
    const spyRemoveMany = vi.spyOn(masonry.vm as any, 'removeMany')

    const items = wrapper.findAll('[data-test="grid-item"]')
    expect(items.length).toBeGreaterThan(1)

    // Trigger two likes quickly (starts two flows)
    await items[0].get('[data-test="like"]').trigger('click')
    await items[1].get('[data-test="like"]').trigger('click')

    // Early network fired before removal
    expect(axiosMocks.post).toHaveBeenCalled()
    expect(spyRemoveMany).not.toHaveBeenCalled()

    // Drain RAF until removal runs
    let safety = 10
    while (rafQueue.length && spyRemoveMany.mock.calls.length === 0 && safety-- > 0) {
      const cb = rafQueue.shift()!
      cb(0 as any)
      await nextTick()
    }

    expect(spyRemoveMany).toHaveBeenCalledTimes(1)
    const arg = spyRemoveMany.mock.calls[0][0]
    const ids = Array.isArray(arg) ? arg.map((x: any) => x?.id).sort() : []
    expect(ids).toEqual([1, 2])

    wrapper.unmount()
  })
})
