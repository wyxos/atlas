import { computed, nextTick, ref, type Ref, watch } from 'vue';

export function useOverlayVideoControls(params: {
    overlayVideoRef: Ref<HTMLVideoElement | null>;
    overlayMediaType: Ref<'image' | 'video'>;
    overlayFillComplete: Ref<boolean>;
    overlayIsClosing: Ref<boolean>;
    overlayVideoSrc: Ref<string | null>;
    overlayImageSrc: Ref<string | null>;
}) {
    const videoCurrentTime = ref(0);
    const videoDuration = ref(0);
    const isVideoPlaying = ref(false);
    const isVideoSeeking = ref(false);
    const isVideoFullscreen = ref(false);
    const videoVolume = ref(1);

    const videoProgressPercent = computed(() => {
        if (!videoDuration.value) {
            return 0;
        }
        return Math.min(100, (videoCurrentTime.value / videoDuration.value) * 100);
    });

    const videoVolumePercent = computed(() => Math.round(videoVolume.value * 100));

    const overlayVideoPoster = computed(() => {
        const src = params.overlayImageSrc.value;
        if (!src) {
            return undefined;
        }
        if (/\.(mp4|webm)(\?|#|$)/i.test(src)) {
            return undefined;
        }
        return src;
    });

    function handleVideoLoadedMetadata(): void {
        const video = params.overlayVideoRef.value;
        if (!video) {
            return;
        }
        videoDuration.value = Number.isFinite(video.duration) ? video.duration : 0;
        videoCurrentTime.value = Number.isFinite(video.currentTime) ? video.currentTime : 0;
        isVideoPlaying.value = !video.paused && !video.ended;
        videoVolume.value = video.volume;
    }

    function handleVideoTimeUpdate(): void {
        if (isVideoSeeking.value) {
            return;
        }
        const video = params.overlayVideoRef.value;
        if (!video) {
            return;
        }
        videoCurrentTime.value = video.currentTime;
    }

    function handleVideoPlay(): void {
        isVideoPlaying.value = true;
    }

    function handleVideoPause(): void {
        isVideoPlaying.value = false;
    }

    function handleVideoEnded(): void {
        isVideoPlaying.value = false;
    }

    function handleVideoVolumeChange(): void {
        const video = params.overlayVideoRef.value;
        if (!video) {
            return;
        }
        videoVolume.value = video.volume;
    }

    function toggleVideoPlayback(): void {
        const video = params.overlayVideoRef.value;
        if (!video) {
            return;
        }
        if (video.paused || video.ended) {
            void video.play().catch(() => {});
        } else {
            video.pause();
        }
    }

    function handleVideoSeek(event: Event): void {
        const video = params.overlayVideoRef.value;
        if (!video) {
            return;
        }
        const value = Number((event.target as HTMLInputElement).value);
        if (!Number.isFinite(value)) {
            return;
        }
        video.currentTime = value;
        videoCurrentTime.value = value;
    }

    function handleVideoVolumeInput(event: Event): void {
        const video = params.overlayVideoRef.value;
        if (!video) {
            return;
        }
        const value = Number((event.target as HTMLInputElement).value);
        if (!Number.isFinite(value)) {
            return;
        }
        video.volume = value;
        video.muted = value === 0;
        videoVolume.value = value;
    }

    function handleFullscreenChange(): void {
        isVideoFullscreen.value = document.fullscreenElement === params.overlayVideoRef.value;
    }

    function toggleVideoFullscreen(): void {
        const video = params.overlayVideoRef.value;
        if (!video) {
            return;
        }
        if (document.fullscreenElement) {
            void document.exitFullscreen().catch(() => {});
            return;
        }
        void video.requestFullscreen().catch(() => {});
    }

    function handleVideoSeekStart(): void {
        isVideoSeeking.value = true;
    }

    function handleVideoSeekEnd(): void {
        isVideoSeeking.value = false;
    }

    function playOverlayVideo(): void {
        const video = params.overlayVideoRef.value;
        if (!video) {
            return;
        }
        video.muted = false;
        video.volume = 1;
        videoVolume.value = video.volume;
        void video.play().catch(() => {});
    }

    watch(
        () => [params.overlayMediaType.value, params.overlayFillComplete.value, params.overlayIsClosing.value, params.overlayVideoSrc.value],
        async ([mediaType, fillComplete, isClosing, src]) => {
            if (mediaType !== 'video' || !fillComplete || isClosing || !src) {
                return;
            }

            await nextTick();
            playOverlayVideo();
        }
    );

    watch(() => params.overlayMediaType.value, (mediaType) => {
        if (mediaType !== 'video') {
            videoCurrentTime.value = 0;
            videoDuration.value = 0;
            isVideoPlaying.value = false;
            isVideoSeeking.value = false;
            isVideoFullscreen.value = false;
            videoVolume.value = 1;
        }
    });

    return {
        videoCurrentTime,
        videoDuration,
        isVideoPlaying,
        isVideoSeeking,
        isVideoFullscreen,
        videoVolume,
        videoProgressPercent,
        videoVolumePercent,
        overlayVideoPoster,
        handleVideoLoadedMetadata,
        handleVideoTimeUpdate,
        handleVideoPlay,
        handleVideoPause,
        handleVideoEnded,
        handleVideoVolumeChange,
        toggleVideoPlayback,
        handleVideoSeek,
        handleVideoSeekStart,
        handleVideoSeekEnd,
        handleVideoVolumeInput,
        toggleVideoFullscreen,
        handleFullscreenChange,
    };
}
