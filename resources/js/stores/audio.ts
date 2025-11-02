import { reactive } from 'vue';
import { spotifyPlayer } from '@/sdk/spotifyPlayer';
import axios from 'axios';
import * as AudioController from '@/actions/App/Http/Controllers/AudioController';
import * as BrowseController from '@/actions/App/Http/Controllers/BrowseController';

// Minimal global audio player store and controls
// Bare-minimum queue + playback to satisfy: enqueue all items, render current item in global player, and start playing.

// Spotify uses SDK snapshots only; HTML audio still uses rAF for smoother progress
let htmlRafId: number | null = null;
let spotifyIntervalId: number | null = null;
const SPOTIFY_INTERVAL_MS = 250;
let spotifyBackgroundCheckId: number | null = null;
const SPOTIFY_BACKGROUND_CHECK_MS = 5000; // Poll SDK state every 5s as backup
// Target position when a user-initiated seek is pending; used to ignore stale SDK snapshots
let pendingSeekMs: number | null = null;
// Track whether we attempted to play the current id via local HTML audio
let lastLocalAttemptId: number | null = null;
let lastPlaybackTrackId: number | null = null;
let spotifyTargetUri: string | null = null;
let spotifyAwaitingFirstState = false;
const spotifyLastState = {
  baseMs: 0,
  reportedAt: 0,
  durationMs: 0,
  paused: true,
  uri: null as string | null,
  endFired: false,
};

type SpotifyPlaybackErrorState = {
  trackId: number | null;
  message: string;
  details: string | null;
};

type NormalizedDuration = {
  seconds: number;
  milliseconds: number;
};

function toPositiveNumber(value: unknown): number {
  if (typeof value === 'number') {
    if (!Number.isFinite(value)) return 0;
    return value > 0 ? value : 0;
  }
  if (typeof value === 'string') {
    const trimmed = value.trim();
    if (!trimmed) return 0;
    const num = Number(trimmed);
    if (!Number.isFinite(num)) return 0;
    return num > 0 ? num : 0;
  }
  return 0;
}

function toMilliseconds(value: unknown): number {
  return toPositiveNumber(value);
}

function toSeconds(value: unknown): number {
  return toPositiveNumber(value);
}

function normalizeDuration(track: any | null): NormalizedDuration {
  if (!track) {
    return { seconds: 0, milliseconds: 0 };
  }

  const msCandidates: unknown[] = [
    track?.duration_ms,
    track?.durationMs,
    track?.metadata?.duration_ms,
    track?.metadata?.durationMs,
    track?.metadata?.payload?.duration_ms,
    track?.metadata?.payload?.durationMs,
    track?.listing_metadata?.track?.duration_ms,
    track?.listing_metadata?.track?.durationMs,
    track?.detail_metadata?.track?.duration_ms,
    track?.detail_metadata?.track?.durationMs,
  ];
  for (const candidate of msCandidates) {
    const ms = toMilliseconds(candidate);
    if (ms > 0) {
      return { seconds: ms / 1000, milliseconds: ms };
    }
  }

  const secondCandidates: unknown[] = [
    track?.duration,
    track?.duration_seconds,
    track?.durationSeconds,
    track?.metadata?.duration,
    track?.metadata?.duration_seconds,
    track?.metadata?.durationSeconds,
    track?.metadata?.payload?.duration,
    track?.metadata?.payload?.duration_seconds,
    track?.metadata?.payload?.durationSeconds,
    track?.listing_metadata?.track?.duration,
    track?.listing_metadata?.track?.duration_seconds,
    track?.listing_metadata?.track?.durationSeconds,
    track?.detail_metadata?.track?.duration,
    track?.detail_metadata?.track?.duration_seconds,
    track?.detail_metadata?.track?.durationSeconds,
  ];
  for (const candidate of secondCandidates) {
    const seconds = toSeconds(candidate);
    if (seconds > 0) {
      return {
        seconds,
        milliseconds: Math.round(seconds * 1000),
      };
    }
  }

  return { seconds: 0, milliseconds: 0 };
}

function normalizedDurationMilliseconds(track: any | null): number {
  return normalizeDuration(track).milliseconds;
}

function hasUsableDuration(track: any | null): boolean {
  return normalizeDuration(track).seconds > 0;
}

function skipTrackDueToMissingDuration(trackId: number | null, isSpotify: boolean): void {
  if (!isSpotify && typeof trackId === 'number') {
    void reportMissing(trackId);
  }
  spotifyAwaitingFirstState = false;
  stopSpotifyTimer();
  const idx = audioStore.currentIndex;
  if (idx >= 0 && idx < audioStore.queue.length) {
    (audioStore.queue as any[]).splice(idx, 1);
  }
  if ((audioStore.queue as any[]).length === 0) {
    audioStore.currentTrack = null;
    audioStore.currentIndex = -1;
    audioStore.isPlaying = false;
    audioStore.currentTime = 0;
    audioStore.duration = 0;
    return;
  }
  const nextIndex = Math.min(Math.max(0, idx), Math.max(0, (audioStore.queue as any[]).length - 1));
  audioStore.currentIndex = nextIndex;
  audioStore.currentTrack = (audioStore.queue as any[])[nextIndex] || null;
  queueMicrotask(() => {
    loadCurrent();
    void playInternal();
  });
}

function parseSpotifyPlaybackError(error: unknown): { message: string; details: string | null } {
  const fallback = 'Spotify rejected playback for this track. Premium playback might be required.';
  let message = '';
  let details: string | null = null;

  const body = typeof (error as any)?.body === 'string' ? (error as any).body.trim() : '';
  if (body) {
    details = body;
    if (body.startsWith('{')) {
      try {
        const parsed = JSON.parse(body);
        const nested = parsed?.error?.message ?? parsed?.message ?? '';
        if (nested) {
          message = String(nested);
        }
      } catch {
        // leave details as raw body
      }
    } else if (!message) {
      message = body;
    }
  }

  const errMessage = typeof (error as any)?.message === 'string' ? (error as any).message.trim() : '';
  if (!message && errMessage) {
    message = errMessage;
  } else if (errMessage && errMessage !== message && !details) {
    details = errMessage;
  }

  if (!message) {
    message = fallback;
  }

  if (details && details === message) {
    details = null;
  }

  return { message, details };
}

let onSpotifyTrackComplete: (() => void) | null = null;

type SpotifySnapshotSource = 'listener' | 'refresh';

function extractSpotifyUri(snapshot: any): string | null {
  if (!snapshot) return null;
  const candidates: Array<unknown> = [
    snapshot?.track_window?.current_track?.uri,
    snapshot?.track_window?.current_track?.id ? `spotify:track:${snapshot.track_window.current_track.id}` : null,
    snapshot?.context?.uri,
  ];
  for (const candidate of candidates) {
    if (typeof candidate === 'string' && candidate.length > 0) {
      return candidate;
    }
  }
  return null;
}

function maybeAdvanceSpotifyTrack(positionMs: number, durationMs: number, paused: boolean): boolean {
  if (durationMs <= 0) return false;
  const tolerance = paused ? 2000 : 500;
  if (positionMs >= durationMs - tolerance) {
    if (!spotifyLastState.endFired) {
      spotifyLastState.endFired = true;
      stopSpotifyTimer();
      return true;
    }
    return false;
  }
  if (positionMs <= 2000) {
    spotifyLastState.endFired = false;
  }
  return false;
}

function startSpotifyTimer(): void {
  if (spotifyIntervalId != null) return;
  spotifyIntervalId = window.setInterval(() => {
    tickSpotifyProgress();
  }, SPOTIFY_INTERVAL_MS);
  // Also start background check timer for SDK state polling
  startSpotifyBackgroundCheck();
}

function stopSpotifyTimer(): void {
  if (spotifyIntervalId != null) {
    window.clearInterval(spotifyIntervalId);
    spotifyIntervalId = null;
  }
  stopSpotifyBackgroundCheck();
}

function startSpotifyBackgroundCheck(): void {
  if (spotifyBackgroundCheckId != null) return;
  spotifyBackgroundCheckId = window.setInterval(() => {
    // Poll SDK state every 5 seconds as backup for event listener
    void refreshSpotifySnapshot();
  }, SPOTIFY_BACKGROUND_CHECK_MS);
}

function stopSpotifyBackgroundCheck(): void {
  if (spotifyBackgroundCheckId != null) {
    window.clearInterval(spotifyBackgroundCheckId);
    spotifyBackgroundCheckId = null;
  }
}

function tickSpotifyProgress(): void {
  if (spotifyLastState.paused) return;
  const now = Date.now();
  const elapsed = Math.max(0, now - (spotifyLastState.reportedAt || 0));
  let positionMs = Math.max(0, spotifyLastState.baseMs + elapsed);
  const durationMs = spotifyLastState.durationMs;
  if (durationMs > 0) {
    positionMs = Math.min(positionMs, durationMs);
  }
  audioStore.currentTime = positionMs / 1000;
  if (durationMs > 0) {
    audioStore.duration = durationMs / 1000;
  }
  if (maybeAdvanceSpotifyTrack(positionMs, durationMs, false)) {
    confirmSpotifyCompletion();
  }
}

function confirmSpotifyCompletion(): void {
  if (typeof spotifyPlayer.getCurrentState !== 'function') {
    queueMicrotask(() => { onSpotifyTrackComplete?.(); });
    return;
  }
  void (async () => {
    try {
      const snapshot = await spotifyPlayer.getCurrentState();
      if (!snapshot) {
        queueMicrotask(() => { onSpotifyTrackComplete?.(); });
        return;
      }
      applySpotifySnapshot(
        snapshot.position,
        snapshot.duration,
        snapshot.paused,
        extractSpotifyUri(snapshot),
        'refresh',
      );
      const pos = spotifyLastState.baseMs;
      const dur = spotifyLastState.durationMs;
      const paused = spotifyLastState.paused;
      const tolerance = paused ? 2000 : 500;
      if (dur > 0 && pos >= dur - tolerance) {
        queueMicrotask(() => { onSpotifyTrackComplete?.(); });
      } else {
        spotifyLastState.endFired = false;
        if (!paused) {
          startSpotifyTimer();
        }
      }
    } catch (stateErr) {
      console.warn('Spotify completion check failed', stateErr);
      queueMicrotask(() => { onSpotifyTrackComplete?.(); });
    }
  })();
}

function applySpotifySnapshot(position: unknown, duration: unknown, pausedValue: unknown, currentUri: string | null, source: SpotifySnapshotSource = 'listener'): void {
  if (spotifyTargetUri && currentUri && currentUri !== spotifyTargetUri) {
    return;
  }

  spotifyAwaitingFirstState = false;

  const now = Date.now();
  const incomingMs = Math.max(0, Number(position ?? 0));
  const incomingDur = Math.max(0, Number(duration ?? 0));
  const paused = !!pausedValue;

  if (!paused && pendingSeekMs != null) {
    if (incomingMs < Math.max(0, pendingSeekMs)) {
      return;
    }
    pendingSeekMs = null;
  }

  const previousBase = spotifyLastState.baseMs;
  if (!paused && pendingSeekMs == null && previousBase > 0) {
    const tolerance = source === 'refresh' ? 1500 : 300;
    if (incomingMs + tolerance < previousBase) {
      return;
    }
  }

  spotifyLastState.baseMs = incomingMs;
  if (incomingDur > 0) {
    spotifyLastState.durationMs = incomingDur;
  } else if (spotifyLastState.durationMs <= 0) {
    try {
      const fallbackMs = normalizedDurationMilliseconds(audioStore.currentTrack);
      if (fallbackMs > 0) {
        spotifyLastState.durationMs = fallbackMs;
      }
    } catch {}
  }
  spotifyLastState.reportedAt = now;
  spotifyLastState.paused = paused;
  if (currentUri) {
    spotifyLastState.uri = currentUri;
  }

  audioStore.currentTime = spotifyLastState.baseMs / 1000;
  if (spotifyLastState.durationMs > 0) {
    audioStore.duration = spotifyLastState.durationMs / 1000;
  }
  audioStore.isPlaying = !paused;

  if (paused) {
    stopSpotifyTimer();
  } else {
    startSpotifyTimer();
  }

  const shouldAdvance = maybeAdvanceSpotifyTrack(spotifyLastState.baseMs, spotifyLastState.durationMs, paused);
  if (shouldAdvance) {
    // Advance from any source (listener or refresh) to handle background tab completion
    queueMicrotask(() => { onSpotifyTrackComplete?.(); });
  }
}

async function refreshSpotifySnapshot(): Promise<void> {
  if (typeof spotifyPlayer.getCurrentState !== 'function') return;
  try {
    const snapshot = await spotifyPlayer.getCurrentState();
    if (!snapshot) return;
    applySpotifySnapshot(
      snapshot.position,
      snapshot.duration,
      snapshot.paused,
      extractSpotifyUri(snapshot),
      'refresh',
    );
  } catch (stateErr) {
    console.warn('Spotify state refresh failed', stateErr);
  }
}

// Track IDs we've already reported as missing in this session to avoid duplicate POSTs
const reportedMissingIds = new Set<number>();

async function reportMissing(id: number): Promise<void> {
  if (!id || reportedMissingIds.has(id)) return;
  reportedMissingIds.add(id);
  try {
    const action = (BrowseController as any).reportMissing({ file: id });
    if (action?.url) {
      await axios.post(action.url);
    }
  } catch {}
}

async function verify404AndReport(id: number): Promise<void> {
  try {
    const res = await fetch(`/audio/stream/${id}`, { method: 'HEAD', credentials: 'same-origin' as RequestCredentials });
    if (res.status === 404) {
      await reportMissing(id);
    }
  } catch {}
}

// Singleton HTMLAudioElement
function skipIfCurrent(id: number | null | undefined) {
  const cur = (audioStore.currentTrack as any)?.id as number | undefined;
  if (typeof id !== 'number' || typeof cur !== 'number') return;
  if (id !== cur) return;
  spotifyAwaitingFirstState = false;
  stopSpotifyTimer();
  audioActions.next();
}

let audioEl: HTMLAudioElement | null = null;
function getAudio(): HTMLAudioElement {
  if (!audioEl) {
    audioEl = new Audio();
    audioEl.preload = 'metadata';
    audioEl.volume = Math.max(0, Math.min(1, audioStore.volume));

    audioEl.addEventListener('timeupdate', () => {
      // Fallback: if rAF loop isn’t running, keep currentTime in sync
      if (htmlRafId == null) {
        audioStore.currentTime = audioEl!.currentTime || 0;
      }
    });
    audioEl.addEventListener('loadedmetadata', () => {
      audioStore.duration = audioEl!.duration || 0;
    });
    audioEl.addEventListener('seeked', () => {
      // Reflect immediate position change regardless of play/pause
      audioStore.currentTime = audioEl!.currentTime || 0;
    });
    audioEl.addEventListener('play', () => { startHtmlProgress(); });
    audioEl.addEventListener('playing', () => { startHtmlProgress(); });
    audioEl.addEventListener('pause', () => { stopHtmlProgress(); });
    audioEl.addEventListener('ended', () => {
      stopHtmlProgress();
      // Advance to next track if available; otherwise stop
      audioActions.next();
    });
    audioEl.addEventListener('error', () => {
      // HTML audio failed to load. Only flag not_found when we actually attempted local stream for this id and it 404s.
      try {
        const id = (audioStore.currentTrack as any)?.id as number | undefined;
        if (typeof id === 'number' && lastLocalAttemptId === id) {
          void verify404AndReport(id);
        }
      } catch {}
      stopHtmlProgress();
      // Skip only if still on the failing id
      skipIfCurrent(lastLocalAttemptId);
    });
  }
  return audioEl;
}

function startHtmlProgress() {
  if (htmlRafId != null) return;
  const audio = getAudio();
  const loop = () => {
    // Only drive when local engine is active
    if (!isSpotifyTrack(audioStore.currentTrack) && !audio.paused) {
      audioStore.currentTime = audio.currentTime || 0;
      audioStore.duration = audio.duration || audioStore.duration || 0;
      htmlRafId = window.requestAnimationFrame(loop);
      return;
    }
    htmlRafId = null;
  };
  htmlRafId = window.requestAnimationFrame(loop);
}
function stopHtmlProgress() {
  if (htmlRafId != null) { cancelAnimationFrame(htmlRafId); htmlRafId = null; }
}

export type RepeatMode = 'off' | 'all' | 'one';

export const audioStore = reactive({
  queue: [] as any[],
  currentIndex: -1,
  currentTrack: null as any | null,
  isPlaying: false,
  currentTime: 0,
  duration: 0,
  volume: 1,
  repeatMode: 'off' as RepeatMode,
  // ID of the playlist the current queue originated from (if applicable)
  queuePlaylistId: null as number | null,
  spotifyPlaybackError: null as SpotifyPlaybackErrorState | null,
});

function isSpotifyTrack(track: any | null): boolean {
  if (!track) return false;
  const src = String((track as any)?.source || '').toLowerCase();
  const mime = String((track as any)?.mime_type || '').toLowerCase();
  if (src === 'spotify' || mime === 'audio/spotify') return true;
  const lm = (track as any)?.listing_metadata || {};
  const lmSource = String((lm as any)?.source || '').toLowerCase();
  const lmUri = String((lm as any)?.track?.uri || '').toLowerCase();
  if (lmSource === 'spotify' || lmUri.startsWith('spotify:track:')) return true;
  const dm = (track as any)?.detail_metadata || {};
  const dmSource = String((dm as any)?.source || '').toLowerCase();
  const dmUri = String((dm as any)?.track?.uri || '').toLowerCase();
  if (dmSource === 'spotify' || dmUri.startsWith('spotify:track:')) return true;
  const engine = String((track as any)?._engine || (track as any)?.meta?.engine || '').toLowerCase();
  return engine === 'spotify';
}

function spotifyUriFor(track: any | null): string | null {
  if (!track) return null;
  const uri = track?.listing_metadata?.track?.uri || null;
  const id = track?.listing_metadata?.track?.id || track?.source_id || null;
  if (uri) return String(uri);
  return id ? `spotify:track:${id}` : null;
}

function isSpotifyPlayableInMarket(track: any | null): boolean {
  if (!isSpotifyTrack(track)) return true;
  const t = track?.listing_metadata?.track || track?.detail_metadata?.track || null;
  if (!t) return true;
  if (t.is_playable === false) return false;
  const reason = (t.restrictions && (t.restrictions.reason || t.restrictions?.reasons)) || null;
  if (typeof reason === 'string') {
    return !/market|catalog|premium/i.test(reason);
  }
  if (Array.isArray(reason)) {
    const joined = reason.join(',');
    return !/market|catalog|premium/i.test(joined);
  }
  return true;
}

function needsDetails(track: any | null): boolean {
  if (!track) return false;
  const hasAnyMeta = !!(track.mime_type || track.source || track.listing_metadata || track.path);
  if (!hasAnyMeta) return true;
  return !hasUsableDuration(track);
}

async function ensureDetailsForCurrent(): Promise<void> {
  if (!audioStore.currentTrack) return;
  if (!needsDetails(audioStore.currentTrack)) return;
  try {
    const id = (audioStore.currentTrack as any).id;
    const action = AudioController.details({ file: id });
    const res = await axios.get(action.url);
    const detailed = res.data;
    audioStore.currentTrack = { ...(audioStore.currentTrack as any), ...detailed } as any;
    if (audioStore.currentIndex >= 0 && audioStore.currentIndex < audioStore.queue.length) {
      (audioStore.queue as any[])[audioStore.currentIndex] = audioStore.currentTrack;
    }
  } catch (e) {
    console.warn('Failed to fetch details for current track', e);
  }
}

function loadCurrent(): void {
  const audio = getAudio();
  const track = audioStore.currentTrack;
  if (!track) return;
  const id = track.id;
  if (!id) return;
  // Reset timers and bookkeeping immediately for UI responsiveness
  audioStore.currentTime = 0;
  audioStore.duration = 0;
  lastLocalAttemptId = null;
  lastPlaybackTrackId = null;

  const finalize = () => {
    const current = audioStore.currentTrack;
    if (!current || current.id !== id) return;

    if (!hasUsableDuration(current)) {
      skipTrackDueToMissingDuration(id, isSpotifyTrack(current));
      return;
    }

    const duration = normalizeDuration(current);
    if (duration.seconds > 0) {
      audioStore.duration = duration.seconds;
    }

    if (isSpotifyTrack(current)) {
      try { audio.pause(); } catch {}
      stopSpotifyTimer();
      spotifyLastState.baseMs = 0;
      spotifyLastState.reportedAt = Date.now();
      spotifyLastState.durationMs = duration.milliseconds;
      spotifyLastState.paused = true;
      spotifyLastState.uri = null;
      spotifyLastState.endFired = false;
      spotifyTargetUri = null;
      pendingSeekMs = null;
      lastLocalAttemptId = null;
      return;
    }

    try { void spotifyPlayer.pause(); } catch {}
    stopSpotifyTimer();
    audio.currentTime = 0;
    audio.src = `/audio/stream/${id}`;
    lastLocalAttemptId = id;
    audio.load();
  };

  if (needsDetails(track)) {
    try { audio.pause(); } catch {}
    stopSpotifyTimer();
    try { (audio as any).removeAttribute?.('src'); } catch { audio.src = ''; }
    audio.load();
    const targetId = id;
    void (async () => {
      try {
        await ensureDetailsForCurrent();
      } catch {}
      if (!audioStore.currentTrack || audioStore.currentTrack.id !== targetId) return;
      finalize();
    })();
    return;
  }

  finalize();
}

async function playInternal(): Promise<void> {
  // Ensure we have enough details to decide engine
  await ensureDetailsForCurrent();

// Spotify branch
  if (isSpotifyTrack(audioStore.currentTrack)) {
    // Ensure we have URI; if not, fetch details again
    let uri = spotifyUriFor(audioStore.currentTrack);
    if (!uri) {
      await ensureDetailsForCurrent();
      uri = spotifyUriFor(audioStore.currentTrack);
    }
    if (!uri) { console.warn('Missing Spotify URI'); return; }

    // Not playable in user's market → attempt relink before surfacing error
    if (!isSpotifyPlayableInMarket(audioStore.currentTrack)) {
      try {
        const resolved = await spotifyPlayer.resolvePlayableUri(uri);
        if (resolved && resolved.uri && resolved.uri !== uri) {
          uri = resolved.uri;
        } else {
          const currentId = (audioStore.currentTrack as any)?.id as number | undefined;
          const t = (audioStore.currentTrack as any)?.listing_metadata?.track || (audioStore.currentTrack as any)?.detail_metadata?.track || {};
          const reasons = (t?.restrictions?.reasons || t?.restrictions?.reason || (t?.is_playable === false ? 'is_playable=false' : null));
          const reasonText = Array.isArray(reasons) ? reasons.join(', ') : (typeof reasons === 'string' ? reasons : 'unavailable');
          const title = (audioStore.currentTrack as any)?.metadata?.payload?.title || (t?.name || '');
          const artist = ((audioStore.currentTrack as any)?.artists?.[0]?.name) || ((t?.artists?.[0]?.name) || '');
          const msg = 'This track is not playable in your market';
          const details = [
            title || artist ? `Track: ${[title, artist].filter(Boolean).join(' — ')}` : null,
            reasonText ? `Restrictions: ${reasonText}` : null,
          ].filter(Boolean).join('\n');
          console.warn('Spotify skip (not playable; relink failed)', { id: currentId, reasons: reasonText, track: { title, artist } });
          audioStore.spotifyPlaybackError = { trackId: typeof currentId === 'number' ? currentId : null, message: msg, details: details || null };
          return;
        }
      } catch {
        const currentId = (audioStore.currentTrack as any)?.id as number | undefined;
        audioStore.spotifyPlaybackError = { trackId: typeof currentId === 'number' ? currentId : null, message: 'This track is not playable in your market', details: null };
        return;
      }
    }

    // Target this URI and wire state updates, ignoring stale events for previous tracks
    spotifyTargetUri = uri;
    await spotifyPlayer.ensure(audioStore.volume);
    spotifyPlayer.setStateListener((pos, dur, paused, currentUri) => {
      applySpotifySnapshot(pos, dur, paused, currentUri || null, 'listener');
    });

    try {
      // Resume if paused on the same track
      const sameTrack = !!spotifyLastState.uri && spotifyLastState.uri === uri;
      if (sameTrack && spotifyLastState.paused) {
        spotifyAwaitingFirstState = true;
        stopSpotifyTimer();
        await spotifyPlayer.resume(audioStore.volume);
        audioStore.isPlaying = true;
        return;
      }

      // Switching tracks or first play: always pause to avoid overlap/glitches
      try { await spotifyPlayer.pause(); } catch {}

      // For same track resume, start at last known position; otherwise start at 0 for new track
      const startMs = sameTrack ? Math.max(0, Math.floor((audioStore.currentTime || 0) * 1000)) : 0;
      if (!sameTrack) { audioStore.currentTime = 0; }
      spotifyAwaitingFirstState = true;
      stopSpotifyTimer();
      
      // Skip activation when auto-advancing (no user gesture available)
      const isAutoAdvance = lastPlaybackTrackId != null && lastPlaybackTrackId !== (audioStore.currentTrack as any)?.id;
      await spotifyPlayer.playUri(uri, startMs, { skipActivation: isAutoAdvance, initialVolume: audioStore.volume });
      spotifyLastState.baseMs = startMs;
      spotifyLastState.reportedAt = Date.now();
      spotifyLastState.uri = uri;
      spotifyLastState.endFired = false;
      audioStore.isPlaying = true;
      lastPlaybackTrackId = (audioStore.currentTrack as any)?.id ?? null;
    } catch (err: any) {
      spotifyAwaitingFirstState = false;
      const status = (err && (err as any).status) || 0;
      if (status === 403) {
        // Try relinking once on 403 before surfacing error
        try {
          const nextUri = await spotifyPlayer.resolvePlayableUri(uri!);
          if (nextUri && nextUri.uri && nextUri.uri !== uri) {
            await spotifyPlayer.playUri(nextUri.uri, 0, { initialVolume: audioStore.volume });
            audioStore.isPlaying = true;
            return;
          }
        } catch {}
        const curId = (audioStore.currentTrack as any)?.id as number | undefined;
        try { await spotifyPlayer.pause(); } catch {}
        stopSpotifyTimer();
        spotifyTargetUri = null;
        spotifyLastState.uri = null;
        spotifyLastState.paused = true;
        spotifyLastState.endFired = false;
        pendingSeekMs = null;
        audioStore.isPlaying = false;
        const { message, details } = parseSpotifyPlaybackError(err);
        console.warn('Spotify 403 during play', { id: curId, message, details });
        audioStore.spotifyPlaybackError = {
          trackId: typeof curId === 'number' ? curId : null,
          message,
          details,
        };
        return;
      }
      console.error('Spotify play failed:', err);
      audioStore.isPlaying = false;
      stopSpotifyTimer();
      try {
        const curId = (audioStore.currentTrack as any)?.id as number | undefined;
        const { message, details } = parseSpotifyPlaybackError(err);
        audioStore.spotifyPlaybackError = {
          trackId: typeof curId === 'number' ? curId : null,
          message,
          details,
        };
      } catch {}
    }
    return;
  }
  const audio = getAudio();
  try {
    // Enforce mutual exclusivity: pause Spotify SDK before starting HTML audio
    try { await spotifyPlayer.pause(); } catch {}
    await audio.play();
    audioStore.isPlaying = true;
    startHtmlProgress();
  } catch (err: any) {
    const name = String(err?.name || '');
    const message = String(err?.message || '');
    const text = `${name} ${message}`;
    if (text.includes('AbortError') || /interrupted by a call to pause/i.test(text)) {
      // Ignore transient AbortError caused by quick pause/src change races
      return;
    }
    // If no supported source (often 404 → decode error), auto-skip and flag missing
    if (/NotSupportedError/i.test(text) || /no supported source/i.test(text) || /Failed to load because no supported source/i.test(text)) {
      // For local engine, only flag when we actually attempted a local stream for this id
      try {
        const id = (audioStore.currentTrack as any)?.id as number | undefined;
        if (typeof id === 'number' && lastLocalAttemptId === id) {
          void verify404AndReport(id);
        }
      } catch {}
      skipIfCurrent(lastLocalAttemptId);
      return;
    }
    console.error('Audio play failed:', err);
    audioStore.isPlaying = false;
  }
}

function shuffleArray<T>(array: T[]): T[] {
  const arr = array.slice();
  for (let i = arr.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [arr[i], arr[j]] = [arr[j], arr[i]];
  }
  return arr;
}

export const audioActions = {
  // Refresh Spotify state if the SDK has gone quiet for a bit (helps keep seekbar flowing without RAF)
  ensureTicker() {
    if (!isSpotifyTrack(audioStore.currentTrack)) return;
    if (spotifyAwaitingFirstState) return;
    if (audioStore.isPlaying && !spotifyLastState.paused) {
      startSpotifyTimer();
    } else if (!audioStore.isPlaying) {
      stopSpotifyTimer();
    }
    tickSpotifyProgress();
  },
  setQueueAndPlay(items: any[], startTrackId: number) {
    if (!Array.isArray(items) || items.length === 0) return;
    stopSpotifyTimer();
    audioStore.queue = [...items];
    const idx = audioStore.queue.findIndex((x: any) => x && x.id === startTrackId);
    audioStore.currentIndex = idx >= 0 ? idx : 0;
    audioStore.currentTrack = audioStore.queue[audioStore.currentIndex] || null;
    loadCurrent();
    void playInternal();
  },

  setQueue(items: any[]) {
    audioStore.queue = Array.isArray(items) ? [...items] : [];
    if (audioStore.queue.length > 0) {
      audioStore.currentIndex = 0;
      audioStore.currentTrack = audioStore.queue[0];
      loadCurrent();
    } else {
      audioStore.currentIndex = -1;
      audioStore.currentTrack = null;
      stopSpotifyTimer();
      spotifyLastState.paused = true;
    }
  },

  play() {
    if (isSpotifyTrack(audioStore.currentTrack)) {
      // Reset on external track change (id differs from last started)
      try {
        const currentId = (audioStore.currentTrack as any)?.id ?? null;
        if (lastPlaybackTrackId != null && currentId !== lastPlaybackTrackId) {
          loadCurrent();
        } else {
          // If switching to a different Spotify track (e.g., via external queue changes), compare by URI as well
          const nextUri = spotifyUriFor(audioStore.currentTrack);
          if (spotifyLastState.uri && nextUri && spotifyLastState.uri !== nextUri) {
            loadCurrent();
          }
        }
      } catch {}
      void playInternal();
      return;
    }
    if (!audioStore.currentTrack) return;
    const audio = getAudio();
    const desiredSrc = `/audio/stream/${audioStore.currentTrack.id}`;
    // Only (re)load if this is a different track or src is empty
    if (!audio.src || !audio.src.endsWith(desiredSrc)) {
      loadCurrent();
    }
    void playInternal();
  },

  pause() {
    if (isSpotifyTrack(audioStore.currentTrack)) {
      void spotifyPlayer.pause();
      spotifyLastState.paused = true;
      stopSpotifyTimer();
      audioStore.isPlaying = false;
      return;
    }
    const audio = getAudio();
    audio.pause();
    stopHtmlProgress();
    audioStore.isPlaying = false;
  },

  toggle() {
    if (isSpotifyTrack(audioStore.currentTrack)) {
      if (audioStore.isPlaying) { this.pause(); } else { this.play(); }
      return;
    }
    if (audioStore.isPlaying) {
      this.pause();
    } else {
      this.play();
    }
  },

  next() {
    if (isSpotifyTrack(audioStore.currentTrack)) { /* handled by queue advance below */ }
    stopSpotifyTimer();
    if (audioStore.queue.length === 0) {
      audioStore.isPlaying = false;
      return;
    }
    // Reset seekbar immediately
    audioStore.currentTime = 0;
    audioStore.duration = 0;

    if (audioStore.repeatMode === 'one') {
      // Replay the same track
      loadCurrent();
      void playInternal();
      return;
    }

    const nextIndex = audioStore.currentIndex + 1;
    if (nextIndex < audioStore.queue.length) {
      audioStore.currentIndex = nextIndex;
      audioStore.currentTrack = audioStore.queue[nextIndex];
      loadCurrent();
      void playInternal();
    } else {
      if (audioStore.repeatMode === 'all') {
        audioStore.currentIndex = 0;
        audioStore.currentTrack = audioStore.queue[0];
        loadCurrent();
        void playInternal();
      } else {
        // Reached end of queue: keep showing the last valid item
        audioStore.currentIndex = Math.min(Math.max(0, audioStore.currentIndex), Math.max(0, audioStore.queue.length - 1));
        audioStore.currentTrack = audioStore.queue[audioStore.currentIndex] || null;
        audioStore.isPlaying = false;
      }
    }
  },

  previous() {
    if (isSpotifyTrack(audioStore.currentTrack)) { /* handled by queue advance below */ }
    stopSpotifyTimer();
    if (audioStore.queue.length === 0) return;
    // Reset seekbar immediately
    audioStore.currentTime = 0;
    audioStore.duration = 0;

    const prevIndex = audioStore.currentIndex - 1;
    if (prevIndex >= 0) {
      audioStore.currentIndex = prevIndex;
      audioStore.currentTrack = audioStore.queue[prevIndex];
      loadCurrent();
      void playInternal();
    } else if (audioStore.repeatMode === 'all') {
      // Wrap to last
      audioStore.currentIndex = audioStore.queue.length - 1;
      audioStore.currentTrack = audioStore.queue[audioStore.currentIndex];
      loadCurrent();
      void playInternal();
    }
  },

  setCurrentTime(time: number) {
    if (isSpotifyTrack(audioStore.currentTrack)) {
      const ms = Math.max(0, Math.floor(time * 1000));
      const previousBase = spotifyLastState.baseMs;
      const previousReportedAt = spotifyLastState.reportedAt;
      const previousDurationMs = spotifyLastState.durationMs;
      const previousPaused = spotifyLastState.paused;
      const previousUiTime = audioStore.currentTime;
      const previousEndFired = spotifyLastState.endFired;
      pendingSeekMs = ms;
      const targetSeconds = Math.max(0, time);
      spotifyLastState.baseMs = ms;
      spotifyLastState.reportedAt = Date.now();
      spotifyLastState.paused = !audioStore.isPlaying;
      spotifyLastState.endFired = false;
      audioStore.currentTime = targetSeconds;
      if (audioStore.isPlaying) {
        startSpotifyTimer();
      } else {
        stopSpotifyTimer();
      }
      void spotifyPlayer.seek(ms).catch(async (err) => {
        console.warn('Spotify seek failed', err);
        pendingSeekMs = null;
        spotifyLastState.baseMs = previousBase;
        spotifyLastState.reportedAt = previousReportedAt;
        spotifyLastState.durationMs = previousDurationMs;
        spotifyLastState.paused = previousPaused;
        spotifyLastState.endFired = previousEndFired;
        audioStore.currentTime = previousUiTime;
        if (previousDurationMs > 0) {
          audioStore.duration = previousDurationMs / 1000;
        }
        if (previousPaused) {
          stopSpotifyTimer();
        }
        await refreshSpotifySnapshot();
      });
      return;
    }
    const audio = getAudio();
    const duration = audioStore.duration || audio.duration || 0;
    const clamped = Math.max(0, Math.min(time, duration));
    audio.currentTime = clamped;
    audioStore.currentTime = clamped;
  },

  setVolume(vol: number) {
    if (isSpotifyTrack(audioStore.currentTrack)) { void spotifyPlayer.setVolume(Math.max(0, Math.min(1, vol))); }
    const v = Math.max(0, Math.min(1, vol));
    audioStore.volume = v;
    const audio = getAudio();
    audio.volume = v;
    try { localStorage.setItem('atlas:volume', String(v)); } catch {}
  },

  initVolumeFromStorage() {
    try {
      const raw = localStorage.getItem('atlas:volume');
      if (raw != null) {
        const v = Number(raw);
        if (!Number.isNaN(v)) this.setVolume(v);
      }
    } catch {}
  },

  setRepeatMode(mode: RepeatMode) {
    audioStore.repeatMode = mode;
  },

  toggleRepeatMode() {
    audioStore.repeatMode = audioStore.repeatMode === 'off' ? 'all' : audioStore.repeatMode === 'all' ? 'one' : 'off';
  },

  shuffleQueue() {
    if (audioStore.queue.length <= 1) return;
    const current = audioStore.currentTrack;
    const rest = audioStore.queue.filter((x) => !current || x.id !== current.id);
    const shuffled = shuffleArray(rest);
    audioStore.queue = current ? [current, ...shuffled] : shuffled;
    if (current) {
      audioStore.currentIndex = 0;
    }
  },

  async resolveSpotifyPlaybackError(decision: 'skip' | 'stop') {
    const context = audioStore.spotifyPlaybackError;
    audioStore.spotifyPlaybackError = null;
    spotifyAwaitingFirstState = false;
    spotifyTargetUri = null;
    pendingSeekMs = null;
    stopSpotifyTimer();
    try { await spotifyPlayer.pause(); } catch {}
    spotifyLastState.paused = true;
    spotifyLastState.uri = null;
    spotifyLastState.endFired = false;
    if (decision === 'skip') {
      const candidate = context?.trackId ?? (audioStore.currentTrack as any)?.id ?? null;
      if (typeof candidate === 'number') {
        skipIfCurrent(candidate);
      } else {
        this.next();
      }
      return;
    }
    audioStore.isPlaying = false;
  },

  dismissSpotifyPlaybackError() {
    audioStore.spotifyPlaybackError = null;
  },
};

onSpotifyTrackComplete = () => {
  audioActions.next();
};

// Handle page visibility: refresh Spotify state when tab becomes visible
// This catches track completion that happened while in background
if (typeof document !== 'undefined') {
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && isSpotifyTrack(audioStore.currentTrack)) {
      void (async () => {
        try {
          await refreshSpotifySnapshot();
          // Check if track completed while we were away
          const pos = spotifyLastState.baseMs;
          const dur = spotifyLastState.durationMs;
          const paused = spotifyLastState.paused;
          if (dur > 0 && pos >= dur - 500 && !spotifyLastState.endFired) {
            spotifyLastState.endFired = true;
            stopSpotifyTimer();
            onSpotifyTrackComplete?.();
          } else if (!paused && audioStore.isPlaying) {
            // Resume ticker if playing
            startSpotifyTimer();
          }
        } catch (err) {
          console.warn('Failed to refresh Spotify state on visibility change', err);
        }
      })();
    }
  });
}
