import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createBrowseGetPage } from '@/pages/browse/useBrowsePaging'
import { bus } from '@/lib/bus'

const axiosMocks = vi.hoisted(() => ({
  get: vi.fn(() => Promise.resolve({ data: {
    files: [{ id: 1 }, { id: 2 }],
    filter: { next: 3 },
    moderation: { ids: [1, 2], previews: [{ preview: 'p1', title: 't1' }, { preview: 'p2', title: 't2' }] },
  } })),
}))
vi.mock('axios', () => ({ default: { get: axiosMocks.get } }))

const inertiaMocks = vi.hoisted(() => ({
  router: { replace: vi.fn() },
}))
vi.mock('@inertiajs/vue3', () => inertiaMocks)

describe('createBrowseGetPage', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    axiosMocks.get.mockClear()
    inertiaMocks.router.replace.mockClear()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('returns items and nextPage; updates form and router; schedules moderation flush', async () => {
    const formState: any = { source: 'x', page: 1 }
    const form = {
      data: () => ({ ...formState }),
      defaults: vi.fn((v: any) => Object.assign(formState, v || {})),
      reset: vi.fn(() => {}),
    }
    const getPage = createBrowseGetPage(form as any)
    const res = await getPage(2)
    expect(res.items.length).toBe(2)
    expect(res.nextPage).toBe(3)

    // form synced
    expect(form.defaults).toHaveBeenCalled()
    expect(form.reset).toHaveBeenCalled()

    // capture bus before advancing time
    const spy = vi.spyOn(bus, 'emit')

    // router replaced with query (throttled; fire after 500ms) and moderation flush
    vi.advanceTimersByTime(500)

    const call = inertiaMocks.router.replace.mock.calls[0]
    expect(call[0]).toHaveProperty('url')

    // bus moderation notify after 500ms
    const calls = spy.mock.calls.filter(([e]) => e === 'moderation:notify')
    expect(calls.length).toBe(1)
  })

  it('omits null parameters like type when fetching pages', async () => {
    const formState: any = { source: 'x', page: 1, type: null }
    const form = {
      data: () => ({ ...formState }),
      defaults: vi.fn((v: any) => Object.assign(formState, v || {})),
      reset: vi.fn(() => {}),
    }
    const getPage = createBrowseGetPage(form as any)
    await getPage(2)
    const params = axiosMocks.get.mock.calls.at(-1)?.[1]?.params ?? {}
    expect('type' in params).toBe(false)
  })

  it('includes type parameter when provided and syncs it to the URL', async () => {
    const formState: any = { source: 'x', page: 1, type: 'image' }
    const form = {
      data: () => ({ ...formState }),
      defaults: vi.fn((v: any) => Object.assign(formState, v || {})),
      reset: vi.fn(() => {}),
    }
    const getPage = createBrowseGetPage(form as any)
    await getPage(2)
    const params = axiosMocks.get.mock.calls.at(-1)?.[1]?.params ?? {}
    expect(params.type).toBe('image')

    vi.advanceTimersByTime(500)
    const routerCall = inertiaMocks.router.replace.mock.calls.at(-1)?.[0]
    expect(String(routerCall?.url ?? '')).toContain('type=image')
  })
})