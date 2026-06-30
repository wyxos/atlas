import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import GlobalAudioPlayer from './GlobalAudioPlayer.vue';
import { resetAudioPlaybackSessionForTests, type AudioPlaybackSession } from '@/composables/useAudioPlaybackSession';
import { useGlobalAudioPlayer, type AudioPlayerTrack } from '@/composables/useGlobalAudioPlayer';

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

function playbackSession(overrides: Partial<AudioPlaybackSession> = {}): AudioPlaybackSession {
    return {
        version: 1,
        lease_token: 'lease-1',
        owner_instance_id: 'mac-tab',
        owner_label: 'Mac Safari',
        state: 'playing',
        source: 'local',
        current_track: testTrack(41, {
            title: 'Remote Track',
            artists: 'Remote Artist',
            duration: '3:00',
            durationSeconds: 180,
        }),
        queue_label: 'Remote queue',
        position_seconds: 32,
        duration_seconds: 180,
        spotify_device_id: null,
        server_recorded_at_ms: Date.now(),
        ...overrides,
    };
}

function installPlaybackSessionMocks(session: AudioPlaybackSession): void {
    const meta = document.createElement('meta');
    meta.name = 'user-id';
    meta.content = '42';
    document.head.appendChild(meta);

    const channel = { listen: vi.fn(() => channel) };
    window.Echo = {
        private: vi.fn(() => channel),
        leave: vi.fn(),
    } as unknown as typeof window.Echo;
    Object.assign(window, {
        axios: {
            get: vi.fn().mockResolvedValue({ data: session }),
            post: vi.fn((url: string) => {
                if (url === '/api/audio/details') {
                    return Promise.resolve({ data: { items: [] } });
                }

                return Promise.resolve({
                    data: {
                        ...session,
                        version: session.version + 1,
                        lease_token: 'owned-lease',
                        owner_instance_id: window.sessionStorage.getItem('atlas:audioPlaybackInstanceId'),
                        owner_label: 'Windows Chrome',
                    },
                });
            }),
        },
    });
}

describe('GlobalAudioPlayer ownership', () => {
    afterEach(() => {
        useGlobalAudioPlayer().clear();
        resetAudioPlaybackSessionForTests();
        document.head.querySelectorAll('meta[name="user-id"]').forEach((meta) => meta.remove());
        delete (window as unknown as { axios?: unknown }).axios;
        delete window.Echo;
        window.sessionStorage.clear();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('renders remote observer playback with a takeover CTA and disabled playback controls', async () => {
        installPlaybackSessionMocks(playbackSession());
        const play = vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue(undefined);

        const wrapper = mount(GlobalAudioPlayer);
        await flushPromises();

        expect(window.axios.get).toHaveBeenCalledWith('/api/audio/playback-session');
        expect(wrapper.get('[data-test="global-audio-player-title"]').text()).toBe('Remote Track');
        expect(wrapper.get('[data-test="global-audio-player-subtitle"]').text()).toBe('Remote Artist');
        expect(wrapper.get('[data-test="audio-ownership-claim"]').text()).toContain('Play on this device');
        expect(wrapper.get('[aria-label="Playback progress"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[aria-label="Previous"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[aria-label="Next"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[aria-label="Shuffle queue"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[aria-label="Queue"]').attributes('disabled')).toBeDefined();
        play.mockClear();
        await wrapper.vm.$nextTick();
        expect(play).not.toHaveBeenCalled();

        await wrapper.get('[data-test="audio-ownership-claim"]').trigger('click');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith('/api/audio/playback-session/claim', expect.objectContaining({
            current_track: expect.objectContaining({ id: 41 }),
            state: 'playing',
        }));
    });
});
