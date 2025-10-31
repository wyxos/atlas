import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { undoManager } from '@/lib/undo'

function countBus() {
  const spy = vi.spyOn(undoManager.bus as any, 'emit')
  return {
    spy,
    count(ev: string) { return spy.mock.calls.filter(([e]) => e === ev).length },
    last(ev: string) { return spy.mock.calls.findLast(([e]) => e === ev) },
  }
}

describe('undoManager', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
    ;(undoManager as any).configure({ graceMs: 10000 })
  })

  it('push applies UI, schedules expiry, and emits new/remove', async () => {
    undoManager.configure({ graceMs: 50 })
    const applyUI = vi.fn()
    const revertUI = vi.fn()
    const doFn = vi.fn(async () => {})
    const undoFn = vi.fn(async () => {})

    const eb = countBus('')
    undoManager.push({ label: 'test', applyUI, revertUI, do: doFn, undo: undoFn })

    expect(applyUI).toHaveBeenCalled()
    expect(doFn).toHaveBeenCalled()
    expect(undoManager.stack.length).toBe(1)
    expect(eb.count('undo:new')).toBeGreaterThan(0)

    // Expire
    vi.advanceTimersByTime(60)
    expect(undoManager.stack.length).toBe(0)
    expect(eb.count('undo:remove')).toBeGreaterThan(0)
  })

  it('undo reverts UI, calls undo(), and removes; on undo failure re-applies UI', async () => {
    undoManager.configure({ graceMs: 1000 })
    const applyUI = vi.fn()
    const revertUI = vi.fn()
    const doFn = vi.fn(async () => {})
    const undoFn = vi.fn(async () => {})

    undoManager.push({ label: 'x', applyUI, revertUI, do: doFn, undo: undoFn })
    expect(undoManager.stack.length).toBe(1)
    await undoManager.undo()
    expect(revertUI).toHaveBeenCalled()
    expect(undoFn).toHaveBeenCalled()
    expect(undoManager.stack.length).toBe(0)

    // Failure path
    const apply2 = vi.fn()
    const revert2 = vi.fn()
    const do2 = vi.fn(async () => {})
    const undo2 = vi.fn(async () => { throw new Error('fail') })
    undoManager.push({ label: 'y', applyUI: apply2, revertUI: revert2, do: do2, undo: undo2 })
    await undoManager.undo()
    // Should re-apply UI when undo throws
    expect(apply2).toHaveBeenCalled()
  })

  it('pause/resume preserves remaining and update event fires', () => {
    undoManager.configure({ graceMs: 100 })
    const applyUI = vi.fn()
    const revertUI = vi.fn()
    const doFn = vi.fn(async () => {})
    const undoFn = vi.fn(async () => {})

    const eb = countBus('')
    undoManager.push({ label: 'z', applyUI, revertUI, do: doFn, undo: undoFn })

    // Let some time pass
    vi.advanceTimersByTime(40)
    undoManager.pause()
    // Resume and ensure update emitted
    undoManager.resume()
    expect(eb.count('undo:update')).toBeGreaterThan(0)

    // Should expire after remaining time (~60ms)
    vi.advanceTimersByTime(70)
    expect(undoManager.stack.length).toBe(0)
  })

  it('dismiss removes without calling undo()', () => {
    undoManager.configure({ graceMs: 1000 })
    const applyUI = vi.fn()
    const revertUI = vi.fn()
    const doFn = vi.fn(async () => {})
    const undoFn = vi.fn(async () => {})

    undoManager.push({ label: 'd', applyUI, revertUI, do: doFn, undo: undoFn })
    expect(undoManager.stack.length).toBe(1)
    undoManager.dismiss()
    expect(undoManager.stack.length).toBe(0)
    expect(undoFn).not.toHaveBeenCalled()
  })
})