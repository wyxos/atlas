import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { useAudioFileLoader } from '@/composables/useAudioFileLoader'
import { setBoundingClientRect, advanceTimersBy, resetAllMocks } from '@/test/utils'

vi.mock('@/actions/App/Http/Controllers/AudioController', () => ({
  details: ({ file }: any) => ({ url: `/audio/details/${file}` }),
  batchDetails: () => ({ url: '/audio/batch-details' }),
}), { virtual: true })

// Minimal axios mock supporting AbortController + isCancel (use hoisted refs for Vitest)
const axiosMocks = vi.hoisted(() => {
  const get = vi.fn((url: string, cfg: any = {}) => {
    return new Promise((resolve, reject) => {
      if (cfg?.signal) {
        const onAbort = () => reject({ __CANCEL__: true })
        cfg.signal.addEventListener('abort', onAbort)
      }
      const id = Number(url.split('/').pop())
      resolve({ data: { id, title: `F${id}` } })
    })
  })
  const post = vi.fn((url: string, body: any) => {
    const ids = Array.isArray(body?.file_ids) ? body.file_ids : []
    const data: any = {}
    for (const id of ids) data[id] = { id, title: `F${id}` }
    return Promise.resolve({ data })
  })
  return { get, post }
})
vi.mock('axios', () => ({
  default: Object.assign(({} as any), {
    get: axiosMocks.get,
    post: axiosMocks.post,
    isCancel: (e: any) => !!(e && e.__CANCEL__),
  }),
}))

describe('useAudioFileLoader', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    axiosMocks.get.mockClear()
    axiosMocks.post.mockClear()
  })
  afterEach(() => {
    vi.useRealTimers()
    resetAllMocks()
  })

  it('cancels previous non-priority request and caches last response', async () => {
    const { loadFileDetails, loadedFiles } = useAudioFileLoader()
    const p1 = loadFileDetails(1)
    const p2 = loadFileDetails(1) // should cancel p1

    await Promise.allSettled([p1, p2])
    expect(axiosMocks.get).toHaveBeenCalledTimes(2)
    expect(loadedFiles[1]).toMatchObject({ id: 1, title: 'F1' })
  })

  it('batch loads file details for visible items via handleScroll', async () => {
    const { handleScroll, visibleItems, observedItems } = useAudioFileLoader()

    // Create two elements in viewport
    const el1 = document.createElement('div')
    el1.setAttribute('data-item-id', '11')
    document.body.appendChild(el1)
    setBoundingClientRect(el1, { top: 10, bottom: 110, height: 100, width: 100 })

    const el2 = document.createElement('div')
    el2.setAttribute('data-item-id', '12')
    document.body.appendChild(el2)
    setBoundingClientRect(el2, { top: 20, bottom: 120, height: 100, width: 100 })

    // Mark as observed so prioritizer considers them
    observedItems.value.add('11')
    observedItems.value.add('12')

    handleScroll() // schedules prioritizeVisibleItems after 500ms
    await advanceTimersBy(500)

    // Should have considered elements visible and triggered a batch post
    expect(axiosMocks.post).toHaveBeenCalled()
    const [url, body] = axiosMocks.post.mock.calls[0]
    expect(url).toBe('/audio/batch-details')
    expect(body.file_ids).toEqual(expect.arrayContaining(['11', '12']))

    // cleanup
    document.body.removeChild(el1)
    document.body.removeChild(el2)
    visibleItems.value.clear()
    observedItems.value.clear()
  })

  it('batch load error clears pending and allows retry', async () => {
    const { loadBatchFileDetails } = useAudioFileLoader()
    const errSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
    axiosMocks.post.mockImplementationOnce(() => Promise.reject(new Error('boom')))
    await loadBatchFileDetails([21, 22]) // should swallow error and clear pendings
    await loadBatchFileDetails([21, 22])
    expect(axiosMocks.post).toHaveBeenCalledTimes(2)
    errSpy.mockRestore()
  })

  it('single details error returns null then subsequent call succeeds', async () => {
    const { loadFileDetails, loadedFiles } = useAudioFileLoader()
    const errSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
    axiosMocks.get.mockImplementationOnce(() => Promise.reject(new Error('net')))
    const res = await loadFileDetails(77)
    expect(res).toBeNull()
    const res2 = await loadFileDetails(77)
    expect(res2).toMatchObject({ id: 77, title: 'F77' })
    expect(loadedFiles[77]).toMatchObject({ id: 77 })
    errSpy.mockRestore()
  })
})
