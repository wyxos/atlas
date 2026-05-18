import { afterEach, describe, expect, it, vi } from 'vitest';
import type { AudioPlayerTrack } from './useGlobalAudioPlayer';

const AUDIO_PLAYER_STATE_STORAGE_KEY = 'atlas:audioPlayerState';

function testTrack(id: number, overrides: Partial<AudioPlayerTrack> = {}): AudioPlayerTrack {
    return {
        id,
        title: `Track ${id}`,
        artists: `Artist ${id}`,
        album: `Album ${id}`,
        coverUrl: null,
        duration: `0:${id.toString().padStart(2, '0')}`,
        durationSeconds: id,
        reaction: null,
        blacklistedAt: null,
        previewedCount: 0,
        seenCount: 0,
        playbackUrl: `/api/files/${id}/serve`,
        ...overrides,
    };
}

afterEach(async () => {
    const { useGlobalAudioPlayer } = await import('./useGlobalAudioPlayer');
    useGlobalAudioPlayer().clear();
    window.sessionStorage.removeItem(AUDIO_PLAYER_STATE_STORAGE_KEY);
    vi.restoreAllMocks();
    vi.resetModules();
});

describe('useGlobalAudioPlayer', () => {
    it('restores the copied queue, playlist label, current track, playback position, and order after reload', async () => {
        vi.resetModules();
        window.sessionStorage.removeItem(AUDIO_PLAYER_STATE_STORAGE_KEY);

        const { useGlobalAudioPlayer } = await import('./useGlobalAudioPlayer');
        const player = useGlobalAudioPlayer();

        player.queueAndPlay([
            testTrack(3),
            testTrack(1, { title: 'Playing Track' }),
            testTrack(2),
        ], 1, { queueLabel: 'Favorites' });
        player.setRepeatMode('all');
        player.updatePlaybackPosition(72.26);

        expect(JSON.parse(window.sessionStorage.getItem(AUDIO_PLAYER_STATE_STORAGE_KEY) ?? '{}')).toMatchObject({
            currentTrackId: 1,
            isShuffleEnabled: false,
            isPlaying: true,
            playbackPositionSeconds: 72.3,
            queueLabel: 'Favorites',
            repeatMode: 'all',
            unshuffledQueueIds: [3, 1, 2],
        });

        vi.resetModules();

        const { useGlobalAudioPlayer: useRestoredGlobalAudioPlayer } = await import('./useGlobalAudioPlayer');
        const restoredPlayer = useRestoredGlobalAudioPlayer();

        expect(restoredPlayer.queue.value.map((track) => track.id)).toEqual([3, 1, 2]);
        expect(restoredPlayer.queueLabel.value).toBe('Favorites');
        expect(restoredPlayer.currentTrackId.value).toBe(1);
        expect(restoredPlayer.currentTrack.value?.title).toBe('Playing Track');
        expect(restoredPlayer.isShuffleEnabled.value).toBe(false);
        expect(restoredPlayer.playbackPositionSeconds.value).toBe(72.3);
        expect(restoredPlayer.repeatMode.value).toBe('all');
        expect(restoredPlayer.isPlaying.value).toBe(true);
    });

    it('restores shuffled mode and can toggle back to the original queue order after reload', async () => {
        vi.resetModules();
        window.sessionStorage.removeItem(AUDIO_PLAYER_STATE_STORAGE_KEY);

        const { useGlobalAudioPlayer } = await import('./useGlobalAudioPlayer');
        const player = useGlobalAudioPlayer();

        player.queueAndPlay([testTrack(1), testTrack(2), testTrack(3), testTrack(4)], 1, { queueLabel: 'Favorites' });
        vi.spyOn(Math, 'random').mockReturnValue(0);

        player.shuffleQueue();

        expect(player.isShuffleEnabled.value).toBe(true);
        expect(player.queue.value.map((track) => track.id)).toEqual([1, 3, 4, 2]);
        expect(JSON.parse(window.sessionStorage.getItem(AUDIO_PLAYER_STATE_STORAGE_KEY) ?? '{}')).toMatchObject({
            isShuffleEnabled: true,
            queue: [
                expect.objectContaining({ id: 1 }),
                expect.objectContaining({ id: 3 }),
                expect.objectContaining({ id: 4 }),
                expect.objectContaining({ id: 2 }),
            ],
            unshuffledQueueIds: [1, 2, 3, 4],
        });

        vi.resetModules();

        const { useGlobalAudioPlayer: useRestoredGlobalAudioPlayer } = await import('./useGlobalAudioPlayer');
        const restoredPlayer = useRestoredGlobalAudioPlayer();

        expect(restoredPlayer.isShuffleEnabled.value).toBe(true);
        expect(restoredPlayer.queue.value.map((track) => track.id)).toEqual([1, 3, 4, 2]);

        restoredPlayer.shuffleQueue();

        expect(restoredPlayer.isShuffleEnabled.value).toBe(false);
        expect(restoredPlayer.queue.value.map((track) => track.id)).toEqual([1, 2, 3, 4]);
        expect(restoredPlayer.currentTrackId.value).toBe(1);
    });

    it('removes persisted player state when the queue is cleared', async () => {
        vi.resetModules();

        const { useGlobalAudioPlayer } = await import('./useGlobalAudioPlayer');
        const player = useGlobalAudioPlayer();

        player.queueTracks([testTrack(5)], 5, { queueLabel: 'All audio' });
        expect(window.sessionStorage.getItem(AUDIO_PLAYER_STATE_STORAGE_KEY)).not.toBeNull();

        player.clear();

        expect(window.sessionStorage.getItem(AUDIO_PLAYER_STATE_STORAGE_KEY)).toBeNull();
    });
});
