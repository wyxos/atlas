import { afterEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import GlobalAudioPlayer from './GlobalAudioPlayer.vue';
import { useGlobalAudioPlayer } from '@/composables/useGlobalAudioPlayer';

describe('GlobalAudioPlayer', () => {
    afterEach(() => {
        useGlobalAudioPlayer().clear();
    });

    it('renders a custom static player surface without native audio controls', () => {
        const wrapper = mount(GlobalAudioPlayer);
        const playerText = wrapper.get('[data-test="global-audio-player"]').text();
        const playButton = wrapper.get('[aria-label="Play"]');
        const shuffleButton = wrapper.get('[aria-label="Shuffle"]');

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
        expect(wrapper.get('[data-test="global-audio-player-cover"]').classes()).toContain('aspect-square');
        expect(wrapper.get('[data-test="global-audio-player-cover"]').classes()).toContain('hidden');
        expect(wrapper.get('[data-test="global-audio-player-cover"]').classes()).toContain('md:flex');
        expect(wrapper.get('[data-test="global-audio-player-cover"]').classes()).not.toContain('rounded-lg');
        expect(wrapper.get('[data-test="global-audio-player-cover"]').classes()).toContain('md:h-full');
        expect(wrapper.get('[data-test="global-audio-player-cover"]').classes()).toContain('md:w-auto');
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
});
