import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import GlobalAudioPlayer from './GlobalAudioPlayer.vue';

describe('GlobalAudioPlayer', () => {
    it('renders a custom static player surface without native audio controls', () => {
        const wrapper = mount(GlobalAudioPlayer);

        expect(wrapper.find('audio').exists()).toBe(false);
        expect(wrapper.get('[data-test="global-audio-player"]').text()).toContain('No audio selected');
        expect(wrapper.get('[aria-label="Play"]').classes()).toContain('size-14');
        expect(wrapper.get('[aria-label="Play"]').classes()).toContain('2xl:size-16');
        expect(wrapper.get('[aria-label="Play"]').classes()).toContain('hover:bg-smart-blue-500');
        expect(wrapper.get('[aria-label="Play"]').attributes('aria-disabled')).toBe('true');
        expect(wrapper.get('[aria-label="Shuffle"]').classes()).toContain('player-control-button');
        expect(wrapper.get('[aria-label="Shuffle"]').classes()).toContain('hover:bg-smart-blue-700');
        expect(wrapper.get('[aria-label="Shuffle"]').classes()).toContain('hover:text-white');
        expect(wrapper.get('[aria-label="Shuffle"]').attributes('aria-disabled')).toBe('true');
        expect(wrapper.get('[aria-label="Playback progress"]').classes()).toContain('h-2');
        expect(wrapper.get('[aria-label="Playback progress"]').classes()).toContain('2xl:h-3');
        expect(wrapper.get('[aria-label="Playback progress"]').classes()).not.toContain('top-0');
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
        expect(wrapper.get('[data-test="global-audio-player-title"]').classes()).toContain('md:max-lg:pl-[18px]');
        expect(wrapper.get('[data-test="global-audio-player-title"]').classes()).toContain('2xl:pl-[18px]');
        expect(wrapper.get('[data-test="global-audio-player-subtitle"]').classes()).toContain('md:max-lg:pl-[18px]');
        expect(wrapper.get('[data-test="global-audio-player-subtitle"]').classes()).toContain('2xl:pl-[18px]');

        const reactions = wrapper.get('[data-test="global-audio-player-reactions"]');
        expect(reactions.classes()).toContain('max-lg:mx-auto');
        expect(reactions.classes()).toContain('2xl:gap-2.5');
        expect(reactions.get('[aria-label="Favorite"]').exists()).toBe(true);
        expect(wrapper.findAll('[aria-label="Favorite"]')).toHaveLength(1);
        expect(reactions.get('[aria-label="Favorite"]').classes()).toContain('text-white');
        expect(reactions.get('[aria-label="Favorite"]').classes()).toContain('hover:text-red-400');
        expect(reactions.get('[aria-label="Favorite"]').find('svg').classes()).toContain('size-7');
        expect(reactions.get('[aria-label="Like"]').exists()).toBe(true);
        expect(reactions.get('[aria-label="Like"]').classes()).toContain('hover:text-smart-blue-400');
        expect(reactions.get('[aria-label="Dislike"]').exists()).toBe(true);
        expect(reactions.get('[aria-label="Dislike"]').classes()).toContain('hover:text-gray-400');
        expect(reactions.get('[aria-label="Funny"]').exists()).toBe(true);
        expect(reactions.get('[aria-label="Funny"]').classes()).toContain('hover:text-yellow-400');
        expect(wrapper.get('[data-test="global-audio-player-track"]').find('[data-test="global-audio-player-reactions"]').exists()).toBe(true);
    });
});
