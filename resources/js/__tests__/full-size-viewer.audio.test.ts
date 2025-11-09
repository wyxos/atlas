import { describe, it, expect, beforeAll, afterAll, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h, nextTick } from 'vue'

const stubComponent = (name: string) =>
  defineComponent({
    name,
    setup(_, { slots }) {
      return () => h('div', { class: name }, slots.default ? slots.default() : [])
    },
  })

const originalPlay = HTMLMediaElement.prototype.play
const playSpy = vi.fn().mockResolvedValue(undefined)

beforeAll(() => {
  Object.defineProperty(HTMLMediaElement.prototype, 'play', {
    configurable: true,
    writable: true,
    value: playSpy,
  })
})

afterAll(() => {
  Object.defineProperty(HTMLMediaElement.prototype, 'play', {
    configurable: true,
    writable: true,
    value: originalPlay,
  })
})

beforeEach(() => {
  playSpy.mockClear()
})

vi.mock('axios', () => ({
  default: {
    post: vi.fn().mockResolvedValue({}),
    get: vi.fn().mockResolvedValue({}),
  },
}))

vi.mock('@/actions/App/Http/Controllers/BrowseController', () => ({
  fileSeen: vi.fn(() => ({ url: '/file-seen' })),
  reportMissing: vi.fn(() => ({ url: '/report-missing' })),
  clearNotFound: vi.fn(() => ({ url: '/clear-not-found' })),
  createBatchReact: vi.fn(() => ({ url: '/batch-react' })),
}))

vi.mock('@/components/ui/button', () => ({ Button: stubComponent('UIButtonStub') }))

vi.mock('@/components/ui/dialog', () => {
  const Dialog = defineComponent({
    name: 'DialogStub',
    props: { open: { type: Boolean, default: false } },
    emits: ['update:open'],
    setup(props, { slots, emit }) {
      const updateOpen = (value: boolean) => emit('update:open', value)
      return () =>
        h(
          'div',
          {
            class: 'DialogStub',
            'data-open': props.open ? 'true' : 'false',
            onClick: () => updateOpen(!props.open),
          },
          slots.default ? slots.default() : [],
        )
    },
  })

  return {
    Dialog,
    DialogContent: stubComponent('DialogContentStub'),
    DialogDescription: stubComponent('DialogDescriptionStub'),
    DialogTitle: stubComponent('DialogTitleStub'),
  }
})

vi.mock('@/components/audio/FileReactions.vue', () => ({ default: stubComponent('FileReactionsStub') }))
vi.mock('@/components/ui/LoaderOverlay.vue', () => ({ default: stubComponent('LoaderOverlayStub') }))
vi.mock('@/components/browse/ActionMenu.vue', () => ({
  default: defineComponent({
    name: 'ActionMenuStub',
    setup(_, { slots }) {
      return () => h('div', { class: 'ActionMenuStub' }, slots.default ? slots.default() : [])
    },
  }),
}))

vi.mock('@/pages/browse/useBatchReact', () => ({
  createBatchReact: () => vi.fn(),
}))

vi.mock('@/pages/browse/highlight', () => ({
  ringForSlot: vi.fn(() => 'ring-0'),
  badgeClassForSlot: vi.fn(() => 'badge-0'),
}))

vi.mock('@/utils/moderationHighlight', () => ({
  highlightPromptHtml: vi.fn(() => ''),
}))

vi.mock('lucide-vue-next', () => ({
  ChevronsLeft: stubComponent('IconStub'),
  ChevronsRight: stubComponent('IconStub'),
  Eye: stubComponent('IconStub'),
  X: stubComponent('IconStub'),
  AlertTriangle: stubComponent('IconStub'),
  ImageOff: stubComponent('IconStub'),
  SquareArrowOutUpRight: stubComponent('IconStub'),
}))

const FullSizeViewer = (await import('@/pages/browse/FullSizeViewer.vue')).default

describe('FullSizeViewer audio video behaviour', () => {
  it('leaves full-size video audio enabled', async () => {
    const item = {
      id: 42,
      type: 'video',
      original: 'https://example.com/video.mp4',
      preview: 'https://example.com/preview.mp4',
      containers: [],
      metadata: {},
    }

    const wrapper = mount(FullSizeViewer, {
      props: {
        open: true,
        item,
        items: [item],
        scroller: {},
      },
      global: {
        stubs: { transition: false, teleport: true },
      },
    })

    await nextTick()

    const video = wrapper.find('video')
    expect(video.exists()).toBe(true)
    expect(video.attributes('controls')).toBeDefined()

    video.element.dispatchEvent(new Event('canplay'))
    await nextTick()

    expect(video.element.muted).toBe(false)
    expect(video.attributes('muted')).toBeUndefined()
    expect(playSpy).toHaveBeenCalled()
  })
})


