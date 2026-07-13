import { computed, nextTick, type ComputedRef, type Ref } from 'vue';
import { useGlobalAudioPlayer, type AudioPlayerTrack } from '@/composables/useGlobalAudioPlayer';
import {
    createSpotifyPlaybackController,
    isSpotifyPlaybackAuthenticationError,
    isSpotifyPlaybackSuperseded,
    type SpotifyPlaybackController,
    type SpotifyPlaybackSnapshot,
} from '@/services/spotifyPlayback';
import { registerSpotifyPlaybackLifecycle } from '@/services/spotifyPlaybackLifecycle';
import { canTrackOwnSpotifyPlayback, isSpotifyAudioTrack, type PlaybackEngine, spotifyStartConfirmationSnapshot } from '@/lib/audioPlaybackOwnership';

const SPOTIFY_POLL_INTERVAL_MS = 750;
const SPOTIFY_PROGRESS_TICK_INTERVAL_MS = 250;
const SPOTIFY_ENDED_TOLERANCE_SECONDS = 1.25;
const SPOTIFY_NEAR_END_WINDOW_SECONDS = 3;
const SPOTIFY_POSITION_RESET_SECONDS = 1;
const SPOTIFY_SNAPSHOT_CORRECTION_SECONDS = 1;
const SPOTIFY_START_POSITION_TOLERANCE_SECONDS = 3;
const SPOTIFY_POLL_START_ADVANCE_SECONDS = 0.25;
const SPOTIFY_START_STALE_GUARD_SECONDS = 6;
type GlobalAudioPlayer = ReturnType<typeof useGlobalAudioPlayer>;
type AudioPlaybackEngineOptions = { isPlaybackOwner?: Ref<boolean> | ComputedRef<boolean>; onSpotifyAuthenticationError?: (message: string) => void; onSpotifyPlaybackError?: () => void; onSpotifyRecoveryStateChange?: (isRecovering: boolean) => void; onTrackEnded?: (trackId: number) => void; volume?: Ref<number> };
type SpotifyPendingStart = { correctedStalePosition: boolean; observedFreshPlaybackAt: number | null; playConfirmedAt: number | null; positionSeconds: number; requestedAt: number; uri: string };
export function useAudioPlaybackEngines(
    audioPlayer: GlobalAudioPlayer,
    audioRef: Ref<HTMLAudioElement | null>,
    currentTime: Ref<number>,
    mediaDuration: Ref<number>,
    durationSeconds: ComputedRef<number>,
    options: AudioPlaybackEngineOptions = {},
) {
    const nativeAudioSource = computed(() => isPlaybackOwner() && audioPlayer.currentTrack.value && !isSpotifyAudioTrack(audioPlayer.currentTrack.value) ? audioPlayer.currentTrack.value.playbackUrl : undefined);
    let activeEngine: PlaybackEngine | null = null;
    let playbackToken = 0;
    let spotifyPlayback: SpotifyPlaybackController | null = null;
    let spotifyHasObservedPlayback = false;
    let spotifyPollingInterval: ReturnType<typeof setInterval> | null = null, spotifyProgressInterval: ReturnType<typeof setInterval> | null = null;
    let spotifyLastSnapshot: SpotifyPlaybackSnapshot | null = null;
    let spotifyDisplayPositionSeconds: number | null = null, spotifyDisplayPositionAt = 0;
    let spotifyPendingStart: SpotifyPendingStart | null = null;
    let spotifyWasNearEnd = false, spotifyEndHandled = false;
    let displayedTrackId = audioPlayer.currentTrackId.value;

    function isPlaybackOwner(): boolean { return options.isPlaybackOwner?.value ?? true; }
    function spotifyController(): SpotifyPlaybackController {
        spotifyPlayback ??= createSpotifyPlaybackController({
            initialVolume: options.volume?.value ?? 0.7,
            onError: (message) => console.error('Spotify playback error:', message),
            onRecoveryStateChange: options.onSpotifyRecoveryStateChange,
            onStateChange: (snapshot) => handleSpotifyStateChange(snapshot, 'event'),
        });

        return spotifyPlayback;
    }

    function setSpotifyVolume(volume: number): void {
        void spotifyPlayback?.setVolume(volume).catch((error: unknown) => {
            console.error('Failed to update Spotify volume:', error);
        });
    }

    function activateSpotifyElement(): void { spotifyController().activateElement(); }
    function clearSpotifyPolling(): void { if (spotifyPollingInterval) { clearInterval(spotifyPollingInterval); spotifyPollingInterval = null; } }

    function clearSpotifyProgressTicker(): void { if (spotifyProgressInterval) { clearInterval(spotifyProgressInterval); spotifyProgressInterval = null; } }

    function clearSpotifyTimers(): void {
        clearSpotifyPolling();
        clearSpotifyProgressTicker();
    }
    function resetSpotifyTracking(): void {
        spotifyHasObservedPlayback = false;
        spotifyLastSnapshot = null;
        spotifyDisplayPositionSeconds = null;
        spotifyDisplayPositionAt = 0;
        spotifyPendingStart = null;
        spotifyWasNearEnd = false;
        spotifyEndHandled = false;
    }
    async function stopSpotifyPlayback(controller: SpotifyPlaybackController | null = spotifyPlayback, options: { destroy?: boolean } = {}): Promise<void> {
        clearSpotifyTimers();
        resetSpotifyTracking();

        if (!controller) { return; }
        try {
            await controller.pause();
        } catch (error) {
            console.error('Failed to pause Spotify playback:', error);
        } finally {
            if (options.destroy) { controller.destroy(); if (spotifyPlayback === controller) { spotifyPlayback = null; } }
        }
    }
    async function stopAllPlaybackEngines(options: { destroySpotify?: boolean } = {}): Promise<void> {
        activeEngine = null; audioRef.value?.pause();
        await stopSpotifyPlayback(spotifyPlayback, { destroy: options.destroySpotify });
    }
    function hasUnconfirmedSpotifyStart(): boolean {
        return spotifyPendingStart !== null && spotifyPendingStart.playConfirmedAt === null;
    }
    async function stopSpotifyPlaybackIfCurrentTrackCannotOwnIt(controller: SpotifyPlaybackController | null = spotifyPlayback): Promise<void> {
        if (!canTrackOwnSpotifyPlayback(audioPlayer.isPlaying.value, audioPlayer.currentTrack.value)) { await stopSpotifyPlayback(controller, { destroy: true }); }
    }
    function playbackPositionFromPlayer(): number {
        const storedPosition = audioPlayer.playbackPositionSeconds.value;
        return durationSeconds.value > 0 ? Math.min(durationSeconds.value, storedPosition) : storedPosition;
    }

    function resetDisplayedPlaybackPosition(track: AudioPlayerTrack | null): void {
        currentTime.value = 0;
        mediaDuration.value = track?.durationSeconds ?? 0;

        if (audioRef.value) {
            audioRef.value.currentTime = 0;
        }
    }

    function syncPlaybackPositionFromPlayer(): void {
        const targetPosition = playbackPositionFromPlayer();
        currentTime.value = targetPosition;

        if (audioRef.value && !isSpotifyAudioTrack(audioPlayer.currentTrack.value)) {
            audioRef.value.currentTime = targetPosition;
        }
    }

    function clampedSpotifyPositionSeconds(seconds: number, duration = durationSeconds.value): number {
        const positionSeconds = Math.max(0, seconds);

        return duration > 0 ? Math.min(duration, positionSeconds) : positionSeconds;
    }

    function setSpotifyDisplayPosition(positionSeconds: number): void {
        const nextPositionSeconds = clampedSpotifyPositionSeconds(positionSeconds);

        spotifyDisplayPositionSeconds = nextPositionSeconds;
        spotifyDisplayPositionAt = Date.now();
        currentTime.value = nextPositionSeconds;
        audioPlayer.updatePlaybackPosition(nextPositionSeconds);
    }

    function spotifyDisplayPositionNow(): number | null {
        if (spotifyDisplayPositionSeconds === null) {
            return null;
        }

        if (spotifyLastSnapshot?.paused) {
            return spotifyDisplayPositionSeconds;
        }

        return clampedSpotifyPositionSeconds(spotifyDisplayPositionSeconds + Math.max(0, (Date.now() - spotifyDisplayPositionAt) / 1000));
    }

    function syncSpotifySnapshot(snapshot: SpotifyPlaybackSnapshot, updateDisplayPosition = true): void {
        if (snapshot.durationMs > 0) {
            mediaDuration.value = snapshot.durationMs / 1000;
        }

        spotifyLastSnapshot = snapshot;

        const snapshotPositionSeconds = clampedSpotifyPositionSeconds(snapshot.positionMs / 1000, snapshot.durationMs / 1000);
        const visiblePositionSeconds = spotifyDisplayPositionNow();

        if (updateDisplayPosition && (
            snapshot.paused
            || visiblePositionSeconds === null
            || Math.abs(snapshotPositionSeconds - visiblePositionSeconds) > SPOTIFY_SNAPSHOT_CORRECTION_SECONDS
        )) {
            setSpotifyDisplayPosition(snapshotPositionSeconds);
        }

        if (isSpotifySnapshotNearEnd(snapshot, SPOTIFY_NEAR_END_WINDOW_SECONDS)) {
            spotifyWasNearEnd = true;
        }
    }

    function primeSpotifySnapshot(positionSeconds: number, paused: boolean): void {
        const track = audioPlayer.currentTrack.value;
        if (!track || !isSpotifyAudioTrack(track)) {
            return;
        }

        spotifyLastSnapshot = {
            durationMs: Math.max(0, Math.round(durationSeconds.value * 1000)),
            paused,
            positionMs: Math.max(0, Math.round(positionSeconds * 1000)),
            trackUri: track.spotifyUri ?? null,
        };
        setSpotifyDisplayPosition(positionSeconds);
        spotifyPendingStart = null;
        spotifyWasNearEnd = false;
        spotifyEndHandled = false;
    }

    function isSpotifyStartSnapshotFresh(snapshot: SpotifyPlaybackSnapshot): boolean {
        if (!spotifyPendingStart) {
            return true;
        }

        if (snapshot.trackUri !== spotifyPendingStart.uri) {
            return false;
        }

        const elapsedSeconds = Math.max(0, (Date.now() - spotifyPendingStart.requestedAt) / 1000);
        const snapshotPositionSeconds = snapshot.positionMs / 1000;
        const allowedPositionSeconds = spotifyPendingStart.positionSeconds
            + elapsedSeconds
            + SPOTIFY_START_POSITION_TOLERANCE_SECONDS;

        return snapshotPositionSeconds <= allowedPositionSeconds
            && snapshotPositionSeconds >= spotifyPendingStart.positionSeconds - SPOTIFY_POSITION_RESET_SECONDS;
    }

    function isSpotifyPollStartAdvanced(snapshot: SpotifyPlaybackSnapshot): boolean {
        return Boolean(spotifyPendingStart
            && snapshot.positionMs / 1000 >= spotifyPendingStart.positionSeconds + SPOTIFY_POLL_START_ADVANCE_SECONDS);
    }

    function shouldIgnoreSpotifyStartSnapshot(snapshot: SpotifyPlaybackSnapshot): boolean {
        const pendingStart = spotifyPendingStart;
        if (!pendingStart) {
            return false;
        }

        if (
            pendingStart.observedFreshPlaybackAt !== null
            && (Date.now() - pendingStart.observedFreshPlaybackAt) / 1000 > SPOTIFY_START_STALE_GUARD_SECONDS
        ) {
            spotifyPendingStart = null;
            return false;
        }

        if (pendingStart.playConfirmedAt !== null
            && snapshot.paused
            && audioPlayer.isPlaying.value
            && (Date.now() - pendingStart.playConfirmedAt) / 1000 <= SPOTIFY_START_STALE_GUARD_SECONDS) {
            return true;
        }

        if (isSpotifyStartSnapshotFresh(snapshot)) {
            return false;
        }

        if (pendingStart.observedFreshPlaybackAt !== null && !snapshot.paused) {
            return false;
        }

        if (!pendingStart.correctedStalePosition) {
            spotifyPendingStart = { ...pendingStart, correctedStalePosition: true };
            void spotifyController().seek(pendingStart.positionSeconds).catch((error: unknown) => {
                console.error('Failed to correct stale Spotify playback position:', error);
            });
        }

        return true;
    }

    function remainingSpotifySeconds(snapshot: SpotifyPlaybackSnapshot): number | null {
        return snapshot.durationMs <= 0 ? null : Math.max(0, (snapshot.durationMs - snapshot.positionMs) / 1000);
    }

    function isSpotifySnapshotNearEnd(snapshot: SpotifyPlaybackSnapshot, seconds: number): boolean {
        const remainingSeconds = remainingSpotifySeconds(snapshot);

        return remainingSeconds !== null && remainingSeconds <= seconds;
    }

    function hasSpotifyPositionReset(snapshot: SpotifyPlaybackSnapshot): boolean {
        return spotifyWasNearEnd && snapshot.positionMs / 1000 <= SPOTIFY_POSITION_RESET_SECONDS;
    }

    function hasSpotifyEnded(snapshot: SpotifyPlaybackSnapshot | null): boolean {
        if (!spotifyHasObservedPlayback || spotifyEndHandled) {
            return false;
        }

        if (!snapshot) {
            return spotifyWasNearEnd;
        }

        return hasSpotifyPositionReset(snapshot) || (snapshot.paused && isSpotifySnapshotNearEnd(snapshot, SPOTIFY_ENDED_TOLERANCE_SECONDS));
    }

    function finishSpotifyPlaybackIfEnded(snapshot: SpotifyPlaybackSnapshot | null): boolean {
        if (!hasSpotifyEnded(snapshot)) {
            return false;
        }

        spotifyEndHandled = true;
        clearSpotifyTimers();
        handleEnded();

        return true;
    }

    function startSpotifyProgressTicker(token: number): void {
        clearSpotifyProgressTicker();
        spotifyProgressInterval = setInterval(() => {
            if (token !== playbackToken || activeEngine !== 'spotify' || !isPlaybackOwner()) {
                clearSpotifyProgressTicker();
                return;
            }

            const snapshot = spotifyLastSnapshot;
            if (!snapshot || snapshot.paused) {
                return;
            }

            const estimatedSeconds = spotifyDisplayPositionNow();
            if (estimatedSeconds === null) {
                return;
            }

            setSpotifyDisplayPosition(estimatedSeconds);

            if (snapshot.durationMs > 0 && (snapshot.durationMs / 1000) - estimatedSeconds <= SPOTIFY_NEAR_END_WINDOW_SECONDS) {
                spotifyWasNearEnd = true;
            }

            if (snapshot.durationMs > 0 && estimatedSeconds >= snapshot.durationMs / 1000) {
                finishSpotifyPlaybackIfEnded({
                    ...snapshot,
                    paused: true,
                    positionMs: snapshot.durationMs,
                });
            }
        }, SPOTIFY_PROGRESS_TICK_INTERVAL_MS);
    }

    function handleSpotifyStateChange(snapshot: SpotifyPlaybackSnapshot | null, source: 'event' | 'poll' = 'event'): void {
        if (!isPlaybackOwner()) { return; }
        const track = audioPlayer.currentTrack.value;
        if (activeEngine !== 'spotify' || !track || !isSpotifyAudioTrack(track)) {
            return;
        }

        if (!snapshot) {
            finishSpotifyPlaybackIfEnded(null);
            return;
        }

        if (snapshot.trackUri !== track.spotifyUri) {
            finishSpotifyPlaybackIfEnded(snapshot);
            return;
        }

        if (shouldIgnoreSpotifyStartSnapshot(snapshot)) {
            return;
        }

        const shouldStartFromPoll = source === 'poll' && !spotifyHasObservedPlayback && !snapshot.paused && isSpotifyPollStartAdvanced(snapshot);
        const shouldUpdateDisplay = source === 'event'
            || spotifyHasObservedPlayback
            || snapshot.paused
            || shouldStartFromPoll;

        syncSpotifySnapshot(snapshot, shouldUpdateDisplay);

        if (!snapshot.paused && (source === 'event' || shouldStartFromPoll || spotifyHasObservedPlayback)) {
            spotifyHasObservedPlayback = true;
            if (spotifyPendingStart?.observedFreshPlaybackAt === null) {
                spotifyPendingStart = { ...spotifyPendingStart, observedFreshPlaybackAt: Date.now() };
            }
            if (source === 'event' || shouldStartFromPoll || spotifyDisplayPositionSeconds !== null) {
                startSpotifyProgressTicker(playbackToken);
            }
        } else {
            clearSpotifyProgressTicker();
        }

        if (finishSpotifyPlaybackIfEnded(snapshot)) {
            return;
        }

        if (spotifyHasObservedPlayback && snapshot.paused && audioPlayer.isPlaying.value) {
            audioPlayer.pause();
        }
    }

    function startSpotifyPolling(token: number): void {
        clearSpotifyPolling();
        spotifyPollingInterval = setInterval(() => {
            if (token !== playbackToken || activeEngine !== 'spotify' || !isPlaybackOwner()) {
                clearSpotifyTimers();
                return;
            }

            void spotifyController().currentState()
                .then((snapshot) => {
                    if (token === playbackToken) {
                        handleSpotifyStateChange(snapshot, 'poll');
                    }
                })
                .catch((error: unknown) => {
                    if (token === playbackToken) {
                        console.error('Failed to poll Spotify playback state:', error);
                    }
                });
        }, SPOTIFY_POLL_INTERVAL_MS);
    }

    async function attemptNativePlay(token: number): Promise<void> {
        await nextTick();

        const track = audioPlayer.currentTrack.value;
        if (token !== playbackToken || !isPlaybackOwner() || !audioRef.value || !track || isSpotifyAudioTrack(track)) {
            return;
        }

        if (currentTime.value > 0) {
            audioRef.value.currentTime = currentTime.value;
        }

        const playResult = audioRef.value.play();
        void (playResult as Promise<void> | undefined)?.catch(() => {
            if (token === playbackToken) {
                audioPlayer.pause();
            }
        });
    }

    async function startCurrentPlayback(): Promise<void> {
        const token = ++playbackToken;
        const track = audioPlayer.currentTrack.value;
        if (!isPlaybackOwner()) { await stopAllPlaybackEngines({ destroySpotify: true }); return; }
        if (track?.id !== displayedTrackId) { resetDisplayedPlaybackPosition(track); }
        displayedTrackId = track?.id ?? null;

        mediaDuration.value = track?.durationSeconds ?? 0;
        syncPlaybackPositionFromPlayer();

        if (!track || !audioPlayer.isPlaying.value) {
            await stopAllPlaybackEngines({ destroySpotify: hasUnconfirmedSpotifyStart() });
            return;
        }

        if (activeEngine === 'spotify' && isSpotifyAudioTrack(track)) { clearSpotifyTimers(); resetSpotifyTracking(); }
        else { await stopAllPlaybackEngines({ destroySpotify: hasUnconfirmedSpotifyStart() }); }

        if (token !== playbackToken) {
            return;
        }

        if (isSpotifyAudioTrack(track)) {
            activeEngine = 'spotify';
            const spotifyUri = track.spotifyUri!;
            const spotifyPlaybackController = spotifyController();
            const startPositionSeconds = playbackPositionFromPlayer();
            const isCurrentSpotifyStart = (): boolean => token === playbackToken
                && activeEngine === 'spotify'
                && audioPlayer.currentTrack.value?.spotifyUri === spotifyUri;

            spotifyPendingStart = {
                correctedStalePosition: false,
                observedFreshPlaybackAt: null,
                playConfirmedAt: null,
                positionSeconds: startPositionSeconds,
                requestedAt: Date.now(),
                uri: spotifyUri,
            };

            try {
                const confirmedSnapshot = await spotifyPlaybackController.play(spotifyUri, startPositionSeconds, {
                    shouldContinue: isCurrentSpotifyStart,
                });
                if (!isCurrentSpotifyStart()) {
                    await stopSpotifyPlaybackIfCurrentTrackCannotOwnIt(spotifyPlaybackController);
                    return;
                }

                if (spotifyPendingStart?.playConfirmedAt === null) {
                    spotifyPendingStart = { ...spotifyPendingStart, playConfirmedAt: Date.now() };
                }
                const playConfirmationSnapshot = spotifyStartConfirmationSnapshot(confirmedSnapshot, durationSeconds.value, startPositionSeconds, spotifyUri);
                const visiblePositionSeconds = spotifyDisplayPositionNow();
                const playConfirmationPositionSeconds = playConfirmationSnapshot.positionMs / 1000;

                if (!spotifyHasObservedPlayback
                    || visiblePositionSeconds === null
                    || playConfirmationSnapshot.paused
                    || playConfirmationPositionSeconds >= visiblePositionSeconds - SPOTIFY_SNAPSHOT_CORRECTION_SECONDS) {
                    syncSpotifySnapshot(playConfirmationSnapshot);
                }
                spotifyHasObservedPlayback = true;
                startSpotifyProgressTicker(token);
                startSpotifyPolling(token);
            } catch (error) {
                if (!isCurrentSpotifyStart() || isSpotifyPlaybackSuperseded(error)) {
                    await stopSpotifyPlaybackIfCurrentTrackCannotOwnIt(spotifyPlaybackController);
                    return;
                }

                if (isSpotifyPlaybackAuthenticationError(error)) {
                    options.onSpotifyAuthenticationError?.(error.message);
                    audioPlayer.pause();
                    return;
                }

                console.error('Failed to start Spotify playback:', error);
                options.onSpotifyPlaybackError?.();
                audioPlayer.pause();
            }

            return;
        }

        activeEngine = 'local';
        await attemptNativePlay(token);
    }

    function handleLoadedMetadata(): void {
        mediaDuration.value = audioRef.value?.duration && Number.isFinite(audioRef.value.duration)
            ? audioRef.value.duration
            : audioPlayer.currentTrack.value?.durationSeconds ?? 0;
        syncPlaybackPositionFromPlayer();
    }

    function handleTimeUpdate(): void {
        if (!isPlaybackOwner()) { return; }
        currentTime.value = audioRef.value?.currentTime ?? 0;
        audioPlayer.updatePlaybackPosition(currentTime.value);
    }

    function handleSeek(event: Event): void {
        if (!isPlaybackOwner() || !(event.target instanceof HTMLInputElement) || durationSeconds.value <= 0 || !audioPlayer.currentTrack.value) {
            return;
        }

        const targetTime = Math.min(durationSeconds.value, Math.max(0, event.target.valueAsNumber));
        currentTime.value = targetTime;

        if (isSpotifyAudioTrack(audioPlayer.currentTrack.value)) {
            primeSpotifySnapshot(targetTime, !audioPlayer.isPlaying.value);
            void spotifyController().seek(targetTime).catch((error: unknown) => {
                console.error('Failed to seek Spotify playback:', error);
            });
        } else if (audioRef.value) {
            audioRef.value.currentTime = targetTime;
        }

        audioPlayer.updatePlaybackPosition(targetTime);
    }

    function restartCurrentTrackPosition(): void {
        currentTime.value = 0;
        audioPlayer.updatePlaybackPosition(0);

        if (audioRef.value) {
            audioRef.value.currentTime = 0;
        }
    }

    function handleEnded(): void {
        if (!isPlaybackOwner()) { return; }
        const endedTrackId = audioPlayer.currentTrackId.value;
        if (endedTrackId !== null) { options.onTrackEnded?.(endedTrackId); }

        if (audioPlayer.repeatMode.value === 'one') {
            restartCurrentTrackPosition();
            audioPlayer.restartCurrentTrack();
            void startCurrentPlayback();
            return;
        }

        audioPlayer.playNext();

        if (audioPlayer.isPlaying.value && audioPlayer.currentTrackId.value === endedTrackId) {
            restartCurrentTrackPosition();
            void startCurrentPlayback();
        }
    }

    function teardown(): void {
        unregisterSpotifyPlaybackLifecycle();
        void stopAllPlaybackEngines({ destroySpotify: true });
    }

    const unregisterSpotifyPlaybackLifecycle = registerSpotifyPlaybackLifecycle({
        onPageHide: () => void stopSpotifyPlayback(spotifyPlayback, { destroy: true }),
        onTabHidden: () => spotifyPlayback?.markDeviceRegistrationStale(),
    });

    return {
        activateSpotifyElement,
        handleEnded,
        handleLoadedMetadata,
        handleSeek,
        handleTimeUpdate,
        nativeAudioSource,
        setSpotifyVolume,
        startCurrentPlayback,
        teardown,
    };
}
