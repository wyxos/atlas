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
                            source: 'Local',
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

    it('keeps partial queue details visible when duration is missing', async () => {
        const post = vi.fn()
            .mockResolvedValue({
                data: {
                    items: [
                        {
                            id: 743,
                            title: 'Atlas Seed Track 0743',
                            source: 'Local',
                            artists: ['Mira Vale'],
                            albums: [],
                            cover_url: null,
                            duration_seconds: null,
                            reaction: null,
                            blacklisted_at: null,
                            previewed_count: 0,
                            seen_count: 0,
                        },
                    ],
                },
            });
        Object.assign(window, {
            axios: { post },
        });

        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(743, {
                title: 'Audio #743',
                artists: 'Loading metadata...',
                album: 'Unknown album',
                duration: '--:--',
                durationSeconds: null,
            }),
        ], 743);

        scope = effectScope();
        const queueDetails = scope.run(() => useAudioQueueDetails(player));
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(1);
        expect(player.queue.value[0]).toMatchObject({
            id: 743,
            title: 'Atlas Seed Track 0743',
            artists: 'Mira Vale',
            duration: '--:--',
        });

        await queueDetails?.handleQueueVisibleItemsChange(player.queue.value);
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(1);
    });

    it('refreshes persisted current track details even when metadata looks complete', async () => {
        const post = vi.fn()
            .mockResolvedValue({
                data: {
                    items: [
                        {
                            id: 745,
                            title: 'The Theme From GTO',
                            source: 'Local',
                            artists: ['本間勇輔'],
                            albums: ['TVアニメーション GTO オリジナルサウンドトラック'],
                            cover_url: '/api/audio/album-covers/4460',
                            duration_seconds: 201,
                            reaction: null,
                            blacklisted_at: null,
                            previewed_count: 0,
                            seen_count: 0,
                        },
                    ],
                },
            });
        Object.assign(window, {
            axios: { post },
        });

        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(745, {
                title: 'The Theme From GTO',
                artists: 'Yusuke Honma',
                album: 'GTO TV Animation Original Soundtrack',
                coverUrl: null,
                duration: '3:21',
                durationSeconds: 201,
            }),
        ], 745);

        scope = effectScope();
        scope.run(() => useAudioQueueDetails(player));
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/api/audio/details', {
            ids: [745],
        });
        expect(player.queue.value[0]).toMatchObject({
            id: 745,
            artists: '本間勇輔',
            album: 'TVアニメーション GTO オリジナルサウンドトラック',
            coverUrl: '/api/audio/album-covers/4460',
        });
    });

    it('fetches details again when the same queue ids are replaced by placeholders', async () => {
        const post = vi.fn()
            .mockResolvedValue({
                data: {
                    items: [
                        {
                            id: 744,
                            title: 'Atlas Seed Track 0744',
                            source: 'Local',
                            artists: ['Mira Vale'],
                            albums: ['Late Indexes'],
                            cover_url: '/api/files/744/poster',
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
        const placeholderTrack = testTrack(744, {
            title: 'Audio #744',
            artists: 'Loading metadata...',
            album: 'Unknown album',
            duration: '--:--',
            durationSeconds: null,
        });
        player.queueAndPlay([placeholderTrack], 744);

        scope = effectScope();
        const queueDetails = scope.run(() => useAudioQueueDetails(player));
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(1);
        expect(player.queue.value[0]).toMatchObject({
            title: 'Atlas Seed Track 0744',
        });

        player.queueAndPlay([placeholderTrack], 744);
        await queueDetails?.handleQueueVisibleItemsChange(player.queue.value);
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(2);
        expect(player.queue.value[0]).toMatchObject({
            title: 'Atlas Seed Track 0744',
        });
    });
});
