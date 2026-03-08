import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import FileViewerMediaStage from './FileViewerMediaStage.vue';

function noop(): void {
    // Intentionally empty for event callback props in tests.
}

function buildVideoControls() {
    return {
        setRef: noop,
        poster: undefined,
        isPlaying: false,
        isFullscreen: false,
        currentTime: 0,
        duration: 0,
        volume: 1,
        progressPercent: 0,
        volumePercent: 100,
        onLoadedMetadata: noop,
        onTimeUpdate: noop,
        onPlay: noop,
        onPause: noop,
        onEnded: noop,
        onVolumeChange: noop,
        onTogglePlayback: noop,
        onSeek: noop,
        onSeekStart: noop,
        onSeekEnd: noop,
        onVolumeInput: noop,
        onToggleFullscreen: noop,
    };
}

function buildAudioControls() {
    return {
        setRef: noop,
        isPlaying: false,
        currentTime: 0,
        duration: 0,
        volume: 1,
        progressPercent: 0,
        volumePercent: 100,
        onLoadedMetadata: noop,
        onTimeUpdate: noop,
        onPlay: noop,
        onPause: noop,
        onEnded: noop,
        onVolumeChange: noop,
        onTogglePlayback: noop,
        onSeek: noop,
        onSeekStart: noop,
        onSeekEnd: noop,
        onVolumeInput: noop,
    };
}

describe('FileViewerMediaStage', () => {
    it('keeps overlay positioning on the media element instead of offsetting the stage wrapper', () => {
        const overlayMediaStyle = {
            width: '832px',
            height: '1216px',
            top: '35px',
            left: '918px',
            transform: 'scale(1) translateY(0px)',
            transformOrigin: 'center center',
        };

        const wrapper = mount(FileViewerMediaStage, {
            props: {
                overlay: {
                    image: { src: 'https://example.com/image.jpeg', alt: 'Example image' },
                    mediaType: 'image',
                    videoSrc: null,
                    audioSrc: null,
                    key: 1,
                    isLoading: false,
                    isFilled: true,
                    fillComplete: true,
                    isClosing: false,
                    fullSizeImage: null,
                },
                currentItem: {
                    id: 4,
                    reaction: null,
                    previewed_count: 3,
                    seen_count: 0,
                },
                currentIndex: 3,
                itemsLength: 301,
                isLoadingMore: false,
                overlayMediaTransitionClass: '',
                overlayMediaStyle,
                video: buildVideoControls(),
                audio: buildAudioControls(),
            },
            global: {
                stubs: {
                    FileReactions: true,
                },
            },
        });

        const stage = wrapper.get('div.relative.flex-1.min-h-0.h-full.w-full.overflow-hidden');
        const media = wrapper.get('img[alt="Example image"]');

        expect(stage.attributes('style')).toBeUndefined();
        expect(media.attributes('style')).toContain('width: 832px;');
        expect(media.attributes('style')).toContain('height: 1216px;');
        expect(media.attributes('style')).toContain('top: 35px;');
        expect(media.attributes('style')).toContain('left: 918px;');
    });
});
