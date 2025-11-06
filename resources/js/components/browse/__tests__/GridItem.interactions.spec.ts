import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick, ref } from 'vue'
import GridItem from '@/components/browse/GridItem.vue'

const axiosMocks = vi.hoisted(() => ({
  post: vi.fn<
    (url: string, body?: any) => Promise<any>
  >(() => Promise.resolve({ data: {} })),
}))

vi.mock('axios', () => ({
  default: {
    post: axiosMocks.post,
  },
}))

type ReactionType = 'favorite' | 'like' | 'funny' | 'dislike'

type MountOverrides = {
  item?: any
  containerCounts?: Map<string, Map<string | number, number>>
}

const batchReactMock = vi.fn<
  (reaction: ReactionType, scope: { key: string; value: string | number }) => Promise<void>
>()

vi.mock('@/pages/browse/useBatchReact', () => ({
  createBatchReact: () => batchReactMock,
}))

describe('GridItem interactions', () => {
  beforeEach(() => {
    batchReactMock.mockReset()
    batchReactMock.mockResolvedValue(undefined)
    axiosMocks.post.mockClear()
    axiosMocks.post.mockResolvedValue({ data: {} })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  async function mountItem(overrides: MountOverrides = {}) {
    const baseItem = {
      id: 1,
      type: 'image',
      preview: 'about:blank',
      containers: [],
      ...overrides.item,
    }

    const io = {
      observer: {},
      register: (_el: Element, callback: () => void) => callback(),
      unregister: () => {},
    }

    const wrapper = mount(GridItem as any, {
      props: { item: baseItem },
      global: {
        provide: {
          'browse-items': ref([baseItem]),
          'browse-io': io,
          'browse-container-counts': overrides.containerCounts ?? new Map(),
          'browse-scroller': { removeMany: () => {}, remove: () => {}, loadNext: () => {} },
          'browse-schedule-refresh': () => {},
        },
        stubs: {
          FileReactions: { template: '<div />' },
          Button: { template: '<button><slot /></button>' },
          LoaderOverlay: { template: '<div />' },
          TooltipProvider: { template: '<div><slot /></div>' },
          Tooltip: { template: '<div><slot /><slot name="content" /></div>' },
          TooltipTrigger: { template: '<div><slot /></div>' },
          TooltipContent: { template: '<div><slot /></div>' },
          ActionMenu: { template: '<div />' },
        },
      },
      attachTo: document.body,
    })

    await nextTick()
    await nextTick()

    return wrapper
  }

  it('emits like on alt+click of preview image', async () => {
    const wrapper = await mountItem()
    const img = wrapper.find('img')
    expect(img.exists()).toBe(true)

    await img.trigger('click', { altKey: true, button: 0 })

    expect(wrapper.emitted('like')).toBeTruthy()
    wrapper.unmount()
  })

  it('emits dislike on alt+contextmenu', async () => {
    const wrapper = await mountItem()
    const img = wrapper.find('img')

    await img.trigger('contextmenu', { altKey: true, button: 2 })

    expect(wrapper.emitted('dislike')).toBeTruthy()
    wrapper.unmount()
  })

  it('emits open on plain click', async () => {
    const wrapper = await mountItem()
    const img = wrapper.find('img')

    await img.trigger('click')

    expect(wrapper.emitted('open')).toBeTruthy()
    wrapper.unmount()
  })

  it('opens URL in new tab on middle-click (auxclick)', async () => {
    const wrapper = await mountItem()
    const img = wrapper.find('img')
    expect(img.exists()).toBe(true)

    const originalOpen = window.open
    let openedUrl: string | null = null
    window.open = ((url: string) => {
      openedUrl = url
      return null
    }) as any

    await img.trigger('mousedown', { button: 1, altKey: false })
    await img.trigger('auxclick', { button: 1, altKey: false })

    expect(openedUrl).toBe('about:blank')

    window.open = originalOpen
    wrapper.unmount()
  })

  it('emits favorite on alt+middle-click (alt+auxclick)', async () => {
    const wrapper = await mountItem()
    const img = wrapper.find('img')
    expect(img.exists()).toBe(true)

    await img.trigger('mousedown', { button: 1, altKey: true })
    await img.trigger('auxclick', { button: 1, altKey: true })

    expect(wrapper.emitted('favorite')).toBeTruthy()
    wrapper.unmount()
  })

  it('does not emit open when middle-clicking', async () => {
    const wrapper = await mountItem()
    const img = wrapper.find('img')

    const originalOpen = window.open
    window.open = (() => null) as any

    await img.trigger('mousedown', { button: 1, altKey: false })
    await img.trigger('auxclick', { button: 1, altKey: false })

    expect(wrapper.emitted('open')).toBeFalsy()

    window.open = originalOpen
    wrapper.unmount()
  })

  it('handles alt+left click on batch badge with like + download', async () => {
    const containerCounts = new Map<string, Map<string | number, number>>([
      ['gallery', new Map([['abc', 2]])],
    ])

    const wrapper = await mountItem({
      item: {
        id: 1,
        type: 'image',
        preview: 'about:blank',
        containers: [{ key: 'gallery', value: 'abc', label: 'Gallery' }],
      },
      containerCounts,
    })

    axiosMocks.post.mockResolvedValue({ data: {} })

    const card = wrapper.find('.grid-item')
    await card.trigger('mouseenter')
    await nextTick()

    const badge = wrapper.find('[data-testid="hover-batch-badge"]')
    expect(badge.exists()).toBe(true)

    await badge.trigger('mousedown', { altKey: true, button: 0 })
    await nextTick()

    expect(batchReactMock).toHaveBeenCalledWith('like', { key: 'gallery', value: 'abc' })
    expect(axiosMocks.post).toHaveBeenCalledWith(expect.stringContaining('/react-download'), { type: 'like' })
    wrapper.unmount()
  })

  it('handles alt+right click on batch badge with dislike', async () => {
    const containerCounts = new Map<string, Map<string | number, number>>([
      ['gallery', new Map([['abc', 2]])],
    ])

    const wrapper = await mountItem({
      item: {
        id: 1,
        type: 'image',
        preview: 'about:blank',
        containers: [{ key: 'gallery', value: 'abc', label: 'Gallery' }],
      },
      containerCounts,
    })

    const card = wrapper.find('.grid-item')
    await card.trigger('mouseenter')
    await nextTick()

    const badge = wrapper.find('[data-testid="hover-batch-badge"]')
    expect(badge.exists()).toBe(true)

    const preventDefault = vi.fn()
    await badge.trigger('contextmenu', { altKey: true, button: 2, preventDefault })

    expect(preventDefault).toHaveBeenCalled()
    expect(batchReactMock).toHaveBeenCalledWith('dislike', { key: 'gallery', value: 'abc' })

    wrapper.unmount()
  })

  it('handles alt+middle click on batch badge with favorite', async () => {
    const containerCounts = new Map<string, Map<string | number, number>>([
      ['gallery', new Map([['abc', 2]])],
    ])

    const wrapper = await mountItem({
      item: {
        id: 1,
        type: 'image',
        preview: 'about:blank',
        containers: [{ key: 'gallery', value: 'abc', label: 'Gallery' }],
      },
      containerCounts,
    })

    const card = wrapper.find('.grid-item')
    await card.trigger('mouseenter')
    await nextTick()

    const badge = wrapper.find('[data-testid="hover-batch-badge"]')
    expect(badge.exists()).toBe(true)

    await badge.trigger('mousedown', { altKey: true, button: 1 })
    await badge.trigger('auxclick', { altKey: true, button: 1 })
    await nextTick()

    expect(batchReactMock).toHaveBeenCalledWith('favorite', { key: 'gallery', value: 'abc' })

    wrapper.unmount()
  })
})
