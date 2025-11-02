import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick, inject, computed, ref, watch } from 'vue'
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

// Masonry stub with scroller API
const MasonryStub = {
  name: 'Masonry',
  props: { items: { type: Array, default: () => [] } },
  emits: ['update:items'],
  methods: {
    init(files: any[]) { this.$emit('update:items', Array.isArray(files) ? files : []) },
    async loadNext() { return [] },
  },
  // Do NOT bind $attrs to avoid object-valued props becoming DOM attributes
  template: `<div><slot name=\"item\" v-for=\"(it,i) in items\" :item=\"it\" :index=\"i\" /></div>`,
}



describe('Browse/Index.vue container count recompute coalescing', () => {
  it('coalesces recompute to single visual update and produces correct counts', async () => {
    // Probe component reads injected counts and tracks number of changes (no production code changes)
    const Probe = {
      name: 'Probe',
      template: '<div data-test="probe" :data-count="count" :data-changes="changes" />',
      setup() {
        const map = inject('browse-container-counts') as Map<string, Map<string | number, number>>
        const count = computed(() => (map.get('tag')?.get('x') ?? 0))
        const changes = ref(0)
        watch(count, () => { changes.value++ })
        return { count, changes }
      },
    } as any

    // Mount with a GridItem stub that includes the Probe
    const GridItemWithProbe = { name: 'GridItem', props: ['item'], components: { Probe }, template: '<div><Probe /></div>' }

    const filter = { source: 'test-source', nsfw: 0, sort: 'Newest', limit: 20, page: 1, next: null }
    const AppLayoutStub = { name: 'AppLayout', inheritAttrs: false, props: { breadcrumbs: { type: Array, default: () => [] } }, template: '<div data-test="app-layout"><slot /></div>' }
    const ContentLayoutStub = { name: 'ContentLayout', inheritAttrs: false, template: '<div data-test="content-layout"><slot /></div>' }
    const ScrollableLayoutStub = { name: 'ScrollableLayout', inheritAttrs: false, template: '<div data-test="scrollable-layout"><slot /></div>' }
    const FullSizeViewerStub = { name: 'FullSizeViewer', inheritAttrs: false, props: { open: Boolean, item: Object, items: Array, scroller: Object }, template: '<div data-test="full-viewer" />' }

    const wrapper = mount(Index as any, {
      props: { files: [ { id: 1, containers: [{ key: 'tag', value: 'x' }] } ], filter, services: [] },
      global: { stubs: { Masonry: MasonryStub, GridItem: GridItemWithProbe, FullSizeViewer: FullSizeViewerStub, AppLayout: AppLayoutStub, ContentLayout: ContentLayoutStub, ScrollableLayout: ScrollableLayoutStub } },
      attachTo: document.body,
    })

    await nextTick(); await nextTick()

    const masonry = wrapper.getComponent(MasonryStub)

    // Rapid updates (length: 2, 1, 3) within same tick
    masonry.vm.$emit('update:items', [ { id: 1, containers: [{ key: 'tag', value: 'x' }] }, { id: 2, containers: [{ key: 'tag', value: 'x' }] } ])
    masonry.vm.$emit('update:items', [ { id: 1, containers: [{ key: 'tag', value: 'x' }] } ])
    masonry.vm.$emit('update:items', [ { id: 1, containers: [{ key: 'tag', value: 'x' }] }, { id: 2, containers: [{ key: 'tag', value: 'x' }] }, { id: 3, containers: [{ key: 'tag', value: 'y' }] } ])

    // Allow watcher and coalesced recompute (raf) to complete
    await nextTick()
    await new Promise<void>((resolve) => {
      const raf = (window as any).requestAnimationFrame || ((cb: any) => setTimeout(cb, 0))
      raf(() => resolve())
    })
    await nextTick()

    const probe = wrapper.find('[data-test="probe"]')
    expect(probe.exists()).toBe(true)
    // Count for tag=x should finally be 2 (items id 1 and 2)
    expect(Number(probe.attributes()['data-count'] || 0)).toBe(2)
    // Exactly one visual change after rapid updates
    expect(Number(probe.attributes()['data-changes'] || 0)).toBe(1)

    wrapper.unmount()
  })
})
