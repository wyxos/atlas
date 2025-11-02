import { nextTick } from 'vue'

export async function flushMicrotasks() {
  await Promise.resolve()
  await nextTick()
}

export async function advanceTimersBy(ms: number) {
  // Consumers should have vi.useFakeTimers enabled
  // @ts-expect-error vi is provided globally by Vitest runtime
  vi.advanceTimersByTime(ms)
  await flushMicrotasks()
}

export function setBoundingClientRect(el: Element, rect: Partial<DOMRect>) {
  Object.defineProperty(el, 'getBoundingClientRect', {
    value: () => ({
      x: 0, y: 0, top: 0, left: 0, right: (rect.right ?? (rect.width ?? 0)), bottom: (rect.bottom ?? (rect.height ?? 0)),
      width: rect.width ?? 0, height: rect.height ?? 0,
      toJSON: () => ({})
    }),
    configurable: true,
  })
}

export function resetAllMocks() {
  // @ts-expect-error Provided by Vitest runtime at test time
  vi.clearAllMocks?.()
  // @ts-expect-error Provided by Vitest runtime at test time
  vi.resetAllMocks?.()
  // @ts-expect-error Provided by Vitest runtime at test time
  vi.restoreAllMocks?.()
}