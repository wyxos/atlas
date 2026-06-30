import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent } from 'vue';
import {
    resetAudioPlaybackSessionForTests,
    useAudioPlaybackSession,
    type AudioPlaybackSession,
} from './useAudioPlaybackSession';
import type { AudioPlayerTrack } from './useGlobalAudioPlayer';

type EchoChannel = {
    listen: ReturnType<typeof vi.fn>;
};

const listeners = new Map<string, (payload: unknown) => void>();

function testTrack(id: number, overrides: Partial<AudioPlayerTrack> = {}): AudioPlayerTrack {
    return {
        id,
        title: `Track ${id}`,
        artists: `Artist ${id}`,
        album: `Album ${id}`,
        coverUrl: null,
        duration: '3:00',
        durationSeconds: 180,
        reaction: null,
        blacklistedAt: null,
        previewedCount: 0,
        seenCount: 0,
        playbackUrl: `/api/files/${id}/serve`,
        ...overrides,
    };
}

function session(overrides: Partial<AudioPlaybackSession> = {}): AudioPlaybackSession {
    return {
        version: 1,
        lease_token: 'lease-1',
        owner_instance_id: 'mac-tab',
        owner_label: 'Mac Safari',
        state: 'playing',
        source: 'local',
        current_track: testTrack(41),
        queue_label: 'All audio',
        position_seconds: 12,
        duration_seconds: 180,
        spotify_device_id: null,
        server_recorded_at_ms: Date.now(),
        ...overrides,
    };
}

function installUserMeta(): void {
    const meta = document.createElement('meta');
    meta.name = 'user-id';
    meta.content = '42';
    document.head.appendChild(meta);
}

function installEcho(): { channel: EchoChannel; leave: ReturnType<typeof vi.fn>; privateChannel: ReturnType<typeof vi.fn> } {
    listeners.clear();
    const channel: EchoChannel = {
        listen: vi.fn((event: string, callback: (payload: unknown) => void) => {
            listeners.set(event, callback);

            return channel;
        }),
    };
    const privateChannel = vi.fn(() => channel);
    const leave = vi.fn();

    window.Echo = {
        private: privateChannel,
        leave,
    } as unknown as typeof window.Echo;

    return { channel, leave, privateChannel };
}

function mountHarness() {
    let playbackSession!: ReturnType<typeof useAudioPlaybackSession>;
    const wrapper = mount(defineComponent({
        setup() {
            playbackSession = useAudioPlaybackSession();

            return () => null;
        },
    }));

    return { playbackSession, wrapper };
}

describe('useAudioPlaybackSession', () => {
    afterEach(() => {
        resetAudioPlaybackSessionForTests();
        document.head.querySelectorAll('meta[name="user-id"]').forEach((meta) => meta.remove());
        delete window.Echo;
        delete (window as unknown as { axios?: unknown }).axios;
        window.sessionStorage.clear();
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('subscribes to the private user channel and mirrors remote playback progress', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-06-25T08:00:00.000Z'));
        installUserMeta();
        const echo = installEcho();
        const remoteSession = session({ server_recorded_at_ms: Date.now() });
        Object.assign(window, {
            axios: {
                get: vi.fn().mockResolvedValue({ data: remoteSession }),
                post: vi.fn(),
            },
        });

        const { playbackSession, wrapper } = mountHarness();
        await flushPromises();

        expect(window.axios.get).toHaveBeenCalledWith('/api/audio/playback-session');
        expect(echo.privateChannel).toHaveBeenCalledWith('App.Models.User.42');
        expect(echo.channel.listen).toHaveBeenCalledWith('.AudioPlaybackSessionUpdated', expect.any(Function));
        expect(playbackSession.role.value).toBe('observer');
        expect(playbackSession.remotePositionSeconds.value).toBe(12);

        await vi.advanceTimersByTimeAsync(2500);

        expect(playbackSession.remotePositionSeconds.value).toBeCloseTo(14.5, 1);

        listeners.get('.AudioPlaybackSessionUpdated')?.(session({
            version: 0,
            current_track: testTrack(99),
            position_seconds: 99,
        }));

        expect(playbackSession.session.value.current_track?.id).toBe(41);
        expect(playbackSession.remotePositionSeconds.value).toBeCloseTo(14.5, 1);

        wrapper.unmount();
        expect(echo.leave).toHaveBeenCalledWith('App.Models.User.42');
    });

    it('claims ownership with this tab instance and heartbeats the owner lease', async () => {
        vi.useFakeTimers();
        installUserMeta();
        installEcho();
        const claimed = session({
            lease_token: 'lease-owned',
            owner_instance_id: 'atlas-tab-123',
            owner_label: 'Windows Chrome',
            position_seconds: 0,
        });
        Object.assign(window, {
            axios: {
                get: vi.fn().mockResolvedValue({ data: session({ version: 0, lease_token: null, owner_instance_id: null }) }),
                post: vi.fn()
                    .mockResolvedValueOnce({ data: claimed })
                    .mockResolvedValueOnce({ data: { ...claimed, version: 2, position_seconds: 5 } }),
            },
        });
        window.sessionStorage.setItem('atlas:audioPlaybackInstanceId', 'atlas-tab-123');

        const { playbackSession } = mountHarness();
        playbackSession.setSnapshotProvider(() => ({
            state: 'playing',
            source: 'local',
            current_track: testTrack(41),
            queue_label: 'All audio',
            position_seconds: 5,
            duration_seconds: 180,
            spotify_device_id: null,
        }));
        await flushPromises();

        await playbackSession.claimOwnership({
            state: 'playing',
            source: 'local',
            current_track: testTrack(41),
            queue_label: 'All audio',
            position_seconds: 0,
            duration_seconds: 180,
            spotify_device_id: null,
        });

        expect(window.axios.post).toHaveBeenCalledWith('/api/audio/playback-session/claim', expect.objectContaining({
            current_track: expect.objectContaining({ id: 41 }),
            instance_id: 'atlas-tab-123',
            owner_label: expect.any(String),
            state: 'playing',
        }));
        expect(playbackSession.role.value).toBe('owner');
        expect(playbackSession.canOutputAudio.value).toBe(true);

        await vi.advanceTimersByTimeAsync(5000);

        expect(window.axios.post).toHaveBeenCalledWith('/api/audio/playback-session/heartbeat', expect.objectContaining({
            instance_id: 'atlas-tab-123',
            lease_token: 'lease-owned',
            position_seconds: 5,
        }));
    });
});
