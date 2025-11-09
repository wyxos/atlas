import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'

const listeners: Record<string, Array<(payload?: any) => void>> = {}

vi.mock('@/lib/bus', () => ({
  bus: {
    on(event: string, callback: (payload?: any) => void) {
      listeners[event] = listeners[event] || []
      listeners[event].push(callback)
    },
    emit(event: string, payload?: any) {
      (listeners[event] || []).forEach((cb) => cb(payload))
    },
  },
}))

vi.mock('@/components/audio/FileReactions.vue', () => ({
  default: {
    name: 'FileReactionsStub',
    template: '<div />',
  },
}))

vi.mock('@/components/ui/LoaderOverlay.vue', () => ({
  default: {
    name: 'LoaderOverlayStub',
    template: '<div data-test="loader-overlay" />',
  },
}))

vi.mock('@/components/browse/ActionMenu.vue', () => ({
  default: {
    name: 'ActionMenuStub',
    props: ['options'],
    template: '<div />',
  },
}))

vi.mock('@/components/ui/dialog', () => {
  const component = (name: string) => ({
    name,
    inheritAttrs: false,
    props: ['open'],
    template: '<div><slot /></div>',
  })

  return {
    Dialog: component('DialogStub'),
    DialogContent: component('DialogContentStub'),
    DialogDescription: component('DialogDescriptionStub'),
    DialogTitle: component('DialogTitleStub'),
  }
})

vi.mock('lucide-vue-next', () => {
  const icon = (name: string) => ({
    name,
    props: ['size'],
    template: '<span />',
  })

  return {
    ChevronsLeft: icon('ChevronsLeftStub'),
    ChevronsRight: icon('ChevronsRightStub'),
    Eye: icon('EyeStub'),
    X: icon('XStub'),
    AlertTriangle: icon('AlertTriangleStub'),
    ImageOff: icon('ImageOffStub'),
    SquareArrowOutUpRight: icon('SquareArrowOutUpRightStub'),
  }
})

vi.mock('@/pages/browse/highlight', () => ({
  ringForSlot: () => 'ring-test',
  badgeClassForSlot: () => 'badge-test',
}))

vi.mock('@/utils/moderationHighlight', () => ({
  highlightPromptHtml: () => '',
}))

vi.mock('@/pages/browse/useBatchReact', () => ({
  createBatchReact: () => vi.fn(),
}))

vi.mock('@/actions/App/Http/Controllers/BrowseController', () => ({
  reactDownload: vi.fn(() => ({ url: '/react' })),
  dislikeBlacklist: vi.fn(() => ({ url: '/dislike' })),
  react: vi.fn(() => ({ url: '/react' })),
  batchReact: vi.fn(() => ({ url: '/batch-react' })),
  batchUnblacklist: vi.fn(() => ({ url: '/batch-unblacklist' })),
  fileSeen: vi.fn(() => ({ url: '/file-seen' })),
  reportMissing: vi.fn(() => ({ url: '/report-missing' })),
  clearNotFound: vi.fn(() => ({ url: '/clear-not-found' })),
}))

vi.mock('axios', () => ({
  default: {
    post: vi.fn().mockResolvedValue({}),
  },
}))

const FullSizeViewer = (await import('@/pages/browse/FullSizeViewer.vue')).default

describe('FullSizeViewer navigation', () => {
  beforeEach(() => {
    Object.keys(listeners).forEach((key) => {
      listeners[key] = []
    })
  })

  it('clears error state before navigating to next item', async () => {
    const items = [
      {
        id: 1,
        original: 'https://example.test/image-1.jpg',
        preview: 'https://example.test/thumb-1.jpg',
        type: 'image',
        not_found: false,
        listing_metadata: {},
        metadata: {},
        containers: [],
      },
      {
        id: 2,
        original: 'https://example.test/image-2.jpg',
        preview: 'https://example.test/thumb-2.jpg',
        type: 'image',
        not_found: false,
        listing_metadata: {},
        metadata: {},
        containers: [],
      },
    ]

    const wrapper = mount(FullSizeViewer, {
      props: {
        open: true,
        item: items[0],
        items,
        scroller: { loadNext: vi.fn().mockResolvedValue(undefined) },
      },
      global: {
        stubs: {
          transition: false,
          teleport: true,
        },
      },
    })

    wrapper.vm.setFullErrorState('unavailable', 503, 'Failed', false)
    await nextTick()

    expect(wrapper.vm.fullErrorKind).toBe('unavailable')

    const navPromise = wrapper.vm.navigate(1)

    expect(wrapper.vm.fullErrorKind).toBe('none')
    expect(wrapper.find('[data-testid="fullsize-error-overlay"]').exists()).toBe(false)

    await navPromise

    const updateEvents = wrapper.emitted('update:item') || []
    const updatedItem = updateEvents.at(-1)?.[0]
    if (updatedItem) {
      await wrapper.setProps({ item: updatedItem })
      await nextTick()
    }

    expect((wrapper.vm.dialogItem as any)?.id).toBe(2)
  })
})

