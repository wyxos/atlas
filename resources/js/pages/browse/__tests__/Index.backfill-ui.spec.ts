import { describe, it, expect, vi } from 'vitest'
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

// Masonry stub that supports v-model:items and emits backfill/retry events
const MasonryStub = {
  name: 'Masonry',
  inheritAttrs: true,
  props: {
    items: { type: Array, default: () => [] },
    getNextPage: { type: Function, default: null },
    pageSize: { type: Number, default: 20 },
    backfillEnabled: { type: Boolean, default: true },
  },
  emits: ['update:items', 'backfill:start', 'backfill:tick', 'backfill:stop', 'retry:tick', 'retry:stop'],
  data() { return { internalItems: this.items } },
  watch: { items(v: any[]) { (this as any).internalItems = v } },
  methods: {
    init(files: any[]) { this.$emit('update:items', Array.isArray(files) ? files : []) },
    async loadNext() { return [] },
    reset() {},
  },
  template: `
    <div data-test="masonry-stub" v-bind="$attrs">
      <slot name="item" v-for="(it, i) in internalItems" :item="it" :index="i" />
    </div>
  `,
}

// Minimal stubs
const GridItemStub = { name: 'GridItem', props: ['item','fileForReactions'], template: `<div data-test="grid-item" />` }
const AppLayoutStub = { name: 'AppLayout', inheritAttrs: false, props: { breadcrumbs: { type: Array, default: () => [] } }, template: '<div data-test="app-layout"><slot /></div>' }
const ContentLayoutStub = { name: 'ContentLayout', inheritAttrs: false, template: '<div data-test="content-layout"><slot /></div>' }
const ScrollableLayoutStub = { name: 'ScrollableLayout', inheritAttrs: false, template: '<div data-test="scrollable-layout"><slot /></div>' }
const FullSizeViewerStub = { name: 'FullSizeViewer', inheritAttrs: false, props: { open: Boolean, item: Object, items: Array, scroller: Object }, template: '<div data-test="full-viewer" />' }

function mountBrowse(files?: any[]) {
  const initialFiles = files ?? [
    { id: 1, type: 'image', title: 'A', preview: 'about:blank', containers: [{ key: 'tag', value: 'x', label: 'x' }] },
  ]
  const filter = { source: 'test-source', nsfw: 0, sort: 'Newest', limit: 20, page: 1, next: null }

  return mount(Index, {
    props: { files: initialFiles, filter, services: [] },
    global: {
      stubs: {
        Masonry: MasonryStub,
        GridItem: GridItemStub,
        FullSizeViewer: FullSizeViewerStub,
        AppLayout: AppLayoutStub,
        ContentLayout: ContentLayoutStub,
        ScrollableLayout: ScrollableLayoutStub,
        SectionHeader: true,
        Button: true,
        Label: true,
        FileReactions: true,
      },
    },
    attachTo: document.body,
  })
}

describe('Browse/Index.vue Masonry backfill UI', () => {
  it('renders filling, waiting and retry states based on Masonry events and clears to ready', async () => {
    const wrapper = mountBrowse()
    await nextTick(); await nextTick()

    const masonry = wrapper.getComponent(MasonryStub as any)

    // Start backfill
    masonry.vm.$emit('backfill:start', { target: 10, fetched: 3, calls: 1 })
    await nextTick()
    expect(wrapper.text()).toContain('filling')
    expect(wrapper.text()).toContain('3 / 10 (1 calls)')

    // Tick: waiting countdown
    masonry.vm.$emit('backfill:tick', { fetched: 3, target: 10, calls: 2, remainingMs: 1500, totalMs: 2000 })
    await nextTick()
    expect(wrapper.text()).toContain('next in 1.5s')

    // Backfill stop clears filling/waiting and status should be ready
    masonry.vm.$emit('backfill:stop', { fetched: 10, calls: 2 })
    await nextTick()
    expect(wrapper.text()).not.toContain('filling')
    expect(wrapper.text()).not.toContain('next in')
    expect(wrapper.text()).toContain('status')
    expect(wrapper.text()).toContain('ready')

    wrapper.unmount()
  })
})