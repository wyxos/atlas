import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { vi, describe, it, expect, afterEach } from 'vitest'
import FullSizeViewer from '@/pages/browse/FullSizeViewer.vue'

type Listener = (...args: any[]) => void

vi.mock('axios', () => ({
  default: {
    post: vi.fn().mockResolvedValue({ data: {} }),
    get: vi.fn().mockResolvedValue({ data: {} }),
    put: vi.fn().mockResolvedValue({ data: {} }),
  },
}))

vi.mock('@/actions/App/Http/Controllers/BrowseController', () => ({
  fileSeen: () => ({ url: '/file-seen' }),
  reportMissing: () => ({ url: '/report-missing' }),
  clearNotFound: () => ({ url: '/clear-not-found' }),
}))

describe('FullSizeViewer preloading', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('preloads the next video and image after current image load', async () => {
    // Track created <video> elements
    const createdVideos: HTMLVideoElement[] = []
    const originalCreateElement = document.createElement.bind(document)
    vi.spyOn(document, 'createElement').mockImplementation((tagName: any) => {
      const el = originalCreateElement(tagName)
      if (String(tagName).toLowerCase() === 'video') {
        createdVideos.push(el as HTMLVideoElement)
      }
      return el
    })

    // Track created Image() instances
    const createdImages: Array<any> = []
    const OriginalImage = (globalThis as any).Image
    class MockImage {
      src = ''
      referrerPolicy = ''
      private listeners: Record<string, Listener[]> = {}
      constructor() {
        createdImages.push(this)
      }
      addEventListener(evt: string, cb: Listener) {
        ;(this.listeners[evt] = this.listeners[evt] || []).push(cb)
      }
      removeEventListener() {}
    }
    ;(globalThis as any).Image = MockImage as any

    const items = [
      { id: 1, preview: 'https://example.com/1.jpg', type: 'image' },
      { id: 2, original: 'https://example.com/2.mp4', type: 'video' },
      { id: 3, preview: 'https://example.com/3.jpg', type: 'image' },
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
          Dialog: { template: '<div><slot /></div>' },
          DialogContent: { template: '<div><slot /></div>' },
          DialogTitle: { template: '<div><slot /></div>' },
          DialogDescription: { template: '<div><slot /></div>' },
          LoaderOverlay: { template: '<div />' },
          FileReactions: { template: '<div />' },
          ActionMenu: { template: '<div />' },
          ChevronsLeft: { template: '<i />' },
          ChevronsRight: { template: '<i />' },
          Eye: { template: '<i />' },
          X: { template: '<i />' },
          AlertTriangle: { template: '<i />' },
          ImageOff: { template: '<i />' },
          SquareArrowOutUpRight: { template: '<i />' },
          Teleport: false,
        },
      },
      attachTo: document.body,
    })

    try {
      await nextTick()
      const img = wrapper.find('img[alt="Full size"]')
      expect(img.exists()).toBe(true)

      await img.trigger('load')
      await nextTick()

      // Should have created a <video> for the next item
      expect(createdVideos.length).toBeGreaterThanOrEqual(1)
      expect(createdVideos[0].src).toBe('https://example.com/2.mp4')
      expect(createdVideos[0].preload).toBe('metadata')

      // And an Image() for the second next item
      expect(createdImages.length).toBeGreaterThanOrEqual(1)
      expect(createdImages[0].src).toBe('https://example.com/3.jpg')
    } finally {
      // Restore globals
      ;(globalThis as any).Image = OriginalImage
    }
  })
})


