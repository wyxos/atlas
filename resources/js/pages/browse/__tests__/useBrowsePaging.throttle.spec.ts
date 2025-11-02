import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createBrowseGetPage } from '@/pages/browse/useBrowsePaging'

const axiosMocks = vi.hoisted(() => ({ get: vi.fn(() => Promise.resolve({ data: { files: [], filter: { next: 4 } } })) }))
vi.mock('axios', () => ({ default: { get: axiosMocks.get } }))

const inertiaMocks = vi.hoisted(() => ({ router: { replace: vi.fn() } }))
vi.mock('@inertiajs/vue3', () => inertiaMocks)

describe('useBrowsePaging throttle', () => {
  beforeEach(() => { vi.useFakeTimers(); axiosMocks.get.mockClear(); inertiaMocks.router.replace.mockClear() })
  afterEach(() => vi.useRealTimers())

  it('coalesces multiple router.replace calls to one with latest params', async () => {
    const formState: any = { source: 'x', page: 1 }
    const form = { data: () => ({ ...formState }), defaults: (v: any) => Object.assign(formState, v || {}), reset: () => {} }
    const getPage = createBrowseGetPage(form as any)

    // Three quick calls
    await getPage(2)
    await getPage(3)
    await getPage(4)

    // Nothing yet until throttle elapsed
    expect(inertiaMocks.router.replace).not.toHaveBeenCalled()

    vi.advanceTimersByTime(500)

    expect(inertiaMocks.router.replace).toHaveBeenCalledTimes(1)
    const arg = inertiaMocks.router.replace.mock.calls[0][0]
    expect(arg).toHaveProperty('url')
    expect(String(arg.url)).toContain('page=')
  })
})