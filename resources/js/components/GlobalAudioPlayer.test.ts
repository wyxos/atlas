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

describe('GlobalAudioPlayer', () => {
    afterEach(() => {
        useGlobalAudioPlayer().clear();
        delete (window as unknown as { axios?: unknown }).axios;
        vi.restoreAllMocks();
    });

    it('renders a custom static player surface without native audio controls', () => {
        const wrapper = mount(GlobalAudioPlayer);
        const playerText = wrapper.get('[data-test="global-audio-player"]').text();
        const playButton = wrapper.get('[aria-label="Play"]');
        const shuffleButton = wrapper.get('[aria-label="Shuffle queue"]');

        expect(wrapper.find('audio').exists()).toBe(true);
        expect(wrapper.get('audio').attributes('controls')).toBeUndefined();
        expect(wrapper.get('audio').classes()).toContain('hidden');
        expect(playerText).not.toContain('No audio selected');
        expect(playerText).not.toContain('Atlas player');
        expect(playButton.classes()).toContain('size-14');
        expect(playButton.classes()).toContain('2xl:size-16');
        expect(playButton.classes()).toContain('enabled:hover:bg-smart-blue-500');
        expect(playButton.classes()).toContain('enabled:hover:scale-105');
        expect(playButton.classes()).toContain('disabled:bg-smart-blue-900/60');
        expect(playButton.classes()).toContain('disabled:cursor-not-allowed');
        expect(playButton.classes()).not.toContain('hover:bg-smart-blue-500');
        expect(playButton.classes()).not.toContain('hover:scale-105');
        expect(playButton.attributes('disabled')).toBeDefined();
        expect(playButton.attributes('aria-disabled')).toBe('true');
        expect(shuffleButton.classes()).toContain('player-control-button');
        expect(shuffleButton.classes()).toContain('enabled:hover:bg-smart-blue-700');
        expect(shuffleButton.classes()).toContain('enabled:hover:text-white');
        expect(shuffleButton.classes()).toContain('disabled:text-blue-slate-500');
        expect(shuffleButton.classes()).toContain('disabled:cursor-not-allowed');
        expect(shuffleButton.classes()).not.toContain('hover:bg-smart-blue-700');
        expect(shuffleButton.classes()).not.toContain('hover:text-white');
        expect(shuffleButton.attributes('disabled')).toBeDefined();
        expect(shuffleButton.attributes('aria-disabled')).toBe('true');
        expect(wrapper.get('[aria-label="Playback progress"]').classes()).toContain('h-2');
        expect(wrapper.get('[aria-label="Playback progress"]').classes()).toContain('2xl:h-3');
        expect(wrapper.get('[aria-label="Playback progress"]').classes()).not.toContain('top-0');
        expect(wrapper.get('[aria-label="Playback progress"]').attributes('type')).toBe('range');
        expect(wrapper.get('[aria-label="Playback progress"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[aria-label="Volume"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="global-audio-player-track"]').classes()).toContain('h-full');
        expect(wrapper.get('[data-test="global-audio-player-track"]').classes()).toContain('justify-center');
        expect(wrapper.get('[data-test="global-audio-player-track"]').classes()).toContain('md:justify-start');
        expect(wrapper.get('[data-test="global-audio-player-details"]').classes()).toContain('text-center');
        expect(wrapper.get('[data-test="global-audio-player-details"]').classes()).toContain('md:text-left');
        expect(wrapper.get('[data-test="global-audio-player-cover"]').classes()).toContain('size-12');
        expect(wrapper.get('[data-test="global-audio-player-cover"]').classes()).toContain('hidden');
        expect(wrapper.get('[data-test="global-audio-player-cover"]').classes()).toContain('md:flex');
        expect(wrapper.get('[data-test="global-audio-player-cover"]').classes()).not.toContain('rounded-lg');
        expect(wrapper.get('[data-test="global-audio-player-cover"]').classes()).toContain('md:size-24');
        expect(wrapper.get('[data-test="global-audio-player-cover"]').classes()).toContain('2xl:size-32');
        expect(wrapper.get('[data-test="global-audio-player-playback"]').classes()).toContain('md:max-lg:mt-3');
        expect(wrapper.get('[data-test="global-audio-player-controls"]').classes()).toContain('md:gap-5');
        expect(wrapper.get('[data-test="global-audio-player-controls"]').classes()).toContain('2xl:gap-6');
        expect(wrapper.get('[data-test="global-audio-player-title"]').attributes('data-slot')).toBe('skeleton');
        expect(wrapper.get('[data-test="global-audio-player-title"]').attributes('aria-hidden')).toBe('true');
        expect(wrapper.get('[data-test="global-audio-player-title"]').classes()).toContain('h-4');
        expect(wrapper.get('[data-test="global-audio-player-title"]').classes()).toContain('w-40');
        expect(wrapper.get('[data-test="global-audio-player-title"]').classes()).toContain('bg-prussian-blue-500/60');
        expect(wrapper.get('[data-test="global-audio-player-title"]').classes()).not.toContain('md:max-lg:ml-[18px]');
        expect(wrapper.get('[data-test="global-audio-player-title"]').classes()).not.toContain('2xl:ml-[18px]');
        expect(wrapper.get('[data-test="global-audio-player-subtitle"]').attributes('data-slot')).toBe('skeleton');
        expect(wrapper.get('[data-test="global-audio-player-subtitle"]').attributes('aria-hidden')).toBe('true');
        expect(wrapper.get('[data-test="global-audio-player-subtitle"]').classes()).toContain('h-3');
        expect(wrapper.get('[data-test="global-audio-player-subtitle"]').classes()).toContain('w-28');
        expect(wrapper.get('[data-test="global-audio-player-subtitle"]').classes()).toContain('bg-prussian-blue-500/60');
        expect(wrapper.get('[data-test="global-audio-player-subtitle"]').classes()).not.toContain('md:max-lg:ml-[18px]');
        expect(wrapper.get('[data-test="global-audio-player-subtitle"]').classes()).not.toContain('2xl:ml-[18px]');

        const reactions = wrapper.get('[data-test="global-audio-player-reactions"]');
        expect(reactions.classes()).toContain('max-lg:mx-auto');
        expect(reactions.classes()).toContain('gap-3');
        expect(reactions.classes()).toContain('md:gap-2.5');
        expect(reactions.classes()).toContain('2xl:gap-3');
        expect(reactions.classes()).not.toContain('bg-black/60');
        expect(reactions.get('[aria-label="Favorite"]').exists()).toBe(true);
        expect(wrapper.findAll('[aria-label="Favorite"]')).toHaveLength(1);
        expect(reactions.get('[aria-label="Favorite"]').classes()).toContain('text-white');
        expect(reactions.get('[aria-label="Favorite"]').classes()).toContain('enabled:hover:text-red-400');
        expect(reactions.get('[aria-label="Favorite"]').classes()).toContain('disabled:text-blue-slate-500');
        expect(reactions.get('[aria-label="Favorite"]').classes()).toContain('disabled:cursor-not-allowed');
        expect(reactions.get('[aria-label="Favorite"]').classes()).not.toContain('hover:text-red-400');
        expect(reactions.get('[aria-label="Favorite"]').attributes('disabled')).toBeDefined();
        expect(reactions.get('[aria-label="Favorite"]').find('svg').classes()).toContain('size-6');
        expect(reactions.get('[aria-label="Favorite"]').find('svg').classes()).toContain('md:size-8');
        expect(reactions.get('[aria-label="Like"]').exists()).toBe(true);
        expect(reactions.get('[aria-label="Like"]').classes()).toContain('enabled:hover:text-smart-blue-400');
        expect(reactions.get('[aria-label="Like"]').classes()).not.toContain('hover:text-smart-blue-400');
        expect(reactions.get('[aria-label="Like"]').attributes('disabled')).toBeDefined();
        expect(reactions.get('[aria-label="Like"]').find('svg').classes()).toContain('size-6');
        expect(reactions.get('[aria-label="Like"]').find('svg').classes()).toContain('md:size-8');
        expect(reactions.find('[aria-label="Dislike"]').exists()).toBe(false);
        expect(reactions.get('[aria-label="Blacklist"]').exists()).toBe(true);
        expect(reactions.get('[aria-label="Blacklist"]').classes()).toContain('enabled:hover:text-danger-300');
        expect(reactions.get('[aria-label="Blacklist"]').classes()).not.toContain('hover:text-danger-300');
        expect(reactions.get('[aria-label="Blacklist"]').attributes('disabled')).toBeDefined();
        expect(reactions.get('[aria-label="Blacklist"]').find('svg').classes()).toContain('size-6');
        expect(reactions.get('[aria-label="Blacklist"]').find('svg').classes()).toContain('md:size-8');
        expect(reactions.get('[aria-label="Funny"]').exists()).toBe(true);
        expect(reactions.get('[aria-label="Funny"]').classes()).toContain('enabled:hover:text-yellow-400');
        expect(reactions.get('[aria-label="Funny"]').classes()).not.toContain('hover:text-yellow-400');
        expect(reactions.get('[aria-label="Funny"]').attributes('disabled')).toBeDefined();
        expect(reactions.get('[aria-label="Funny"]').find('svg').classes()).toContain('size-6');
        expect(reactions.get('[aria-label="Funny"]').find('svg').classes()).toContain('md:size-8');
        expect(wrapper.get('[data-test="global-audio-player-track"]').find('[data-test="global-audio-player-reactions"]').exists()).toBe(true);
    });

    it('renders the queued track and enables playback controls', () => {
        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            {
                id: 41,
                title: 'Atlas Seed Track 0041',
                artists: 'Signal Park',
                album: 'Playback Notes',
                coverUrl: '/api/files/41/poster',
                duration: '1:31',
                durationSeconds: 91,
                reaction: { type: 'like' },
                blacklistedAt: null,
                previewedCount: 0,
                seenCount: 0,
                playbackUrl: '/api/files/41/serve',
            },
            {
                id: 42,
                title: 'Atlas Seed Track 0042',
                artists: 'The Quiet Machines',
                album: 'Blue Room Sessions',
                coverUrl: null,
                duration: '1:32',
                durationSeconds: 92,
                reaction: null,
                blacklistedAt: null,
                previewedCount: 0,
                seenCount: 0,
                playbackUrl: '/api/files/42/serve',
            },
        ], 41);

        const wrapper = mount(GlobalAudioPlayer);

        expect(wrapper.text()).toContain('Atlas Seed Track 0041');
        expect(wrapper.text()).toContain('Signal Park');
        expect(wrapper.get('audio').attributes('src')).toBe('/api/files/41/serve');
        expect(wrapper.get('[aria-label="Pause"]').attributes('disabled')).toBeUndefined();
        expect(wrapper.get('[aria-label="Next"]').attributes('disabled')).toBeUndefined();
        expect(wrapper.get('[aria-label="Previous"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[aria-label="Shuffle queue"]').attributes('disabled')).toBeUndefined();
        expect(wrapper.get('[aria-label="Queue"]').attributes('disabled')).toBeUndefined();
        expect(wrapper.get('[aria-label="Like"]').classes()).toContain('bg-smart-blue-500');
        expect(wrapper.get('[aria-label="Like"]').attributes('disabled')).toBeUndefined();
        expect(wrapper.get('[aria-label="Playback progress"]').attributes('aria-valuemax')).toBe('91');
        expect(wrapper.get('[aria-label="Playback progress"]').attributes('disabled')).toBeUndefined();
    });

    it('seeks the hidden audio element from the custom progress bar', async () => {
        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            {
                id: 41,
                title: 'Atlas Seed Track 0041',
                artists: 'Signal Park',
                album: 'Playback Notes',
                coverUrl: null,
                duration: '1:31',
                durationSeconds: 91,
                reaction: null,
                blacklistedAt: null,
                previewedCount: 0,
                seenCount: 0,
                playbackUrl: '/api/files/41/serve',
            },
        ], 41);

        const wrapper = mount(GlobalAudioPlayer);
        const seekInput = wrapper.get('[aria-label="Playback progress"]');

        await seekInput.setValue('45');

        expect((wrapper.get('audio').element as HTMLAudioElement).currentTime).toBe(45);
        expect((seekInput.element as HTMLInputElement).value).toBe('45');
        expect(wrapper.text()).toContain('0:45');
    });

    it('controls volume and restores the previous amount after mute', async () => {
        const wrapper = mount(GlobalAudioPlayer);
        await wrapper.vm.$nextTick();

        const audio = wrapper.get('audio').element as HTMLAudioElement;
        const volumeInput = wrapper.get('[aria-label="Volume"]');

        expect((volumeInput.element as HTMLInputElement).value).toBe('70');
        expect(audio.volume).toBe(0.7);

        await volumeInput.setValue('35');

        expect(audio.volume).toBe(0.35);
        expect(audio.muted).toBe(false);
        expect(wrapper.get('[aria-label="Mute volume"]').exists()).toBe(true);

        await wrapper.get('[aria-label="Mute volume"]').trigger('click');

        expect(audio.muted).toBe(true);
        expect((volumeInput.element as HTMLInputElement).value).toBe('0');

        await wrapper.get('[aria-label="Restore volume"]').trigger('click');

        expect(audio.muted).toBe(false);
        expect(audio.volume).toBe(0.35);
        expect((volumeInput.element as HTMLInputElement).value).toBe('35');
    });

    it('opens a virtualized copied queue and plays selected queue tracks', async () => {
        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            {
                id: 41,
                title: 'Atlas Seed Track 0041',
                artists: 'Signal Park',
                album: 'Playback Notes',
                coverUrl: '/api/files/41/poster',
                duration: '1:31',
                durationSeconds: 91,
                reaction: null,
                blacklistedAt: null,
                previewedCount: 0,
                seenCount: 0,
                playbackUrl: '/api/files/41/serve',
            },
            {
                id: 42,
                title: 'Atlas Seed Track 0042',
                artists: 'The Quiet Machines',
                album: 'Blue Room Sessions',
                coverUrl: null,
                duration: '1:32',
                durationSeconds: 92,
                reaction: null,
                blacklistedAt: null,
                previewedCount: 0,
                seenCount: 0,
                playbackUrl: '/api/files/42/serve',
            },
        ], 41);

        const wrapper = mount(GlobalAudioPlayer);

        await wrapper.get('[aria-label="Queue"]').trigger('click');

        expect(wrapper.get('[data-test="audio-queue-sheet"]').text()).toContain('2 tracks');
        expect(wrapper.get('[data-test="audio-queue-sheet"]').text()).not.toContain('Queue');
        expect(wrapper.get('[data-test="audio-queue-sheet"]').text()).toContain('Atlas Seed Track 0041');
        expect(wrapper.get('[data-test="audio-queue-sheet"]').text()).toContain('Atlas Seed Track 0042');
        expect(wrapper.findAll('[data-test="audio-queue-track"]')).toHaveLength(2);

        await wrapper.findAll('[data-test="audio-queue-track"]')[1]?.trigger('click');

        expect(player.currentTrackId.value).toBe(42);
        expect(player.isPlaying.value).toBe(true);
    });

    it('closes the queue sheet from the outside click backdrop', async () => {
        const player = useGlobalAudioPlayer();
        player.queueAndPlay([testTrack(41), testTrack(42)], 41);
        const wrapper = mount(GlobalAudioPlayer);

        await wrapper.get('[aria-label="Queue"]').trigger('click');

        expect(wrapper.get('[data-test="audio-queue-sheet"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="audio-queue-backdrop"]').exists()).toBe(true);

        await wrapper.get('[data-test="audio-queue-backdrop"]').trigger('click');

        expect(wrapper.find('[data-test="audio-queue-sheet"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="audio-queue-backdrop"]').exists()).toBe(false);
    });

    it('loads queue details as virtual queue rows become visible', async () => {
        const post = vi.fn().mockResolvedValue({
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
        player.queueTracks([
            {
                id: 742,
                title: 'Audio #742',
                artists: 'Loading metadata...',
                album: 'Unknown album',
                coverUrl: null,
                duration: '--:--',
                durationSeconds: null,
                reaction: null,
                blacklistedAt: null,
                previewedCount: 0,
                seenCount: 0,
                playbackUrl: '/api/files/742/serve',
            },
        ], 742);

        const wrapper = mount(GlobalAudioPlayer);

        await wrapper.get('[aria-label="Queue"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/api/audio/details', {
            ids: [742],
        });
        expect(wrapper.get('[data-test="audio-queue-sheet"]').text()).toContain('Atlas Seed Track 0742');
        expect(wrapper.get('[data-test="audio-queue-sheet"]').text()).toContain('Mira Vale');
        expect(wrapper.get('[data-test="audio-queue-sheet"]').text()).toContain('2:34');
        expect(wrapper.get('[data-test="audio-queue-sheet"]').text()).not.toContain('Loading metadata...');
        expect(player.queue.value[0]).toMatchObject({
            id: 742,
            title: 'Atlas Seed Track 0742',
            artists: 'Mira Vale',
            album: 'Late Indexes',
            duration: '2:34',
            durationSeconds: 154,
            previewedCount: 2,
            seenCount: 1,
        });
    });

    it('preloads queue details for the next playback track', async () => {
        const post = vi.fn().mockResolvedValue({
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
            testTrack(41, {
                title: 'Atlas Seed Track 0041',
                artists: 'Signal Park',
                duration: '1:31',
                durationSeconds: 91,
            }),
            testTrack(742, {
                title: 'Audio #742',
                artists: 'Loading metadata...',
                album: 'Unknown album',
                duration: '--:--',
                durationSeconds: null,
            }),
        ], 41);

        const wrapper = mount(GlobalAudioPlayer);
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/api/audio/details', {
            ids: [742],
        });
        expect(player.queue.value[1]).toMatchObject({
            id: 742,
            title: 'Atlas Seed Track 0742',
            artists: 'Mira Vale',
            album: 'Late Indexes',
            duration: '2:34',
        });

        await wrapper.get('[aria-label="Next"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-test="global-audio-player-title"]').text()).toBe('Atlas Seed Track 0742');
        expect(wrapper.get('[data-test="global-audio-player-subtitle"]').text()).toBe('Mira Vale');
        expect(wrapper.get('[data-test="global-audio-player"]').text()).not.toContain('Loading metadata...');
    });

    it('shuffles the copied queue while keeping the current track first', async () => {
        const player = useGlobalAudioPlayer();
        const copiedPlaylist = [testTrack(1), testTrack(2), testTrack(3), testTrack(4)];
        player.queueAndPlay(copiedPlaylist, 1);
        vi.spyOn(Math, 'random').mockReturnValue(0);

        const wrapper = mount(GlobalAudioPlayer);

        await wrapper.get('[aria-label="Shuffle queue"]').trigger('click');

        const shuffledOrder = player.queue.value.map((track) => track.id);
        expect(shuffledOrder).toEqual([1, 3, 4, 2]);

        player.queueTracks(copiedPlaylist, 3);

        expect(player.queue.value.map((track) => track.id)).toEqual(shuffledOrder);
        expect(player.currentTrackId.value).toBe(1);
    });

    it('cycles repeat modes from off to all to one', async () => {
        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            {
                id: 41,
                title: 'Atlas Seed Track 0041',
                artists: 'Signal Park',
                album: 'Playback Notes',
                coverUrl: null,
                duration: '1:31',
                durationSeconds: 91,
                reaction: null,
                blacklistedAt: null,
                previewedCount: 0,
                seenCount: 0,
                playbackUrl: '/api/files/41/serve',
            },
        ], 41);

        const wrapper = mount(GlobalAudioPlayer);

        await wrapper.get('[aria-label="Repeat off"]').trigger('click');
        expect(player.repeatMode.value).toBe('all');
        expect(wrapper.get('[aria-label="Repeat all"]').attributes('aria-pressed')).toBe('true');

        await wrapper.get('[aria-label="Repeat all"]').trigger('click');
        expect(player.repeatMode.value).toBe('one');
        expect(wrapper.get('[aria-label="Repeat one"]').attributes('aria-pressed')).toBe('true');

        await wrapper.get('[aria-label="Repeat one"]').trigger('click');
        expect(player.repeatMode.value).toBe('none');
        expect(wrapper.get('[aria-label="Repeat off"]').attributes('aria-pressed')).toBe('false');
    });
});
