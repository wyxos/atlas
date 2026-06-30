import { afterEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import GlobalAudioPlayer from './GlobalAudioPlayer.vue';
import { useGlobalAudioPlayer, type AudioPlayerTrack } from '@/composables/useGlobalAudioPlayer';
import { resetAudioPlaybackSessionForTests } from '@/composables/useAudioPlaybackSession';

function testTrack(id: number, overrides: Partial<AudioPlayerTrack> = {}): AudioPlayerTrack {
    return {
        id,
        title: `Track ${id}`,
        artists: `Artist ${id}`,
        album: `Album ${id}`,
        coverUrl: null,
        duration: '6:12',
        durationSeconds: 372,
        reaction: null,
        blacklistedAt: null,
        previewedCount: 0,
        seenCount: 0,
        playbackUrl: `/api/files/${id}/serve`,
        ...overrides,
    };
}

afterEach(() => {
    useGlobalAudioPlayer().clear();
    resetAudioPlaybackSessionForTests();
});

describe('GlobalAudioPlayer persistence', () => {
    it('restores the saved playback position in the visible player and hidden audio element', async () => {
        const player = useGlobalAudioPlayer();
        player.queueAndPlay([testTrack(1, {
            title: 'Atlas Seed Track 0001',
            artists: 'Signal Park, Collaborator 1',
        })], 1, { queueLabel: 'All audio' });
        player.updatePlaybackPosition(61.2);

        const wrapper = mount(GlobalAudioPlayer);
        const seekInput = wrapper.get('[aria-label="Playback progress"]');

        expect(wrapper.get('[data-test="global-audio-player-title"]').text()).toBe('Atlas Seed Track 0001');
        expect(wrapper.get('[data-test="global-audio-player-subtitle"]').text()).toBe('Signal Park, Collaborator 1');
        expect(wrapper.get('[data-test="global-audio-player"]').text()).toContain('1:01');
        expect(wrapper.get('[data-test="global-audio-player"]').text()).toContain('6:12');
        expect((seekInput.element as HTMLInputElement).value).toBe('61.2');

        await wrapper.get('audio').trigger('loadedmetadata');

        expect((wrapper.get('audio').element as HTMLAudioElement).currentTime).toBe(61.2);
    });
});
