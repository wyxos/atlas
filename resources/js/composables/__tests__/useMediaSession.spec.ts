import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { useMediaSession } from '@/composables/useMediaSession'
import { audioActions, audioStore } from '@/stores/audio'

function trigger(name: string, ev?: any) {
  ;(globalThis as any).__triggerMediaSession(name, ev)
}

describe('useMediaSession', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    // Reset store
    audioStore.queue = []
    audioStore.currentIndex = -1
    audioStore.currentTrack = null
    audioStore.isPlaying = false
    audioStore.currentTime = 0
    audioStore.duration = 0
    audioStore.volume = 1
    audioStore.repeatMode = 'off'
  })
  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  it('registers and triggers media session action handlers', () => {
    const spies = {
      next: vi.spyOn(audioActions, 'next').mockImplementation(() => undefined as any),
      previous: vi.spyOn(audioActions, 'previous').mockImplementation(() => undefined as any),
      play: vi.spyOn(audioActions, 'play').mockImplementation(() => undefined as any),
      pause: vi.spyOn(audioActions, 'pause').mockImplementation(() => undefined as any),
      setCurrentTime: vi.spyOn(audioActions, 'setCurrentTime').mockImplementation(() => undefined as any),
    }

    const stop = useMediaSession()

    trigger('nexttrack')
    trigger('previoustrack')
    trigger('seekto', { seekTime: 42 })
    trigger('seekbackward', { seekOffset: 5 })
    audioStore.currentTime = 50
    trigger('seekforward', { seekOffset: 10 })

    expect(spies.next).toHaveBeenCalled()
    expect(spies.previous).toHaveBeenCalled()
    expect(spies.setCurrentTime).toHaveBeenNthCalledWith(1, 42)
    // starts at 0, so backward clamps to 0
    expect(spies.setCurrentTime).toHaveBeenNthCalledWith(2, 0)
    // duration is 0 at this point, so forward clamps to 0
    expect(spies.setCurrentTime).toHaveBeenNthCalledWith(3, 0)

    stop()
  })

  it('rapid play/pause does not trigger next (no double-press heuristic)', () => {
    const nextSpy = vi.spyOn(audioActions, 'next').mockImplementation(() => undefined as any)
    const playSpy = vi.spyOn(audioActions, 'play').mockImplementation(() => undefined as any)
    const pauseSpy = vi.spyOn(audioActions, 'pause').mockImplementation(() => undefined as any)

    useMediaSession()

    trigger('play')
    vi.advanceTimersByTime(50)
    trigger('play')

    expect(nextSpy).toHaveBeenCalledTimes(0)
    expect(playSpy).toHaveBeenCalledTimes(2)

    trigger('pause')
    vi.advanceTimersByTime(50)
    trigger('pause')
    expect(nextSpy).toHaveBeenCalledTimes(0)
    expect(pauseSpy).toHaveBeenCalledTimes(2)
  })

  it('updates metadata, playback state and position state', async () => {
    useMediaSession()

    // Set a track and expect MediaMetadata applied
    audioStore.currentTrack = {
      id: 1,
      metadata: { payload: { title: 'Song A' } },
      artists: [{ name: 'Artist Z' }],
      albums: [{ covers: [{ url: 'cover.png' }] }],
    } as any
    // trigger watchers
    await Promise.resolve()

    const mediaSession: any = (navigator as any).mediaSession
    expect(mediaSession.metadata).toBeTruthy()
    expect(mediaSession.metadata.title).toBe('Song A')
    expect(mediaSession.metadata.artist).toBe('Artist Z')

    // Playback state
    audioStore.isPlaying = true
    await Promise.resolve()
    expect(mediaSession.playbackState).toBe('playing')

    // Position state
    audioStore.duration = 123
    audioStore.currentTime = 12
    await Promise.resolve()
    const last = (globalThis as any).__lastPositionState
    expect(last).toMatchObject({ duration: 123, position: 12 })
  })

  it('cleanup clears handlers so they no-op', () => {
    const nextSpy = vi.spyOn(audioActions, 'next').mockImplementation(() => undefined as any)
    const stop = useMediaSession()

    trigger('nexttrack')
    expect(nextSpy).toHaveBeenCalledTimes(1)

    stop() // clearActionHandlers to null
    trigger('nexttrack')
    expect(nextSpy).toHaveBeenCalledTimes(1)
  })
})