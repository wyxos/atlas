// Global test setup for Vitest (jsdom)
// - Polyfills: Audio/HTMLAudioElement, Media Session API, MediaMetadata, IntersectionObserver, crypto.randomUUID
// - Ensures localStorage exists

// Audio polyfill
class FakeAudio {
  public src = ''
  public currentTime = 0
  public duration = 0
  public volume = 1
  public preload: string | null = null
  public readyState = 0
  private _listeners: Record<string, Set<(ev?: any) => void>> = {}

  addEventListener(type: string, cb: (ev?: any) => void) {
    if (!this._listeners[type]) this._listeners[type] = new Set()
    this._listeners[type].add(cb)
  }
  removeEventListener(type: string, cb: (ev?: any) => void) {
    this._listeners[type]?.delete(cb)
  }
  private _emit(type: string, ev?: any) {
    const list = this._listeners[type]
    if (list) for (const cb of Array.from(list)) try { cb(ev) } catch {}
  }
  load() {
    // Simulate metadata ready
    this.readyState = 1
    if (!Number.isFinite(this.duration) || this.duration <= 0) this.duration = 180
    this._emit('loadedmetadata')
  }
  async play(): Promise<void> {
    // Simulate successful play
    this._emit('play')
  }
  pause(): void {
    this._emit('pause')
  }
}

// Attach to global
;(globalThis as any).HTMLAudioElement = FakeAudio
;(globalThis as any).Audio = FakeAudio

// Media Session polyfill
const mediaSessionHandlers: Record<string, ((ev?: any) => void) | null> = Object.create(null)
;(globalThis as any).navigator = (globalThis as any).navigator || ({} as any)
;(globalThis as any).navigator.mediaSession = {
  metadata: null,
  playbackState: 'none',
  setActionHandler(name: string, handler: ((ev?: any) => void) | null) {
    mediaSessionHandlers[name] = handler
  },
  setPositionState(state: { duration?: number; position?: number; playbackRate?: number }) {
    // no-op in tests; can be spied via vi.spyOn if needed
    ;(globalThis as any).__lastPositionState = state
  },
}
;(globalThis as any).MediaMetadata = class MediaMetadata {
  title?: string; artist?: string; album?: string; artwork?: any[]
  constructor(data: any) { Object.assign(this, data) }
}
// Helper for tests to trigger actions (optional)
;(globalThis as any).__triggerMediaSession = (name: string, ev?: any) => {
  const fn = mediaSessionHandlers[name]
  if (typeof fn === 'function') fn(ev)
}

// IntersectionObserver polyfill
class FakeIntersectionObserver {
  _cb: (entries: any[], obs: any) => void
  _opts: any
  constructor(cb: (entries: any[], obs: any) => void, opts?: any) { this._cb = cb; this._opts = opts }
  observe() { /* no-op */ }
  unobserve() { /* no-op */ }
  disconnect() { /* no-op */ }
}
;(globalThis as any).IntersectionObserver = FakeIntersectionObserver as any

// crypto.randomUUID polyfill (deterministic-ish)
let __uuidCounter = 0
function __nextId() {
  __uuidCounter++
  const n = __uuidCounter.toString(16).padStart(12, '0')
  return `00000000-0000-4000-8000-${n.slice(-12)}`
}
// Do not reassign crypto (jsdom defines getter). Only add randomUUID if missing.
try {
  if (!((globalThis as any).crypto && typeof (globalThis as any).crypto.randomUUID === 'function')) {
    Object.defineProperty((globalThis as any).crypto, 'randomUUID', { value: () => __nextId(), configurable: true })
  }
} catch {
  // In rare environments where crypto is undefined, create a minimal object
  const c: any = {}
  Object.defineProperty(c, 'randomUUID', { value: () => __nextId(), configurable: true })
  Object.defineProperty(globalThis as any, 'crypto', { value: c, configurable: true })
}

// localStorage fallback (jsdom usually provides this)
try {
  const testKey = '__test__'
  window.localStorage.setItem(testKey, '1')
  window.localStorage.removeItem(testKey)
} catch {
  const store = new Map<string, string>()
  ;(globalThis as any).localStorage = {
    getItem(key: string) { return store.has(key) ? (store.get(key) as string) : null },
    setItem(key: string, value: string) { store.set(key, String(value)) },
    removeItem(key: string) { store.delete(key) },
    clear() { store.clear() },
    key(i: number) { return Array.from(store.keys())[i] || null },
    get length() { return store.size },
  }
}