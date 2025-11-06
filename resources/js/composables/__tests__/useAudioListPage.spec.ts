import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, VueWrapper } from '@vue/test-utils'
import { useAudioListPage, type UseAudioListPageOptions } from '@/composables/useAudioListPage'
import { bus } from '@/lib/bus'
import { flushMicrotasks } from '@/test/utils'
import { router } from '@inertiajs/vue3'

vi.mock('@inertiajs/vue3', () => ({
  router: { get: vi.fn(), replace: vi.fn() },
}))

const loaderMocks = vi.hoisted(() => {
  const loadedFiles: Record<number, any> = {
    1: { id: 1, title: 'Track A' },
    2: { id: 2, title: 'Track B' },
    3: { id: 3, title: 'Track C' },
  }
  const loadBatchFileDetails = vi.fn()
  return { loadedFiles, loadBatchFileDetails }
})

vi.mock('@/composables/useAudioFileLoader', () => ({
  useAudioFileLoader: () => loaderMocks,
}))

const mountedWrappers: VueWrapper[] = []

function mountUseAudioListPage(overrides: Partial<UseAudioListPageOptions> = {}) {
  const options: UseAudioListPageOptions = {
    files: () => [{ id: 1 }, { id: 2 }, { id: 3 }, { id: 4 }],
    search: () => [],
    idOrder: () => [1, 2, 3, 4],
    initialQuery: '',
    getSearchAction: (query: string) => ({ url: '/search', query }),
    ...overrides,
  }

  let page!: ReturnType<typeof useAudioListPage>
  const wrapper = mount({
    setup() {
      page = useAudioListPage(options)
      return () => null
    },
  })

  mountedWrappers.push(wrapper)
  return { page, wrapper }
}

describe('useAudioListPage', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    loaderMocks.loadBatchFileDetails.mockClear()
    loaderMocks.loadedFiles[1] = { id: 1, title: 'Track A' }
    loaderMocks.loadedFiles[2] = { id: 2, title: 'Track B' }
    loaderMocks.loadedFiles[3] = { id: 3, title: 'Track C' }
    delete loaderMocks.loadedFiles[4]
    router.get.mockClear()
  })

  afterEach(() => {
    mountedWrappers.splice(0).forEach((wrapper) => wrapper.unmount())
    vi.useRealTimers()
    vi.clearAllMocks()
  })

  it('debounces search and calls router.get with trimmed query', async () => {
    const { page } = mountUseAudioListPage()
    const { router } = await import('@inertiajs/vue3') as any

    page.updateSearch('  hello  ')
    expect(router.get).not.toHaveBeenCalled()

    vi.advanceTimersByTime(299)
    expect(router.get).not.toHaveBeenCalled()

    vi.advanceTimersByTime(1)

    expect(router.get).toHaveBeenCalledWith('/search', { query: 'hello' }, expect.objectContaining({
      preserveState: true,
      only: ['search', 'query'],
      replace: true,
    }))
  })

  it('queues visible range for detail loading when scroller updates', async () => {
    delete loaderMocks.loadedFiles[2]
    delete loaderMocks.loadedFiles[3]

    const { page } = mountUseAudioListPage({
      files: () => [{ id: 1 }, { id: 2 }, { id: 3 }, { id: 4 }],
    })

    page.onScrollerUpdate(0, 2, 0, 2)
    expect(loaderMocks.loadBatchFileDetails).not.toHaveBeenCalled()

    vi.advanceTimersByTime(500)

    expect(loaderMocks.loadBatchFileDetails).toHaveBeenCalledTimes(1)
    const [ids] = loaderMocks.loadBatchFileDetails.mock.calls[0]
    expect(ids).toEqual([2, 3])
  })

  it('updates loaded files when reaction events arrive', async () => {
    loaderMocks.loadedFiles[2] = { id: 2, loved: false, liked: false, disliked: false, funny: false }
    const onReactionEvent = vi.fn()
    mountUseAudioListPage({ onReactionEvent })

    bus.emit('file:reaction', { id: 2, loved: true, liked: false, disliked: false, funny: true })
    await flushMicrotasks()

    expect(loaderMocks.loadedFiles[2]).toMatchObject({ loved: true, funny: true })
    expect(onReactionEvent).toHaveBeenCalledWith({ id: 2, loved: true, liked: false, disliked: false, funny: true })
  })
})