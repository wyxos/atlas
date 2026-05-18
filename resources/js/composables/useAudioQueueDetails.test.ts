import { effectScope, type EffectScope } from 'vue';
import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useAudioQueueDetails } from './useAudioQueueDetails';
import { useGlobalAudioPlayer, type AudioPlayerTrack } from './useGlobalAudioPlayer';

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

describe('useAudioQueueDetails', () => {
    let scope: EffectScope | null = null;

    afterEach(() => {
        scope?.stop();
        scope = null;
        useGlobalAudioPlayer().clear();
        delete (window as unknown as { axios?: unknown }).axios;
        vi.restoreAllMocks();
    });

    it('retries queue detail hydration when the API omits a requested track', async () => {
        const post = vi.fn()
            .mockResolvedValueOnce({
                data: {
                    items: [],
                },
            })
            .mockResolvedValueOnce({
                data: {
                    items: [
                        {
                            id: 742,
                            title: 'Atlas Seed Track 0742',
                            source: 'Spotify',
                            artists: ['Mira Vale'],
                            albums: ['Late Indexes'],
                            cover_url: '/api/files/742/poster',
                            duration_seconds: 154,
                            reaction: null,
                            blacklisted_at: null,
                            previewed_count: 2,
                            seen_count: 1,
                        },
                    ],
                },
            });
        Object.assign(window, {
            axios: { post },
        });

        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(742, {
                title: 'Audio #742',
                artists: 'Loading metadata...',
                album: 'Unknown album',
                duration: '--:--',
                durationSeconds: null,
            }),
        ], 742);

        scope = effectScope();
        const queueDetails = scope.run(() => useAudioQueueDetails(player));
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(1);

        await queueDetails?.handleQueueVisibleItemsChange(player.queue.value);
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(2);
        expect(post).toHaveBeenLastCalledWith('/api/audio/details', {
            ids: [742],
        });
        expect(player.queue.value[0]).toMatchObject({
            id: 742,
            title: 'Atlas Seed Track 0742',
            artists: 'Mira Vale',
            album: 'Late Indexes',
            duration: '2:34',
        });
    });
});
