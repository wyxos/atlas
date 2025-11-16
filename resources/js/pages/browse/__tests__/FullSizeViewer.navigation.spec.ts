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
    props: ['file', 'size'],
    emits: ['favorite', 'like', 'dislike', 'laughed-at'],
    template: '<div data-test="file-reactions" :data-file-id="file?.id" />',
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

  it('renders 404 error immediately when file has not_found flag', async () => {
    const item = {
      id: 1,
      original: 'https://example.test/image-1.jpg',
      preview: 'https://example.test/thumb-1.jpg',
      type: 'image',
      not_found: true,
      listing_metadata: {},
      metadata: {},
      containers: [],
    }

    const wrapper = mount(FullSizeViewer, {
      props: {
        open: true,
        item: null, // Start with null, then set item to trigger watcher
        items: [item],
        scroller: { loadNext: vi.fn().mockResolvedValue(undefined) },
      },
      global: {
        stubs: {
          transition: false,
          teleport: true,
        },
      },
    })

    await nextTick()

    // Now set the item with not_found flag to trigger watcher
    await wrapper.setProps({ item })
    await nextTick()
    await nextTick() // Wait for watcher to process

    // The component should detect not_found flag and set error state
    expect(wrapper.vm.fullErrorKind).toBe('not-found')
    expect(wrapper.vm.fullIsNotFoundError).toBe(true)
    
    // Error overlay shows when fullLoaded is true AND there's an error
    // For not_found errors, we need to set fullLoaded to true to see the overlay
    wrapper.vm.fullLoaded = true
    await nextTick()
    
    expect(wrapper.find('[data-testid="fullsize-error-overlay"]').exists()).toBe(true)
  })

  it('renders loading animation when file is not 404', async () => {
    const item = {
      id: 1,
      original: 'https://example.test/image-1.jpg',
      preview: 'https://example.test/thumb-1.jpg',
      type: 'image',
      not_found: false,
      listing_metadata: {},
      metadata: {},
      containers: [],
    }

    const wrapper = mount(FullSizeViewer, {
      props: {
        open: true,
        item,
        items: [item],
        scroller: { loadNext: vi.fn().mockResolvedValue(undefined) },
      },
      global: {
        stubs: {
          transition: false,
          teleport: true,
        },
      },
    })

    await nextTick()

    // Initially, media is not loaded, so loader should be visible
    expect(wrapper.vm.fullLoaded).toBe(false)
    expect(wrapper.find('[data-test="loader-overlay"]').exists()).toBe(true)
  })

  it('hides loading animation when image loads', async () => {
    const item = {
      id: 1,
      original: 'https://example.test/image-1.jpg',
      preview: 'https://example.test/thumb-1.jpg',
      type: 'image',
      not_found: false,
      listing_metadata: {},
      metadata: {},
      containers: [],
    }

    const wrapper = mount(FullSizeViewer, {
      props: {
        open: true,
        item,
        items: [item],
        scroller: { loadNext: vi.fn().mockResolvedValue(undefined) },
      },
      global: {
        stubs: {
          transition: false,
          teleport: true,
        },
      },
    })

    await nextTick()

    expect(wrapper.vm.fullLoaded).toBe(false)

    // Simulate image load
    const img = wrapper.find('img')
    if (img.exists()) {
      img.element.dispatchEvent(new Event('load'))
      await nextTick()

      expect(wrapper.vm.fullLoaded).toBe(true)
    }
  })

  it('can navigate to next item', async () => {
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

    await nextTick()

    await wrapper.vm.navigate(1)
    await nextTick()

    const updateEvents = wrapper.emitted('update:item') || []
    const updatedItem = updateEvents.at(-1)?.[0]
    expect(updatedItem?.id).toBe(2)
  })

  it('can navigate to previous item', async () => {
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
        item: items[1],
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

    await nextTick()

    await wrapper.vm.navigate(-1)
    await nextTick()

    const updateEvents = wrapper.emitted('update:item') || []
    const updatedItem = updateEvents.at(-1)?.[0]
    expect(updatedItem?.id).toBe(1)
  })

  it('shows carousel when file is clicked', async () => {
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

    await nextTick()

    expect(wrapper.vm.thumbsVisible).toBe(false)

    // Click on media to toggle carousel
    const img = wrapper.find('img')
    if (img.exists()) {
      await img.trigger('click')
      await nextTick()

      expect(wrapper.vm.thumbsVisible).toBe(true)
    }
  })

  it('tracks which file from carousel is active', async () => {
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
      {
        id: 3,
        original: 'https://example.test/image-3.jpg',
        preview: 'https://example.test/thumb-3.jpg',
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
        item: items[1],
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

    await nextTick()

    // Check active index
    expect(wrapper.vm.activeThumbIndex).toBe(1)
    expect(wrapper.vm.activeVisibleThumbIndex).toBe(1)

    // Navigate to next
    await wrapper.vm.navigate(1)
    await nextTick()

    const updateEvents = wrapper.emitted('update:item') || []
    const updatedItem = updateEvents.at(-1)?.[0]
    if (updatedItem) {
      await wrapper.setProps({ item: updatedItem })
      await nextTick()

      expect((wrapper.vm.dialogItem as any)?.id).toBe(3)
      expect(wrapper.vm.activeThumbIndex).toBe(2)
    }
  })

  it('renders FileReactions component with correct file prop', async () => {
    const item = {
      id: 42,
      original: 'https://example.test/image-1.jpg',
      preview: 'https://example.test/thumb-1.jpg',
      type: 'image',
      not_found: false,
      listing_metadata: {},
      metadata: {},
      containers: [],
    }

    const wrapper = mount(FullSizeViewer, {
      props: {
        open: true,
        item,
        items: [item],
        scroller: { loadNext: vi.fn().mockResolvedValue(undefined) },
      },
      global: {
        stubs: {
          transition: false,
          teleport: true,
        },
      },
    })

    await nextTick()

    const reactions = wrapper.find('[data-test="file-reactions"]')
    expect(reactions.exists()).toBe(true)
    expect(reactions.attributes('data-file-id')).toBe('42')
  })

  it('allows reacting to file via FileReactions', async () => {
    const item = {
      id: 42,
      original: 'https://example.test/image-1.jpg',
      preview: 'https://example.test/thumb-1.jpg',
      type: 'image',
      not_found: false,
      listing_metadata: {},
      metadata: {},
      containers: [],
    }

    const wrapper = mount(FullSizeViewer, {
      props: {
        open: true,
        item,
        items: [item],
        scroller: { loadNext: vi.fn().mockResolvedValue(undefined) },
      },
      global: {
        stubs: {
          transition: false,
          teleport: true,
        },
      },
    })

    await nextTick()

    // Simulate media loaded
    wrapper.vm.fullLoaded = true
    await nextTick()

    // Trigger reactions
    const mockEvent = {} as Event

    wrapper.vm.handleFavorite(item, mockEvent)
    await nextTick()
    expect(wrapper.emitted('favorite')).toBeTruthy()
    expect(wrapper.vm.fullLoaded).toBe(false) // Should hide media immediately

    wrapper.vm.fullLoaded = true
    await nextTick()

    wrapper.vm.handleLike(item, mockEvent)
    await nextTick()
    expect(wrapper.emitted('like')).toBeTruthy()
    expect(wrapper.vm.fullLoaded).toBe(false)

    wrapper.vm.fullLoaded = true
    await nextTick()

    wrapper.vm.handleDislike(item, mockEvent)
    await nextTick()
    expect(wrapper.emitted('dislike')).toBeTruthy()
    expect(wrapper.vm.fullLoaded).toBe(false)

    wrapper.vm.fullLoaded = true
    await nextTick()

    wrapper.vm.handleLaughedAt(item, mockEvent)
    await nextTick()
    expect(wrapper.emitted('laughed-at')).toBeTruthy()
    expect(wrapper.vm.fullLoaded).toBe(false)
  })

  it('hides media immediately when reaction is triggered', async () => {
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

    // Simulate media loaded
    wrapper.vm.fullLoaded = true
    await nextTick()

    // Set an error state to verify it gets cleared
    wrapper.vm.setFullErrorState('unavailable', 503, 'Failed', false)
    await nextTick()

    expect(wrapper.vm.fullLoaded).toBe(true)
    expect(wrapper.vm.fullErrorKind).toBe('unavailable')

    // Trigger a reaction - should immediately hide media and clear errors
    wrapper.vm.handleLike(items[0], {} as Event)
    await nextTick()

    // Media should be hidden immediately
    expect(wrapper.vm.fullLoaded).toBe(false)
    // Error state should be cleared
    expect(wrapper.vm.fullErrorKind).toBe('none')
    // Loader overlay should be visible (fullLoaded is false)
    expect(wrapper.find('[data-test="loader-overlay"]').exists()).toBe(true)
  })

  it('hides media immediately for all reaction types', async () => {
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

    const mockEvent = {} as Event
    const reactionHandlers = [
      { name: 'handleFavorite', handler: wrapper.vm.handleFavorite },
      { name: 'handleLike', handler: wrapper.vm.handleLike },
      { name: 'handleDislike', handler: wrapper.vm.handleDislike },
      { name: 'handleLaughedAt', handler: wrapper.vm.handleLaughedAt },
    ]

    for (const { name, handler } of reactionHandlers) {
      // Reset state
      wrapper.vm.fullLoaded = true
      wrapper.vm.setFullErrorState('unavailable', 503, 'Failed', false)
      await nextTick()

      expect(wrapper.vm.fullLoaded).toBe(true)
      expect(wrapper.vm.fullErrorKind).toBe('unavailable')

      // Trigger reaction
      handler(items[0], mockEvent)
      await nextTick()

      // Media should be hidden immediately
      expect(wrapper.vm.fullLoaded).toBe(false)
      expect(wrapper.vm.fullErrorKind).toBe('none')
    }
  })
})

