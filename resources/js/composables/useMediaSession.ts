import { watch } from 'vue';
import { audioActions, audioStore } from '@/stores/audio';

// Media Session + media key fallback integration for the global audio store
// Registers handlers for: play/pause, next/previous, seekto/forward/backward
// Updates metadata, playback state, and position for OS/lock-screen controls.
export function useMediaSession(): () => void {
  const hasMediaSession = typeof navigator !== 'undefined' && 'mediaSession' in navigator;

  function coverFrom(track: any): string | null {
    if (!track) return null;
    // Prefer album cover, then file cover
    if (track.albums && track.albums.length > 0) {
      for (const album of track.albums) {
        if (album?.covers?.length > 0) {
          return album.covers[0].url || album.covers[0].path || null;
        }
      }
    }
    if (track.covers && track.covers.length > 0) {
      return track.covers[0].url || track.covers[0].path || null;
    }
    return null;
  }

  function updateMetadata() {
    if (!hasMediaSession) return;
    const t = audioStore.currentTrack as any | null;
    if (!t) return;

    const title: string = t?.metadata?.payload?.title || '';
    const artist: string = (t?.artists && t.artists.length ? t.artists[0]?.name : 'Unknown Artist') || '';
    const album: string = (t?.albums && t.albums.length ? t.albums[0]?.name : '') || '';
    const art = coverFrom(t);

    try {
      const MediaMeta = (window as any).MediaMetadata;
      if (!MediaMeta) return;
      (navigator as any).mediaSession.metadata = new MediaMeta({
        title,
        artist,
        album,
        artwork: art
          ? [
              { src: art, sizes: '96x96', type: 'image/png' },
              { src: art, sizes: '192x192', type: 'image/png' },
              { src: art, sizes: '512x512', type: 'image/png' },
            ]
          : [],
      });
    } catch {
      // ignore
    }
  }

  function updatePlaybackState() {
    if (!hasMediaSession) return;
    try {
      (navigator as any).mediaSession.playbackState = audioStore.isPlaying ? 'playing' : 'paused';
    } catch {
      // ignore
    }
  }

  function updatePositionState() {
    if (!hasMediaSession) return;
    const setPos = (navigator as any).mediaSession?.setPositionState;
    if (typeof setPos !== 'function') return;
    try {
      setPos({
        duration: Number.isFinite(audioStore.duration) ? audioStore.duration : 0,
        position: Number.isFinite(audioStore.currentTime) ? audioStore.currentTime : 0,
        playbackRate: 1,
      });
    } catch {
      // ignore
    }
  }

  function registerActionHandlers() {
    if (!hasMediaSession) return;
    const ms = (navigator as any).mediaSession;
    try { ms.setActionHandler('previoustrack', () => audioActions.previous()); } catch {}
    try { ms.setActionHandler('nexttrack', () => audioActions.next()); } catch {}
    try {
      ms.setActionHandler('play', () => {
        audioActions.play();
      });
    } catch {}
    try {
      ms.setActionHandler('pause', () => {
        audioActions.pause();
      });
    } catch {}
    try {
      ms.setActionHandler('seekto', (e: any) => {
        const t = typeof e?.seekTime === 'number' ? e.seekTime : 0;
        audioActions.setCurrentTime(t);
      });
    } catch {}
    try {
      ms.setActionHandler('seekbackward', (e: any) => {
        const off = typeof e?.seekOffset === 'number' ? e.seekOffset : 10;
        const t = Math.max(0, (audioStore.currentTime || 0) - off);
        audioActions.setCurrentTime(t);
      });
    } catch {}
    try {
      ms.setActionHandler('seekforward', (e: any) => {
        const off = typeof e?.seekOffset === 'number' ? e.seekOffset : 10;
        const dur = Number.isFinite(audioStore.duration) ? audioStore.duration : Infinity;
        const t = Math.min(dur, (audioStore.currentTime || 0) + off);
        audioActions.setCurrentTime(t);
      });
    } catch {}
  }

  function clearActionHandlers() {
    if (!hasMediaSession) return;
    const ms = (navigator as any).mediaSession;
    const tryClear = (name: string) => {
      try { ms.setActionHandler(name, null); } catch {}
    };
    tryClear('previoustrack');
    tryClear('nexttrack');
    tryClear('play');
    tryClear('pause');
    tryClear('seekto');
    tryClear('seekbackward');
    tryClear('seekforward');
  }

  function registerKeyboardFallback() {
    const handler = (e: KeyboardEvent) => {
      const tag = (document.activeElement?.tagName || '').toUpperCase();
      if (tag === 'INPUT' || tag === 'TEXTAREA' || (document.activeElement as HTMLElement | null)?.isContentEditable) return;
      switch (e.code) {
        case 'MediaTrackNext':
          e.preventDefault();
          audioActions.next();
          break;
        case 'MediaTrackPrevious':
          e.preventDefault();
          audioActions.previous();
          break;
        case 'MediaPlayPause':
          e.preventDefault();
          audioActions.toggle();
          break;
      }
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }

  // Initialize
  registerActionHandlers();
  const unbindKeys = registerKeyboardFallback();
  updateMetadata();
  updatePlaybackState();
  updatePositionState();

  // Reactive sync to store
  const stopWatchTrack = watch(
    () => (audioStore.currentTrack ? (audioStore.currentTrack as any).id : null),
    () => { updateMetadata(); updatePositionState(); },
  );
  const stopWatchState = watch(
    () => audioStore.isPlaying,
    () => { updatePlaybackState(); },
  );
  const stopWatchProgress = watch(
    () => [audioStore.currentTime, audioStore.duration] as const,
    () => { updatePositionState(); },
    { deep: false },
  );

  // Cleanup
  return () => {
    try { stopWatchTrack(); } catch {}
    try { stopWatchState(); } catch {}
    try { stopWatchProgress(); } catch {}
    try { unbindKeys?.(); } catch {}
    clearActionHandlers();
  };
}
