/* Simple wrapper around Spotify Web Playback SDK for in-browser playback */
import { bus } from '@/lib/bus';

let sdkLoaded = false;
let player: any | null = null;
let deviceId: string | null = null;
let tokenCache: { access_token: string; expires_at?: number | null } | null = null;
let meCache: { product: string | null; country: string | null; fetchedAt: number } | null = null;

async function fetchToken(): Promise<string> {
  // Always ask server; it refreshes when needed
  const res = await fetch('/spotify/token', { credentials: 'same-origin' });
  const j = await res.json();
  if (!res.ok || !j?.access_token) throw new Error(j?.error || 'Token unavailable');
  tokenCache = { access_token: j.access_token, expires_at: j.expires_at ? Date.parse(j.expires_at) : null };
  return j.access_token as string;
}

async function apiFetch(method: string, url: string, body?: any): Promise<Response> {
  let token = tokenCache?.access_token || (await fetchToken());
  const make = async () => fetch(url, {
    method,
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });
  let res = await make();
  if (res.status === 401) {
    try { tokenCache = null; token = await fetchToken(); } catch {}
    res = await make();
  }
  if (res.status === 401) {
    try { bus.emit('spotify:auth:invalid', { reason: '401' }); } catch {}
  }
  return res;
}

function loadSdk(): Promise<void> {
  if (sdkLoaded) return Promise.resolve();
  return new Promise((resolve) => {
    const s = document.createElement('script');
    s.src = 'https://sdk.scdn.co/spotify-player.js';
    s.async = true;
    document.body.appendChild(s);
    (window as any).onSpotifyWebPlaybackSDKReady = () => { sdkLoaded = true; resolve(); };
  });
}

function attachStateChanged(cb: (positionMs: number, durationMs: number, paused: boolean, uri?: string | null) => void) {
  if (!player) return;
  player.addListener('player_state_changed', (state: any) => {
    if (!state) return;
    const position = Number(state.position || 0);
    const duration = Number(state.duration || 0);
    const paused = !!state.paused;
    const uri = state?.track_window?.current_track?.uri || null;
    cb(position, duration, paused, uri);
  });
}

async function ensureReady(initialVolume?: number): Promise<string> {
  await loadSdk();
  const isNewPlayer = !player;
  if (isNewPlayer) {
    player = new (window as any).Spotify.Player({
      name: 'Atlas Web Player',
      getOAuthToken: async (cb: (t: string) => void) => {
        try { cb(await fetchToken()); } catch (e) { console.warn('Spotify token fetch error', e); }
      },
      volume: initialVolume ?? 0.5,
    });
    player.addListener('ready', ({ device_id }: any) => { deviceId = device_id; });
    player.addListener('not_ready', ({ device_id }: any) => { if (deviceId === device_id) deviceId = null; });
    player.addListener('initialization_error', ({ message }: any) => console.warn('Spotify init error', message));
    player.addListener('authentication_error', ({ message }: any) => { console.warn('Spotify auth error', message); try { bus.emit('spotify:auth:invalid', { reason: 'authentication_error' }); } catch {} });
    player.addListener('account_error', ({ message }: any) => console.warn('Spotify account error', message));
    await player.connect();
  }
  // Wait up to ~5s for device to be ready
  const started = Date.now();
  while (!deviceId && Date.now() - started < 5000) {
    await new Promise((r) => setTimeout(r, 200));
  }
  if (!deviceId) throw new Error('Spotify device not ready');
  // Always sync volume when provided, even if player already existed
  if (initialVolume !== undefined) {
    try { await player?.setVolume(Math.max(0, Math.min(1, initialVolume))); } catch {}
  }
  return deviceId as string;
}

async function getMe(): Promise<any | null> {
  // Cache for 60s to cut calls
  if (meCache && Date.now() - meCache.fetchedAt < 60_000) {
    return { product: meCache.product, country: meCache.country };
  }
  const res = await apiFetch('GET', 'https://api.spotify.com/v1/me');
  if (!res.ok) return null;
  const json = await res.json();
  meCache = { product: (json?.product ?? null), country: (json?.country ?? null), fetchedAt: Date.now() };
  return json;
}

async function getPlayer(): Promise<any | null> {
  const res = await apiFetch('GET', 'https://api.spotify.com/v1/me/player');
  if (res.status === 204) return null; // no active device
  if (!res.ok) return null;
  try { return await res.json(); } catch { return null; }
}

async function ensurePremium(): Promise<void> {
  const me = await getMe();
  const product = String(me?.product || '').toLowerCase();
  if (product !== 'premium') {
    const err: any = new Error('Spotify Premium is required for playback');
    err.status = 403;
    throw err;
  }
}

async function transferToThisDevice(shouldPlay: boolean): Promise<void> {
  await ensureReady();
  const body = { device_ids: [deviceId as string], play: !!shouldPlay } as const;
  const res = await apiFetch('PUT', 'https://api.spotify.com/v1/me/player', body);
  if (!(res.status === 204 || res.status === 202)) {
    const msg = await res.text();
    const err: any = new Error(`Spotify transfer failed (${res.status}): ${msg}`);
    err.status = res.status;
    err.body = msg;
    throw err;
  }
}

async function ensureActiveDevice(shouldPlay = false): Promise<void> {
  await ensureReady();
  const state = await getPlayer();
  const activeId = state?.device?.id || null;
  const isActive = !!state?.device?.is_active;
  if (isActive && activeId === deviceId) return;
  await transferToThisDevice(!!shouldPlay);
}

async function apiPlay(body: any) {
  // Target our SDK device explicitly; also retry after a transfer if needed
  await ensureReady();
  const base = 'https://api.spotify.com/v1/me/player/play';
  const url = deviceId ? `${base}?device_id=${encodeURIComponent(deviceId)}` : base;
  let res = await apiFetch('PUT', url, body || {});
  if (res.status === 404) {
    // NO_ACTIVE_DEVICE fallback: transfer, then retry once
    try { await transferToThisDevice(true); } catch {}
    res = await apiFetch('PUT', url, body || {});
  }
  if (!(res.status === 204 || res.status === 202)) {
    const msg = await res.text();
    const err: any = new Error(`Spotify play failed (${res.status}): ${msg}`);
    err.status = res.status;
    err.body = msg;
    throw err;
  }
}

function parseSpotifyTrackId(input: string): string | null {
  if (!input) return null;
  if (/^spotify:track:/i.test(input)) return input.split(':').pop() || null;
  const m = input.match(/open\.spotify\.com\/track\/([a-zA-Z0-9]+)(?:\?|$)/);
  if (m) return m[1];
  if (/^[a-zA-Z0-9]{10,}$/i.test(input)) return input;
  return null;
}

async function getTrack(trackId: string): Promise<any | null> {
  const res = await apiFetch('GET', `https://api.spotify.com/v1/tracks/${encodeURIComponent(trackId)}?market=from_token`);
  if (!res.ok) return null;
  try { return await res.json(); } catch { return null; }
}

async function searchByISRC(isrc: string): Promise<any | null> {
  const q = encodeURIComponent(`isrc:${isrc}`);
  const res = await apiFetch('GET', `https://api.spotify.com/v1/search?q=${q}&type=track&market=from_token&limit=1`);
  if (!res.ok) return null;
  try {
    const j = await res.json();
    const items = j?.tracks?.items || [];
    return items && items.length ? items[0] : null;
  } catch { return null; }
}

async function searchByNameArtist(name: string, artist: string): Promise<any | null> {
  const terms = [name || '', artist || ''].filter(Boolean).map((s) => s.replace(/"/g, ''));
  if (terms.length === 0) return null;
  const q = encodeURIComponent(`track:"${terms[0]}" ${terms[1] ? `artist:"${terms[1]}"` : ''}`.trim());
  const res = await apiFetch('GET', `https://api.spotify.com/v1/search?q=${q}&type=track&market=from_token&limit=5&include_external=audio`);
  if (!res.ok) return null;
  try {
    const j = await res.json();
    const items = (j?.tracks?.items || []) as any[];
    const playable = items.find((it) => it && it.is_playable !== false);
    return playable || (items[0] || null);
  } catch { return null; }
}

export const spotifyPlayer = {
  async ensure(initialVolume?: number): Promise<void> {
    await ensureReady(initialVolume);
  },
  setStateListener(cb: ((positionMs: number, durationMs: number, paused: boolean, uri?: string | null) => void) | null) {
    if (!player) return;
    // Remove any existing state listeners to avoid leaks/duplicates
    try { (player as any).removeListener?.('player_state_changed'); } catch {}
    if (cb) attachStateChanged(cb);
  },
  clearStateListener() { try { if (player) (player as any).removeListener?.('player_state_changed'); } catch {} },
  async playUri(uri: string, positionMs = 0, options?: { skipActivation?: boolean; initialVolume?: number }) {
    await ensureReady(options?.initialVolume);
    await ensurePremium();
    await ensureActiveDevice(true);
    // Skip activateElement when auto-advancing in background tab (no user gesture)
    if (!options?.skipActivation) {
      try { await (player as any).activateElement?.(); } catch {}
    }
    await apiPlay({ uris: [uri], position_ms: Math.max(0, Math.floor(positionMs)) });
  },
  async pause() { try { await (player as any)?.pause(); } catch {} },
  async resume(initialVolume?: number) {
    await ensureReady(initialVolume);
    await ensurePremium();
    await ensureActiveDevice(false);
    try { await (player as any)?.resume(); } catch {}
  },
  async seek(positionMs: number) {
    await ensureReady();
    if (!player || typeof (player as any)?.seek !== 'function') {
      throw new Error('Spotify player not ready');
    }
    await (player as any).seek(Math.max(0, Math.floor(positionMs)));
  },
  async setVolume(vol: number) { try { await (player as any)?.setVolume(Math.max(0, Math.min(1, vol))); } catch {} },
  async getCurrentState(): Promise<any | null> { try { await ensureReady(); return await (player as any)?.getCurrentState?.() ?? null; } catch { return null; } },
  async destroy() {
    try { if (player) { (player as any).removeListener?.('player_state_changed'); await (player as any).disconnect?.(); } } catch {}
    player = null; deviceId = null; sdkLoaded = sdkLoaded; // keep sdkLoaded to avoid reloading script
  },
  // ---------- Relinking helpers ----------
  async resolvePlayableUri(inputUri: string): Promise<{ uri: string; from: 'same'|'linked_from'|'isrc'|'search'; id: string } | null> {
    try {
      await ensureReady();
      const id = parseSpotifyTrackId(inputUri);
      if (!id) return null;
      const primary = await getTrack(id);
      if (!primary) return null;
      // If already playable, keep same
      if (primary?.is_playable !== false) {
        return { uri: `spotify:track:${id}`, from: 'same', id };
      }
      // Try linked_from
      const linked = (primary?.linked_from?.id || primary?.linked_from?.uri ? primary?.linked_from : null) as any;
      if (linked?.id) {
        const linkedId = String(linked.id);
        const linkedTrack = await getTrack(linkedId);
        if (linkedTrack && linkedTrack?.is_playable !== false) {
          return { uri: `spotify:track:${linkedId}`, from: 'linked_from', id: linkedId };
        }
      }
      // Try ISRC
      const isrc = (primary?.external_ids?.isrc || primary?.external_ids?.ISRC || null) as string | null;
      if (isrc) {
        const isrcCandidate = await searchByISRC(isrc);
        if (isrcCandidate?.id) {
          const cand = await getTrack(String(isrcCandidate.id));
          if (cand && cand?.is_playable !== false) {
            const cid = String(cand.id);
            return { uri: `spotify:track:${cid}`, from: 'isrc', id: cid };
          }
        }
      }
      // Name/artist fallback
      const name = String(primary?.name || '');
      const artist = String(primary?.artists?.[0]?.name || '');
      if (name) {
        const s = await searchByNameArtist(name, artist);
        const sid = s?.id ? String(s.id) : null;
        if (sid) {
          const cand = await getTrack(sid);
          if (cand && cand?.is_playable !== false) {
            return { uri: `spotify:track:${sid}`, from: 'search', id: sid };
          }
        }
      }
      return null;
    } catch (e) {
      console.warn('resolvePlayableUri failed', e);
      return null;
    }
  },
};
