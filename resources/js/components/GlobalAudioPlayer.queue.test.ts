import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import GlobalAudioPlayer from './GlobalAudioPlayer.vue';
import { useGlobalAudioPlayer, type AudioPlayerTrack } from '@/composables/useGlobalAudioPlayer';

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

function createDeferred<T>() {
    let resolve: ((value: T | PromiseLike<T>) => void) | null = null;
    const promise = new Promise<T>((innerResolve) => {
        resolve = innerResolve;
    });

    return {
        promise,
        resolve: (value: T) => {
            resolve?.(value);
        },
    };
}

describe('GlobalAudioPlayer queue', () => {
    afterEach(() => {
        useGlobalAudioPlayer().clear();
        delete (window as unknown as { axios?: unknown }).axios;
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('renders queue skeletons while copied track details are loading', async () => {
        const detailsRequest = createDeferred<{
            data: {
                items: Array<{
                    id: number;
                    title: string;
                    source: string;
                    artists: string[];
                    albums: string[];
                    cover_url: string | null;
                    duration_seconds: number;
                    reaction: null;
                    blacklisted_at: null;
                    previewed_count: number;
                    seen_count: number;
                }>;
            };
        }>();
        const post = vi.fn().mockReturnValue(detailsRequest.promise);
        Object.assign(window, {
            axios: { post },
        });
        const player = useGlobalAudioPlayer();
        player.queueTracks([
            testTrack(742, {
                title: 'Audio #742',
                artists: 'Loading metadata...',
                album: 'Unknown album',
                duration: '--:--',
                durationSeconds: null,
            }),
        ], 742);

        const wrapper = mount(GlobalAudioPlayer);

        await wrapper.get('[aria-label="Queue"]').trigger('click');
        await flushPromises();

        const queueSheet = wrapper.get('[data-test="audio-queue-sheet"]');
        expect(queueSheet.find('[data-test="audio-queue-track-loading"]').exists()).toBe(true);
        expect(queueSheet.findAll('[data-slot="skeleton"]').length).toBeGreaterThan(0);
        expect(queueSheet.text()).not.toContain('Loading metadata...');
        expect(queueSheet.text()).not.toContain('Audio #742');
        expect(queueSheet.text()).not.toContain('--:--');

        detailsRequest.resolve({
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
        await flushPromises();

        expect(queueSheet.text()).toContain('Atlas Seed Track 0742');
        expect(queueSheet.text()).toContain('Mira Vale');
        expect(queueSheet.text()).toContain('2:34');
    });

    it('shows queue metadata instead of skeletons when only duration is missing', async () => {
        const player = useGlobalAudioPlayer();
        player.queueTracks([
            testTrack(743, {
                title: 'Atlas Seed Track 0743',
                artists: 'Mira Vale',
                album: 'Unknown album',
                duration: '--:--',
                durationSeconds: null,
            }),
        ], 743);

        const wrapper = mount(GlobalAudioPlayer);

        await wrapper.get('[aria-label="Queue"]').trigger('click');
        await flushPromises();

        const queueSheet = wrapper.get('[data-test="audio-queue-sheet"]');
        expect(queueSheet.find('[data-test="audio-queue-track-loading"]').exists()).toBe(false);
        expect(queueSheet.text()).toContain('Atlas Seed Track 0743');
        expect(queueSheet.text()).toContain('Mira Vale');
        expect(queueSheet.text()).toContain('--:--');
    });

    it('scrolls the queue sheet to the current track when opened', async () => {
        const player = useGlobalAudioPlayer();
        const tracks = Array.from({ length: 120 }, (_, index) => testTrack(index + 1, {
            title: `Visible Track ${index + 1}`,
        }));
        player.queueAndPlay(tracks, 91);

        const wrapper = mount(GlobalAudioPlayer);

        await wrapper.get('[aria-label="Queue"]').trigger('click');
        await flushPromises();

        const queueSheet = wrapper.get('[data-test="audio-queue-sheet"]');
        expect(queueSheet.text()).toContain('Visible Track 91');
        expect(queueSheet.find('[aria-current="true"]').text()).toContain('Visible Track 91');
    });

    it('uses the strong active track treatment in the queue sheet', async () => {
        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(41, { title: 'Previous Track' }),
            testTrack(42, { title: 'Playing Track' }),
        ], 42);

        const wrapper = mount(GlobalAudioPlayer);

        await wrapper.get('[aria-label="Queue"]').trigger('click');
        await flushPromises();

        const playingTrack = wrapper.get('[data-test="audio-queue-track"][aria-current="true"]');
        expect(playingTrack.classes()).toContain('bg-smart-blue-600/95');
        expect(playingTrack.classes()).toContain('ring-2');
        expect(playingTrack.classes()).toContain('ring-smart-blue-100/90');
        expect(playingTrack.classes()).toContain('shadow-[inset_4px_0_0_rgb(219_238_255/0.95)]');

        player.pause();
        await flushPromises();

        const pausedTrack = wrapper.get('[data-test="audio-queue-track"][aria-current="true"]');
        expect(pausedTrack.classes()).toContain('bg-smart-blue-700/90');
        expect(pausedTrack.classes()).toContain('ring-smart-blue-100/75');
        expect(pausedTrack.classes()).toContain('shadow-[inset_4px_0_0_rgb(123_190_255/0.95)]');
    });
});
