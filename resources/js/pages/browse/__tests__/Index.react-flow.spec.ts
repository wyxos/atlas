import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import Index from '@/pages/browse/Index.vue'
import { undoManager } from '@/lib/undo'

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
    { id: 3, type: 'image', title: 'C', preview: 'about:blank', containers: [{ key: 'tag', value: 'y', label: 'y' }] },
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

describe('Browse/Index.vue react flows', () => {
  const rafQueue: FrameRequestCallback[] = []
  beforeEach(() => {
    axiosMocks.post.mockClear()
    rafQueue.length = 0
    vi.useFakeTimers()
    vi.spyOn(window, 'requestAnimationFrame').mockImplementation((cb: FrameRequestCallback) => { rafQueue.push(cb); return rafQueue.length as unknown as number })
  })
  afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks() })

  it('like flow: fires network early, delays removal to raf, pushes undo and revert schedules refresh', async () => {
    const wrapper = mountBrowse()
    await nextTick(); await nextTick()

    const masonry = wrapper.getComponent(MasonryStub)
    const spyRemoveMany = vi.spyOn(masonry.vm as any, 'removeMany')
    const spyRefresh = vi.spyOn(masonry.vm as any, 'refreshLayout')

    const first = wrapper.findAll('[data-test="grid-item"]')[0]
    await first.get('[data-test="like"]').trigger('click')

    // Early network fired before any removal
    expect(axiosMocks.post).toHaveBeenCalled()
    expect(spyRemoveMany).not.toHaveBeenCalled()

    // Drain RAF until removal runs
    let safety1 = 10
    while (rafQueue.length && spyRemoveMany.mock.calls.length === 0 && safety1-- > 0) {
      const cb = rafQueue.shift()!
      cb(0 as any)
      await nextTick()
    }

    expect(spyRemoveMany).toHaveBeenCalledTimes(1)

    // Capture undo action and invoke revertUI
    const pushed: any[] = []
    vi.spyOn(undoManager, 'push').mockImplementation((a: any) => { pushed.push(a) })

    // Trigger another like to ensure undo captured
    // Re-query items after first removal to avoid acting on a detached wrapper
    const itemsAfter = wrapper.findAll('[data-test="grid-item"]')
    const nextTarget = itemsAfter[0] || wrapper.find('[data-test="grid-item"]')
    await nextTarget.get('[data-test="like"]').trigger('click')
    // Drain RAF for second like as well
    let safety2 = 10
    while (rafQueue.length && safety2-- > 0) {
      const cb2 = rafQueue.shift()!
      cb2(0 as any)
      await nextTick()
    }

    expect(pushed.length).toBeGreaterThan(0)
    const action = pushed[pushed.length - 1]
    expect(String(action?.label || '')).toMatch(/Like 1 item/)

    // Revert should reinsert and schedule masonry refresh (microtask + raf)
    action.revertUI()
    // Wait for nextTick scheduled within revertUI, then flush microtasks and drain RAFs
    await nextTick()
    await Promise.resolve()
    let safetyR = 10
    while (rafQueue.length && safetyR-- > 0) { rafQueue.shift()!(0 as any); await nextTick() }
    expect(spyRefresh).toHaveBeenCalled()

    wrapper.unmount()
  })

  it('dislike flow: early blacklist call, removal via raf, dialog advances to next item', async () => {
    const wrapper = mountBrowse()
    await nextTick(); await nextTick()

    // Open dialog on first item
    const first = wrapper.findAll('[data-test="grid-item"]')[0]
    await first.get('[data-test="open"]').trigger('click')
    await nextTick()

    // Dislike the first item
    await first.get('[data-test="dislike"]').trigger('click')

    // Early network fired
    expect(axiosMocks.post).toHaveBeenCalled()

    // Drain RAF until removal applied
    let safety3 = 10
    const masonry = wrapper.getComponent(MasonryStub)
    const spyRemoveMany = vi.spyOn(masonry.vm as any, 'removeMany')
    while (rafQueue.length && spyRemoveMany.mock.calls.length === 0 && safety3-- > 0) {
      const cb = rafQueue.shift()!
      cb(0 as any)
      await nextTick()
    }

    await nextTick()

    // FullSizeViewer v-model should now point to next item (id 2)
    await nextTick(); await nextTick()
    const viewer = wrapper.getComponent(FullSizeViewerStub)
    expect(Number(viewer.attributes()['data-item-id'] || 0)).toBe(2)

    wrapper.unmount()
  })

  it('pauses backfill during removal and resumes after 200ms', async () => {
    const wrapper = mountBrowse()
    await nextTick(); await nextTick()

    const masonry = wrapper.getComponent(MasonryStub)

    // Initially true
    expect((masonry.props() as any).backfillEnabled).toBe(true)

    // Trigger like
    const first = wrapper.findAll('[data-test="grid-item"]')[0]
    await first.get('[data-test="like"]').trigger('click')

    // Drain first RAF (yield); after this, prop should toggle false
    // Drain RAFs until we observe backfill=false or run out
    let toggled = false
    let guard = 10
    while (guard-- > 0 && rafQueue.length) {
      const cb = rafQueue.shift()!
      cb(0 as any)
      await nextTick()
      if (wrapper.getComponent(MasonryStub).attributes()['data-backfill'] === 'false') { toggled = true; break }
    }
    expect(toggled).toBe(true)

    // Now process remaining RAFs
    while (rafQueue.length) { rafQueue.shift()!(0 as any); await nextTick() }

    // Advance timers to pass the 200ms resume window
    vi.advanceTimersByTime(200)
    await nextTick()

    expect(wrapper.getComponent(MasonryStub).attributes()['data-backfill']).toBe('true')

    wrapper.unmount()
  })
})
