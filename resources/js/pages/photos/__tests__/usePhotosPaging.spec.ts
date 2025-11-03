import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createPhotosGetPage } from '@/pages/photos/usePhotosPaging'

const axiosMocks = vi.hoisted(() => ({
  get: vi.fn(() => Promise.resolve({ data: { files: [{ id: 9 }], filter: { next: 5 } } })),
}))
vi.mock('axios', () => ({ default: { get: axiosMocks.get } }))

const inertiaMocks = vi.hoisted(() => ({
  router: { replace: vi.fn() },
}))
vi.mock('@inertiajs/vue3', () => inertiaMocks)

vi.mock('@/actions/App/Http/Controllers/PhotosController', () => ({
  default: { data: () => ({ url: '/photos/data' }) },
}), { virtual: true })

type FormLike = { data: () => Record<string, any>; defaults: (v: any) => void; reset: () => void }

describe('createPhotosGetPage', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    axiosMocks.get.mockClear()
    inertiaMocks.router.replace.mockClear()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('uses data_url from form when present, syncs form via defaults/reset, and throttles URL to next', async () => {
    const formState: any = { data_url: '/custom/photos/data', page: 1, limit: 20, sort: 'random', rand_seed: 123 }
    const form: FormLike = {
      data: () => ({ ...formState }),
      defaults: (v: any) => Object.assign(formState, v || {}),
      reset: () => {},
    }
    const getPage = createPhotosGetPage(form as any)

    const res = await getPage(2)
    expect(res.items.map((x: any) => x.id)).toEqual([9])
    expect(res.nextPage).toBe(5)

    // axios.get called with custom URL and params (includes page + form state)
    expect(axiosMocks.get).toHaveBeenCalledWith('/custom/photos/data', expect.objectContaining({ params: expect.objectContaining({ page: 2, limit: 20, sort: 'random', rand_seed: 123 }) }))

    // router.replace is throttled; advance timer
    expect(inertiaMocks.router.replace).not.toHaveBeenCalled()
    vi.advanceTimersByTime(500)
    expect(inertiaMocks.router.replace).toHaveBeenCalledTimes(1)
    const call = inertiaMocks.router.replace.mock.calls[0][0]
    expect(call.url).toContain('?')
    // we put next into page param in URL
    expect(String(call.url)).toContain('page=5')
  })
})
