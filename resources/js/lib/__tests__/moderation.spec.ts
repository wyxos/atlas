import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { enqueueModeration, flushModeration } from '@/lib/moderation'
import { bus } from '@/lib/bus'

describe('moderation aggregation', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('deduplicates and caps previews/titles; flush emits once', () => {
    const spy = vi.spyOn(bus, 'emit')

    enqueueModeration([1, 2, 2, 3, 4, 5], ['a', 'b', 'b', 'c', 'd', 'e'], ['t1', 't2', 't2', 't3', 't4', 't5'])
    enqueueModeration([3, 4, 6], ['f', 'g'], ['t6', 't7'])

    // Scheduled flush in 300ms
    vi.advanceTimersByTime(300)
    const calls = spy.mock.calls.filter(([e]) => e === 'moderation:notify')
    expect(calls.length).toBe(1)
    const payload = calls[0][1] as any
    // ids deduped
    expect(new Set(payload.ids)).toEqual(new Set([1, 2, 3, 4, 5, 6]))
    // previews limited to 4 unique
    expect(payload.previews.length).toBeLessThanOrEqual(4)
    expect(payload.previewTitles.length).toBeLessThanOrEqual(4)
    expect(payload.count).toBe(payload.ids.length)
  })

  it('flushModeration synchronously emits and clears', () => {
    const spy = vi.spyOn(bus, 'emit')
    enqueueModeration([9, 10], ['x', 'y'], ['tx', 'ty'])
    flushModeration()
    const calls = spy.mock.calls.filter(([e]) => e === 'moderation:notify')
    expect(calls.length).toBe(1)
    const payload = calls[0][1] as any
    expect(payload.ids).toEqual([9, 10])
  })
})