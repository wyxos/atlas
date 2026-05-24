import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import GlobalAudioPlayer from './GlobalAudioPlayer.vue';
import { useGlobalAudioPlayer, type AudioPlayerTrack } from '@/composables/useGlobalAudioPlayer';

type CapturedActionHandler = ((details: MediaSessionActionDetails) => void) | null;

class MockMediaMetadata {
    album = '';

    artist = '';

    artwork: MediaImage[] = [];

    title = '';

    constructor(init: MediaMetadataInit) {
        this.album = init.album ?? '';
        this.artist = init.artist ?? '';
        this.artwork = [...(init.artwork ?? [])];
        this.title = init.title ?? '';
    }
}

function testTrack(id: number, overrides: Partial<AudioPlayerTrack> = {}): AudioPlayerTrack {
    return {
        id,
        title: `Track ${id}`,
        artists: '',
        album: '',
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

function installMediaSessionMock() {
    const actionHandlers = new Map<MediaSessionAction, CapturedActionHandler>();
    const mediaSession = {
        metadata: null as MockMediaMetadata | null,
        playbackState: 'none' as MediaSessionPlaybackState,
        setActionHandler: vi.fn((action: MediaSessionAction, handler: CapturedActionHandler) => {
            actionHandlers.set(action, handler);
        }),
        setPositionState: vi.fn(),
    };

    Object.defineProperty(navigator, 'mediaSession', {
        configurable: true,
        value: mediaSession,
    });
    vi.stubGlobal('MediaMetadata', MockMediaMetadata);

    return { actionHandlers, mediaSession };
}

describe('GlobalAudioPlayer media session integration', () => {
    afterEach(() => {
        useGlobalAudioPlayer().clear();
        Object.defineProperty(navigator, 'mediaSession', {
            configurable: true,
            value: undefined,
        });
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('publishes lock-screen metadata, progress, and queue transport actions', async () => {
        const { actionHandlers, mediaSession } = installMediaSessionMock();
        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(41, {
                title: 'Event Horizon',
                artists: 'Signal Park',
                album: 'Playback Notes',
                coverUrl: '/api/files/41/poster',
                duration: '1:31',
                durationSeconds: 91,
            }),
            testTrack(42),
        ], 41);

        mount(GlobalAudioPlayer);
        await flushPromises();

        expect(mediaSession.metadata).toMatchObject({
            album: 'Playback Notes',
            artist: 'Signal Park',
            title: 'Event Horizon',
        });
        expect(mediaSession.metadata?.artwork).toEqual([{
            src: new URL('/api/files/41/poster', window.location.origin).toString(),
            sizes: '512x512',
        }]);
        expect(mediaSession.playbackState).toBe('playing');
        expect(mediaSession.setPositionState).toHaveBeenLastCalledWith({
            duration: 91,
            playbackRate: 1,
            position: 0,
        });

        actionHandlers.get('nexttrack')?.({ action: 'nexttrack' });
        await flushPromises();

        expect(player.currentTrackId.value).toBe(42);

        actionHandlers.get('previoustrack')?.({ action: 'previoustrack' });
        await flushPromises();

        expect(player.currentTrackId.value).toBe(41);

        actionHandlers.get('pause')?.({ action: 'pause' });
        await flushPromises();

        expect(player.isPlaying.value).toBe(false);
        expect(mediaSession.playbackState).toBe('paused');

        actionHandlers.get('play')?.({ action: 'play' });
        await flushPromises();

        expect(player.isPlaying.value).toBe(true);
        expect(mediaSession.playbackState).toBe('playing');
    });

    it('uses a music-note artwork fallback when the current track has no cover', async () => {
        const { mediaSession } = installMediaSessionMock();
        const player = useGlobalAudioPlayer();
        player.queueAndPlay([testTrack(41, { coverUrl: null })], 41);

        mount(GlobalAudioPlayer);
        await flushPromises();

        expect(mediaSession.metadata?.artwork[0]).toMatchObject({
            sizes: '512x512',
            type: 'image/svg+xml',
        });
        expect(mediaSession.metadata?.artwork[0]?.src).toContain('data:image/svg+xml');
    });

    it('updates the lock-screen position state from player progress', async () => {
        const { mediaSession } = installMediaSessionMock();
        const player = useGlobalAudioPlayer();
        player.queueAndPlay([testTrack(41, { duration: '1:31', durationSeconds: 91 })], 41);

        mount(GlobalAudioPlayer);
        await flushPromises();

        player.updatePlaybackPosition(22.4);
        await flushPromises();

        expect(mediaSession.setPositionState).toHaveBeenLastCalledWith({
            duration: 91,
            playbackRate: 1,
            position: 22.4,
        });
    });
});
