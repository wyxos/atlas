import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { useAudioListPage } from '@/composables/useAudioListPage'
import { audioActions, audioStore } from '@/stores/audio'
import { bus } from '@/lib/bus'
import { flushMicrotasks } from '@/test/utils'

// Mock Inertia router
vi.mock('@inertiajs/vue3', () => ({
  router: { get: vi.fn(), replace: vi.fn() },
}))

// Mock useAudioFileLoader to control loadedFiles and batch loader
const loaderMocks = vi.hoisted(() => {
  const loadedFiles: Record<number, any> = {
    1: { id: 1, title: 'A' },
    2: { id: 2, title: 'B' },
    3: { id: 3, title: 'C' },
  }
  const loadBatchFileDetails = vi.fn()
  return { loadedFiles, loadBatchFileDetails }
})
vi.mock('@/composables/useAudioFileLoader', () => ({
  useAudioFileLoader: () => loaderMocks,
}))

function mountUseAudioListPage(overrides: Partial<Parameters<typeof useAudioListPage>[0]> = {}) {
  const options = {
    files: () => [{ id: 1 }, { id: 2 }, { id: 3 }],
    search: () => [],
    idOrder: () => [2, 1, 3],
    initialQuery: '',
    getSearchAction: () => ({ url: '/search' }),
    beforePlaySelected: vi.fn(async () => {}),
    playlistId: () => 7,
    ...overrides,
  }
  let page!: ReturnType<typeof useAudioListPage>
  const C = { setup: () => { page = useAudioListPage(options); return () => null } }
  mount(C)
  return page
}

describe('useAudioListPage', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    // reset audio store
    audioStore.queue = []
    audioStore.currentIndex = -1
    audioStore.currentTrack = null
    audioStore.isPlaying = false
    audioStore.queuePlaylistId = null
  })
  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  it('debounces search and calls router.get with trimmed query', async () => {
    const page = mountUseAudioListPage()
    const { router } = await import('@inertiajs/vue3') as any

    page.updateSearch('  hello  ')
    vi.advanceTimersByTime(300)

    expect(router.get).toHaveBeenCalledWith('/search', { query: 'hello' }, expect.objectContaining({ preserveState: true, only: ['search', 'query'], replace: true }))
  })

  it('scroll-to-current via bus triggers scroller and flash clears', async () => {
    // Mount a component to allow onMounted hook registration
    const options = {
      files: () => [{ id: 1 }, { id: 2 }, { id: 3 }],
      // ensure filteredItems includes id when search is non-empty
      search: () => [{ id: 1 }, { id: 2 }, { id: 3 }],
      idOrder: () => [2, 1, 3],
      initialQuery: '',
      getSearchAction: () => ({ url: '/search' }),
      beforePlaySelected: vi.fn(async () => {}),
      playlistId: () => 7,
    }
    let page: ReturnType<typeof useAudioListPage> | null = null
    const C = { setup: () => { page = useAudioListPage(options); return () => null } }
    mount(C)

    const scroller = { scrollToItem: vi.fn() }
    ;(page as any).recycleScrollerRef.value = scroller as any

    // Item id 2 exists in files()
    bus.emit('player:scroll-to-current', { id: 2 } as any)
    await flushMicrotasks()
    await flushMicrotasks()
    expect(scroller.scrollToItem).toHaveBeenCalled()

    // flashItemId clears after 1500ms
    expect((page as any).flashItemId.value).toBe(2)
    vi.advanceTimersByTime(1600)
    expect((page as any).flashItemId.value).toBeNull()
  })

  it('playAudio toggles when same id, and sets queue when new id', async () => {
    const playSpy = vi.spyOn(audioActions, 'play').mockImplementation(() => undefined as any)
    const pauseSpy = vi.spyOn(audioActions, 'pause').mockImplementation(() => undefined as any)
    const setQueueAndPlaySpy = vi.spyOn(audioActions, 'setQueueAndPlay').mockImplementation(() => undefined as any)

    const page = mountUseAudioListPage()

    // Toggle path
    audioStore.currentTrack = { id: 1 } as any
    audioStore.isPlaying = false
    await page.playAudio({ id: 1 })
    expect(playSpy).toHaveBeenCalled()

    audioStore.isPlaying = true
    await page.playAudio({ id: 1 })
    expect(pauseSpy).toHaveBeenCalled()

    // New id path
    audioStore.currentTrack = { id: 2 } as any
    audioStore.isPlaying = false
    await page.playAudio({ id: 1 })
    expect(setQueueAndPlaySpy).toHaveBeenCalled()
    // queuePlaylistId should be set from playlistId()
    expect(audioStore.queuePlaylistId).toBe(7)
    const [playlistArg, startId] = setQueueAndPlaySpy.mock.calls.at(-1) as any
    expect(startId).toBe(1)
    // playlist constructed from idOrder [2,1,3] mapped via loadedFiles from loaderMocks
    expect(playlistArg.map((x: any) => x.id)).toEqual([2, 1, 3])
  })
})