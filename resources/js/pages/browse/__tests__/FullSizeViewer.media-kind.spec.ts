import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h, reactive } from 'vue'

vi.mock('@/actions/App/Http/Controllers/BrowseController', () => ({
  reportMissing: vi.fn(() => ({ url: '/report-missing' })),
  previewSeen: vi.fn(() => ({ url: '/preview-seen' })),
  reactDownload: vi.fn(() => ({ url: '/react' })),
}))

vi.mock('axios', () => ({
  default: {
    post: vi.fn(() => Promise.resolve({ data: {} })),
  },
}))

const stub = (name: string) =>
  defineComponent({
    name,
    inheritAttrs: false,
    setup(_props, { slots }) {
      return () => h('div', { class: name }, slots.default ? slots.default() : [])
    },
  })

vi.mock('lucide-vue-next', () => ({
  ChevronsLeft: stub('ChevronsLeft'),
  ChevronsRight: stub('ChevronsRight'),
  Eye: stub('EyeIcon'),
  X: stub('XIcon'),
  AlertTriangle: stub('AlertTriangleIcon'),
  ImageOff: stub('ImageOffIcon'),
}))

vi.mock('@/components/audio/FileReactions.vue', () => ({
  default: defineComponent({ name: 'FileReactionsStub', template: '<div />' }),
}))

vi.mock('@/components/ui/LoaderOverlay.vue', () => ({
  default: defineComponent({ name: 'LoaderOverlayStub', template: '<div data-test="loader" />' }),
}))

vi.mock('@/components/browse/ActionMenu.vue', () => ({
  default: defineComponent({
    name: 'ActionMenuStub',
    emits: ['close', 'path-change'],
    setup(_props, { slots }) {
      return () => h('div', { 'data-test': 'action-menu' }, slots.default ? slots.default() : [])
    },
  }),
}))

vi.mock('@/components/ui/button', () => ({
  Button: defineComponent({
    name: 'UIButtonStub',
    inheritAttrs: false,
    emits: ['click'],
    setup(_props, { attrs, slots, emit }) {
      return () =>
        h(
          'button',
          {
            type: 'button',
            ...attrs,
            onClick: (event: MouseEvent) => emit('click', event),
          },
          slots.default ? slots.default() : [],
        )
    },
  }),
}))

vi.mock('@/components/ui/dialog', () => ({
  Dialog: defineComponent({
    name: 'DialogStub',
    props: {
      open: { type: Boolean, default: false },
    },
    emits: ['update:open'],
    setup(props, { slots }) {
      return () => h('div', { 'data-test': 'dialog', 'data-open': props.open }, slots.default ? slots.default() : [])
    },
  }),
  DialogContent: stub('DialogContentStub'),
  DialogDescription: stub('DialogDescriptionStub'),
  DialogTitle: stub('DialogTitleStub'),
}))

vi.mock('@/pages/browse/highlight', () => ({
  ringForSlot: () => 'ring-test',
  badgeClassForSlot: () => 'badge-test',
}))

vi.mock('@/pages/browse/useBatchReact', () => ({
  createBatchReact: () => async () => Promise.resolve(),
}))

const FullSizeViewer = (await import('@/pages/browse/FullSizeViewer.vue')).default

function mountViewer(overrides: { item?: Record<string, any> } = {}) {
  const baseItem = reactive({
    id: 1,
    type: 'image',
    preview: 'https://cdn.example.com/file.jpg',
    original: 'https://cdn.example.com/file.jpg',
    containers: [],
    not_found: false,
    ...overrides.item,
  })

  const scrollerStub = {
    removeAll: async () => undefined,
    remove: async () => undefined,
    loadNext: async () => undefined,
    loadPage: async () => undefined,
    refreshLayout: () => undefined,
    refreshCurrentPage: async () => undefined,
    cancelLoad: () => undefined,
    reset: () => undefined,
  }

  return mount(FullSizeViewer, {
    props: {
      open: true,
      item: baseItem,
      items: [baseItem],
      scroller: scrollerStub,
    },
    attachTo: document.body,
    global: {
      stubs: {
        teleport: true,
        transition: false,
      },
    },
  })
}

describe('FullSizeViewer media kind resolution', () => {
  beforeEach(() => {
    vi.spyOn(window, 'open').mockImplementation(() => null as any)
  })

  afterEach(() => {
    document.body.innerHTML = ''
    vi.restoreAllMocks()
  })

  it('renders an <img> when metadata says video but URL looks like image', async () => {
    const wrapper = mountViewer({
      item: {
        type: 'video',
        preview: 'https://cdn.example.com/media/file.jpeg',
        original: 'https://cdn.example.com/media/file.jpeg',
      },
    })

    await wrapper.vm.$nextTick()
    await wrapper.vm.$nextTick()

    expect(wrapper.find('video').exists()).toBe(false)
    expect(wrapper.find('img').exists()).toBe(true)

    wrapper.unmount()
  })

  it('renders a <video> when metadata says image but URL resolves to video', async () => {
    const wrapper = mountViewer({
      item: {
        type: 'image',
        preview: 'https://cdn.example.com/media/file.mp4',
        original: 'https://cdn.example.com/media/file.mp4',
      },
    })

    await wrapper.vm.$nextTick()
    await wrapper.vm.$nextTick()

    expect(wrapper.find('video').exists()).toBe(true)
    expect(wrapper.find('img').exists()).toBe(false)

    wrapper.unmount()
  })
})

