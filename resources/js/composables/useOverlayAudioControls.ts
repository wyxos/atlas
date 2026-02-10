import { computed, nextTick, ref, type Ref, watch } from 'vue';

export function useOverlayAudioControls(params: {
    overlayAudioRef: Ref<HTMLAudioElement | null>;
    overlayMediaType: Ref<'image' | 'video' | 'audio' | 'file'>;
    overlayFillComplete: Ref<boolean>;
    overlayIsClosing: Ref<boolean>;
    overlayAudioSrc: Ref<string | null>;
}) {
    const audioCurrentTime = ref(0);
    const audioDuration = ref(0);
    const isAudioPlaying = ref(false);
    const isAudioSeeking = ref(false);
    const audioVolume = ref(1);

    const audioProgressPercent = computed(() => {
        if (!audioDuration.value) {
            return 0;
        }
        return Math.min(100, (audioCurrentTime.value / audioDuration.value) * 100);
    });

    const audioVolumePercent = computed(() => Math.round(audioVolume.value * 100));

    function handleAudioLoadedMetadata(): void {
        const audio = params.overlayAudioRef.value;
        if (!audio) {
            return;
        }
        audioDuration.value = Number.isFinite(audio.duration) ? audio.duration : 0;
        audioCurrentTime.value = Number.isFinite(audio.currentTime) ? audio.currentTime : 0;
        isAudioPlaying.value = !audio.paused && !audio.ended;
        audioVolume.value = audio.volume;
    }

    function handleAudioTimeUpdate(): void {
        if (isAudioSeeking.value) {
            return;
        }
        const audio = params.overlayAudioRef.value;
        if (!audio) {
            return;
        }
        audioCurrentTime.value = audio.currentTime;
    }

    function handleAudioPlay(): void {
        isAudioPlaying.value = true;
    }

    function handleAudioPause(): void {
        isAudioPlaying.value = false;
    }

    function handleAudioEnded(): void {
        isAudioPlaying.value = false;
    }

    function handleAudioVolumeChange(): void {
        const audio = params.overlayAudioRef.value;
        if (!audio) {
            return;
        }
        audioVolume.value = audio.volume;
    }

    function toggleAudioPlayback(): void {
        const audio = params.overlayAudioRef.value;
        if (!audio) {
            return;
        }

        if (audio.paused || audio.ended) {
            void audio.play().catch(() => {});
        } else {
            audio.pause();
        }
    }

    function handleAudioSeek(event: Event): void {
        const audio = params.overlayAudioRef.value;
        if (!audio) {
            return;
        }
        const value = Number((event.target as HTMLInputElement).value);
        if (!Number.isFinite(value)) {
            return;
        }
        audio.currentTime = value;
        audioCurrentTime.value = value;
    }

    function handleAudioSeekStart(): void {
        isAudioSeeking.value = true;
    }

    function handleAudioSeekEnd(): void {
        isAudioSeeking.value = false;
    }

    function handleAudioVolumeInput(event: Event): void {
        const audio = params.overlayAudioRef.value;
        if (!audio) {
            return;
        }
        const value = Number((event.target as HTMLInputElement).value);
        if (!Number.isFinite(value)) {
            return;
        }
        audio.volume = value;
        audio.muted = value === 0;
        audioVolume.value = value;
    }

    async function syncAudioSrc(): Promise<void> {
        const audio = params.overlayAudioRef.value;
        const src = params.overlayAudioSrc.value;
        if (!audio || !src) {
            return;
        }

        // Ensure src changes are reflected immediately even if the element was previously in a bad state.
        audio.pause();
        audio.src = src;
        try {
            audio.load();
        } catch {
            // Ignore load failures; the UI will show duration 0.
        }

        await nextTick();
    }

    watch(
        () => [params.overlayMediaType.value, params.overlayFillComplete.value, params.overlayIsClosing.value, params.overlayAudioSrc.value],
        async ([mediaType, fillComplete, isClosing, src]) => {
            if (mediaType === 'audio' && isClosing) {
                params.overlayAudioRef.value?.pause();
                return;
            }
            if (mediaType !== 'audio' || !fillComplete || isClosing || !src) {
                return;
            }

            await nextTick();
            await syncAudioSrc();
        }
    );

    watch(() => params.overlayMediaType.value, (mediaType) => {
        if (mediaType !== 'audio') {
            params.overlayAudioRef.value?.pause();
            audioCurrentTime.value = 0;
            audioDuration.value = 0;
            isAudioPlaying.value = false;
            isAudioSeeking.value = false;
            audioVolume.value = 1;
        }
    });

    return {
        audioCurrentTime,
        audioDuration,
        isAudioPlaying,
        isAudioSeeking,
        audioVolume,
        audioProgressPercent,
        audioVolumePercent,
        handleAudioLoadedMetadata,
        handleAudioTimeUpdate,
        handleAudioPlay,
        handleAudioPause,
        handleAudioEnded,
        handleAudioVolumeChange,
        toggleAudioPlayback,
        handleAudioSeek,
        handleAudioSeekStart,
        handleAudioSeekEnd,
        handleAudioVolumeInput,
    };
}
