import { computed, onMounted, onUnmounted, ref, watch, type WatchStopHandle } from 'vue';
import type { AudioPlayerTrack } from '@/composables/useGlobalAudioPlayer';

export type AudioPlaybackRole = 'owner' | 'observer';

export type AudioPlaybackSession = {
    version: number;
    lease_token: string | null;
    owner_instance_id: string | null;
    owner_label: string | null;
    state: 'idle' | 'playing' | 'paused';
    source: 'local' | 'spotify' | null;
    current_track: AudioPlayerTrack | null;
    queue_label: string | null;
    position_seconds: number;
    duration_seconds: number | null;
    spotify_device_id: string | null;
    server_recorded_at_ms: number;
};

export type AudioPlaybackClaimResult = {
    role: AudioPlaybackRole;
    session: AudioPlaybackSession;
};

export type AudioPlaybackSessionSnapshot = Pick<
    AudioPlaybackSession,
    'state' | 'source' | 'current_track' | 'queue_label' | 'position_seconds' | 'duration_seconds' | 'spotify_device_id'
>;

type EchoChannel = {
    listen: (event: string, callback: (payload: unknown) => void) => EchoChannel;
};

type AxiosLike = {
    get: <T = unknown>(url: string) => Promise<{ data: T }>;
    post: <T = unknown>(url: string, data?: unknown) => Promise<{ data: T }>;
};

const AUDIO_PLAYBACK_INSTANCE_STORAGE_KEY = 'atlas:audioPlaybackInstanceId';
const AVAILABILITY_RETRY_MS = 250;
const HEARTBEAT_INTERVAL_MS = 5000;
const REMOTE_PROGRESS_TICK_MS = 250;

function emptyAudioPlaybackSession(): AudioPlaybackSession {
    return {
        version: 0,
        lease_token: null,
        owner_instance_id: null,
        owner_label: null,
        state: 'idle',
        source: null,
        current_track: null,
        queue_label: null,
        position_seconds: 0,
        duration_seconds: null,
        spotify_device_id: null,
        server_recorded_at_ms: Date.now(),
    };
}

const instanceId = ref(readOrCreateInstanceId());
const session = ref<AudioPlaybackSession>(emptyAudioPlaybackSession());
const remotePositionSeconds = ref(0);
const availabilityCheck = ref(0);
let echoChannelName: string | null = null;
let availabilityRetryTimeout: ReturnType<typeof setTimeout> | null = null;
let heartbeatInterval: ReturnType<typeof setInterval> | null = null;
let remoteProgressInterval: ReturnType<typeof setInterval> | null = null;
let hasStarted = false;
let snapshotProvider: (() => AudioPlaybackSessionSnapshot) | null = null;
let watcherStops: WatchStopHandle[] = [];

const isAvailable = computed(() => {
    availabilityCheck.value;

    return Boolean(currentUserId() !== null && echoClient() && axiosClient());
});
const hasOtherOwner = computed(() => Boolean(
    isAvailable.value
    && session.value.owner_instance_id
    && session.value.owner_instance_id !== instanceId.value,
));
const isLeaseOwner = computed(() => Boolean(
    isAvailable.value
    && session.value.owner_instance_id === instanceId.value
    && session.value.lease_token,
));
const canOutputAudio = computed(() => !isAvailable.value || !hasOtherOwner.value);
const role = computed<AudioPlaybackRole>(() => hasOtherOwner.value ? 'observer' : 'owner');
const shouldShowOwnershipUi = computed(() => hasOtherOwner.value && session.value.current_track !== null);

function currentUserId(): number | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const content = document.querySelector('meta[name="user-id"]')?.getAttribute('content');
    const parsed = Number(content);

    return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
}

function axiosClient(): AxiosLike | null {
    const axios = typeof window !== 'undefined' ? window.axios as unknown : null;

    if ((typeof axios !== 'object' && typeof axios !== 'function') || axios === null) {
        return null;
    }

    const candidate = axios as Partial<AxiosLike>;

    return typeof candidate.get === 'function' && typeof candidate.post === 'function'
        ? candidate as AxiosLike
        : null;
}

function echoClient(): { private: (channel: string) => EchoChannel; leave?: (channel: string) => void } | null {
    return typeof window !== 'undefined' && window.Echo
        ? window.Echo as unknown as { private: (channel: string) => EchoChannel; leave?: (channel: string) => void }
        : null;
}

function readOrCreateInstanceId(): string {
    if (typeof window === 'undefined' || !('sessionStorage' in window)) {
        return randomInstanceId();
    }

    const existing = window.sessionStorage.getItem(AUDIO_PLAYBACK_INSTANCE_STORAGE_KEY)?.trim();
    if (existing) {
        return existing;
    }

    const next = randomInstanceId();
    window.sessionStorage.setItem(AUDIO_PLAYBACK_INSTANCE_STORAGE_KEY, next);

    return next;
}

function randomInstanceId(): string {
    return `atlas-tab-${typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`}`;
}

function ownerLabel(): string {
    const platform = typeof navigator !== 'undefined' && navigator.platform?.trim()
        ? navigator.platform.trim()
        : 'this device';

    return `Atlas on ${platform}`;
}

function normalizeSession(value: unknown): AudioPlaybackSession | null {
    if (!value || typeof value !== 'object') {
        return null;
    }

    const payload = value as Partial<AudioPlaybackSession>;
    const state = payload.state === 'playing' || payload.state === 'paused' || payload.state === 'idle'
        ? payload.state
        : 'idle';
    const source = payload.source === 'local' || payload.source === 'spotify'
        ? payload.source
        : null;

    return {
        version: numeric(payload.version, 0),
        lease_token: nullableString(payload.lease_token),
        owner_instance_id: nullableString(payload.owner_instance_id),
        owner_label: nullableString(payload.owner_label),
        state,
        source,
        current_track: payload.current_track && typeof payload.current_track === 'object'
            ? payload.current_track as AudioPlayerTrack
            : null,
        queue_label: nullableString(payload.queue_label),
        position_seconds: numeric(payload.position_seconds, 0),
        duration_seconds: payload.duration_seconds === null || payload.duration_seconds === undefined
            ? null
            : numeric(payload.duration_seconds, 0),
        spotify_device_id: nullableString(payload.spotify_device_id),
        server_recorded_at_ms: numeric(payload.server_recorded_at_ms, Date.now()),
    };
}

function nullableString(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

function numeric(value: unknown, fallback: number): number {
    return typeof value === 'number' && Number.isFinite(value) ? value : fallback;
}

function clampPosition(seconds: number, durationSeconds: number | null): number {
    const positionSeconds = Math.max(0, seconds);

    return durationSeconds && durationSeconds > 0
        ? Math.min(durationSeconds, positionSeconds)
        : positionSeconds;
}

function estimatedRemotePosition(): number {
    const current = session.value;
    const elapsedSeconds = current.state === 'playing'
        ? Math.max(0, (Date.now() - current.server_recorded_at_ms) / 1000)
        : 0;

    return clampPosition(current.position_seconds + elapsedSeconds, current.duration_seconds);
}

function syncRemotePosition(): void {
    remotePositionSeconds.value = estimatedRemotePosition();
}

function refreshRemoteProgressTicker(): void {
    if (remoteProgressInterval) {
        clearInterval(remoteProgressInterval);
        remoteProgressInterval = null;
    }

    if (!hasOtherOwner.value || session.value.state !== 'playing') {
        syncRemotePosition();
        return;
    }

    remoteProgressInterval = setInterval(syncRemotePosition, REMOTE_PROGRESS_TICK_MS);
}

function applySession(payload: unknown): void {
    const nextSession = normalizeSession(payload);
    if (!nextSession || nextSession.version < session.value.version) {
        return;
    }

    session.value = nextSession;
    syncRemotePosition();
    refreshRemoteProgressTicker();
    refreshHeartbeat();
}

async function fetchCurrentSession(): Promise<void> {
    const axios = axiosClient();
    if (!isAvailable.value || !axios) {
        return;
    }

    try {
        const { data } = await axios.get<AudioPlaybackSession>('/api/audio/playback-session');
        applySession(data);
    } catch (error) {
        console.error('Failed to load audio playback session:', error);
    }
}

function startEchoListener(): void {
    const userId = currentUserId();
    const echo = echoClient();
    if (!echo || userId === null || echoChannelName) {
        return;
    }

    echoChannelName = `App.Models.User.${userId}`;
    echo.private(echoChannelName).listen('.AudioPlaybackSessionUpdated', applySession);
}

function stopEchoListener(): void {
    if (!echoChannelName) {
        return;
    }

    echoClient()?.leave?.(echoChannelName);
    echoChannelName = null;
}

function endpointPayload(snapshot: AudioPlaybackSessionSnapshot, includeLeaseToken: boolean): Record<string, unknown> {
    return {
        instance_id: instanceId.value,
        ...(includeLeaseToken ? { lease_token: session.value.lease_token } : { owner_label: ownerLabel() }),
        state: snapshot.state,
        source: snapshot.source,
        current_track: snapshot.current_track,
        queue_label: snapshot.queue_label,
        position_seconds: snapshot.position_seconds,
        duration_seconds: snapshot.duration_seconds,
        spotify_device_id: snapshot.spotify_device_id,
    };
}

async function claimOwnership(snapshot: AudioPlaybackSessionSnapshot): Promise<AudioPlaybackClaimResult> {
    const axios = axiosClient();
    if (!isAvailable.value || !axios) {
        return { role: role.value, session: session.value };
    }

    const { data } = await axios.post<AudioPlaybackSession>('/api/audio/playback-session/claim', endpointPayload(snapshot, false));
    applySession(data);

    return { role: role.value, session: session.value };
}

async function heartbeat(snapshot: AudioPlaybackSessionSnapshot | null = null): Promise<AudioPlaybackSession | null> {
    const axios = axiosClient();
    const nextSnapshot = snapshot ?? snapshotProvider?.() ?? null;
    if (!axios || !isLeaseOwner.value || !nextSnapshot) {
        return null;
    }

    try {
        const { data } = await axios.post<AudioPlaybackSession>('/api/audio/playback-session/heartbeat', endpointPayload(nextSnapshot, true));
        applySession(data);

        return session.value;
    } catch (error) {
        console.error('Failed to refresh audio playback ownership:', error);
        return null;
    }
}

async function update(snapshot: AudioPlaybackSessionSnapshot): Promise<AudioPlaybackSession | null> {
    const axios = axiosClient();
    if (!axios || !isLeaseOwner.value) {
        return null;
    }

    try {
        const { data } = await axios.post<AudioPlaybackSession>('/api/audio/playback-session/update', endpointPayload(snapshot, true));
        applySession(data);

        return session.value;
    } catch (error) {
        console.error('Failed to update audio playback session:', error);
        return null;
    }
}

async function release(snapshot: AudioPlaybackSessionSnapshot | null = snapshotProvider?.() ?? null): Promise<void> {
    const axios = axiosClient();
    if (!axios || !isLeaseOwner.value) {
        return;
    }

    try {
        const { data } = await axios.post<AudioPlaybackSession>('/api/audio/playback-session/release', endpointPayload(snapshot ?? {
            state: 'paused',
            source: session.value.source,
            current_track: session.value.current_track,
            queue_label: session.value.queue_label,
            position_seconds: remotePositionSeconds.value,
            duration_seconds: session.value.duration_seconds,
            spotify_device_id: session.value.spotify_device_id,
        }, true));
        applySession(data);
    } catch {
        // Release is best effort during navigation/unmount.
    }
}

function refreshHeartbeat(): void {
    if (heartbeatInterval) {
        clearInterval(heartbeatInterval);
        heartbeatInterval = null;
    }

    if (!isLeaseOwner.value || !snapshotProvider) {
        return;
    }

    heartbeatInterval = setInterval(() => {
        void heartbeat();
    }, HEARTBEAT_INTERVAL_MS);
}

function clearAvailabilityRetry(): void {
    if (!availabilityRetryTimeout) {
        return;
    }

    clearTimeout(availabilityRetryTimeout);
    availabilityRetryTimeout = null;
}

function scheduleAvailabilityRetry(): void {
    if (availabilityRetryTimeout) {
        return;
    }

    availabilityRetryTimeout = setTimeout(() => {
        availabilityRetryTimeout = null;
        start();
    }, AVAILABILITY_RETRY_MS);
}

function setSnapshotProvider(provider: () => AudioPlaybackSessionSnapshot): void {
    snapshotProvider = provider;
    refreshHeartbeat();
}

function refreshAvailability(): boolean {
    availabilityCheck.value += 1;

    return isAvailable.value;
}

function start(): void {
    const isSessionAvailable = refreshAvailability();
    if (hasStarted) {
        return;
    }

    if (!isSessionAvailable) {
        scheduleAvailabilityRetry();
        return;
    }

    clearAvailabilityRetry();
    instanceId.value = readOrCreateInstanceId();
    hasStarted = true;
    startEchoListener();
    void fetchCurrentSession();
}

function stop(): void {
    hasStarted = false;
    clearAvailabilityRetry();
    stopEchoListener();
    if (heartbeatInterval) {
        clearInterval(heartbeatInterval);
        heartbeatInterval = null;
    }
    if (remoteProgressInterval) {
        clearInterval(remoteProgressInterval);
        remoteProgressInterval = null;
    }
}

function ensureWatchers(): void {
    if (watcherStops.length > 0) {
        return;
    }

    watcherStops.push(watch(isLeaseOwner, refreshHeartbeat));
}

function stopWatchers(): void {
    watcherStops.forEach((stopWatcher) => stopWatcher());
    watcherStops = [];
}

export function resetAudioPlaybackSessionForTests(): void {
    stop();
    stopWatchers();
    snapshotProvider = null;
    availabilityCheck.value += 1;
    instanceId.value = readOrCreateInstanceId();
    session.value = emptyAudioPlaybackSession();
    remotePositionSeconds.value = 0;
}

export function useAudioPlaybackSession() {
    availabilityCheck.value += 1;
    ensureWatchers();

    onMounted(start);
    onUnmounted(stop);

    return {
        canOutputAudio,
        claimOwnership,
        fetchCurrentSession,
        hasOtherOwner,
        heartbeat,
        instanceId,
        isAvailable,
        isLeaseOwner,
        release,
        refreshAvailability,
        remotePositionSeconds,
        role,
        session,
        setSnapshotProvider,
        shouldShowOwnershipUi,
        start,
        stop,
        update,
    };
}
