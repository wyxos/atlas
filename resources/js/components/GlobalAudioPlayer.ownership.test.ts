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
            post: vi.fn((url: string, data?: Record<string, unknown>) => {
                if (url === '/api/audio/details') {
                    return Promise.resolve({ data: { items: [] } });
                }

                return Promise.resolve({
                    data: {
                        ...session,
                        ...data,
                        version: session.version + 1,
                        lease_token: 'owned-lease',
                        owner_instance_id: data?.instance_id ?? window.sessionStorage.getItem('atlas:audioPlaybackInstanceId'),
                        owner_label: 'Windows Chrome',
                        server_recorded_at_ms: Date.now(),
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

    it('claims ownership when local playback starts from another surface', async () => {
        installPlaybackSessionMocks(playbackSession({
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
        }));
        vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue(undefined);

        mount(GlobalAudioPlayer);
        await flushPromises();

        vi.mocked(window.axios.post).mockClear();

        useGlobalAudioPlayer().queueAndPlay([testTrack(51, {
            title: 'Local Track',
            duration: '3:00',
            durationSeconds: 180,
        })], 51, { queueLabel: 'All audio' });
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith('/api/audio/playback-session/claim', expect.objectContaining({
            current_track: expect.objectContaining({ id: 51 }),
            queue_label: 'All audio',
            state: 'playing',
        }));
    });

    it('refreshes availability before claiming local playback started after app globals attach', async () => {
        vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue(undefined);

        mount(GlobalAudioPlayer);
        await flushPromises();

        installPlaybackSessionMocks(playbackSession({
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
        }));

        useGlobalAudioPlayer().queueAndPlay([testTrack(61, {
            title: 'Late Globals Track',
            duration: '3:00',
            durationSeconds: 180,
        })], 61, { queueLabel: 'All audio' });
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith('/api/audio/playback-session/claim', expect.objectContaining({
            current_track: expect.objectContaining({ id: 61 }),
            queue_label: 'All audio',
            state: 'playing',
        }));
    });

    it('pushes owner metadata duration updates to the playback session', async () => {
        installPlaybackSessionMocks(playbackSession({
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
        }));
        vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue(undefined);

        const wrapper = mount(GlobalAudioPlayer);
        await flushPromises();

        useGlobalAudioPlayer().queueAndPlay([testTrack(71, {
            title: 'Duration Track',
            duration: '6:12',
            durationSeconds: 372,
        })], 71, { queueLabel: 'All audio' });
        await flushPromises();

        vi.mocked(window.axios.post).mockClear();
        Object.defineProperty(wrapper.get('audio').element, 'duration', {
            configurable: true,
            value: 91,
        });

        await wrapper.get('audio').trigger('loadedmetadata');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith('/api/audio/playback-session/update', expect.objectContaining({
            current_track: expect.objectContaining({ id: 71 }),
            duration_seconds: 91,
            state: 'playing',
        }));
    });
});
