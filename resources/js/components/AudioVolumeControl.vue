<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Volume2, VolumeX } from 'lucide-vue-next';

const props = defineProps<{
    audioRef: HTMLAudioElement | null;
}>();

const emit = defineEmits<{
    volumeChange: [volume: number];
}>();

const volume = ref(0.7);
const previousVolume = ref(0.7);
const isMuted = ref(false);

const effectiveVolume = computed(() => isMuted.value ? 0 : volume.value);
const volumePercent = computed(() => Math.round(effectiveVolume.value * 100));
const volumeProgressWidth = computed(() => `${volumePercent.value}%`);

function syncAudioVolume(): void {
    const audio = props.audioRef;
    const nextEffectiveVolume = effectiveVolume.value;

    if (audio) {
        audio.volume = volume.value;
        audio.muted = isMuted.value;
    }

    emit('volumeChange', nextEffectiveVolume);
}

function handleVolumeInput(event: Event): void {
    if (!(event.target instanceof HTMLInputElement)) {
        return;
    }

    const nextVolume = Math.min(1, Math.max(0, event.target.valueAsNumber / 100));
    volume.value = nextVolume;

    if (nextVolume > 0) {
        previousVolume.value = nextVolume;
        isMuted.value = false;
        return;
    }

    isMuted.value = true;
}

function toggleMute(): void {
    if (isMuted.value || volume.value === 0) {
        volume.value = previousVolume.value > 0 ? previousVolume.value : 0.7;
        isMuted.value = false;
        return;
    }

    previousVolume.value = volume.value;
    isMuted.value = true;
}

watch(() => [props.audioRef, volume.value, isMuted.value], () => {
    syncAudioVolume();
}, { immediate: true });
</script>

<template>
    <div class="flex w-24 items-center gap-2 2xl:w-36 2xl:gap-3">
        <button
            type="button"
            class="inline-flex size-6 shrink-0 items-center justify-center text-blue-slate-300 transition hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-smart-blue-300"
            :aria-label="isMuted || volume === 0 ? 'Restore volume' : 'Mute volume'"
            @click="toggleMute"
        >
            <VolumeX v-if="isMuted || volume === 0" class="size-4 2xl:size-6" />
            <Volume2 v-else class="size-4 2xl:size-6" />
        </button>
        <input
            type="range"
            class="audio-volume-slider h-1.5 flex-1 rounded-full 2xl:h-2.5"
            aria-label="Volume"
            min="0"
            max="100"
            step="1"
            :value="volumePercent"
            :aria-valuenow="volumePercent"
            :style="{ '--seek-progress': volumeProgressWidth }"
            @input="handleVolumeInput"
            @change="handleVolumeInput"
        >
    </div>
</template>

<style scoped>
.audio-volume-slider {
    --seek-progress: 0%;
    appearance: none;
    background: linear-gradient(
        to right,
        var(--color-smart-blue-100) 0 var(--seek-progress),
        var(--color-twilight-indigo-500) var(--seek-progress) 100%
    );
    cursor: pointer;
}

.audio-volume-slider::-webkit-slider-thumb {
    width: 14px;
    height: 14px;
    appearance: none;
    border: 0;
    border-radius: 9999px;
    background: var(--color-regal-navy-100);
    box-shadow: 0 0 0 4px rgb(123 190 255 / 18%);
    opacity: 0;
    transition: opacity 120ms ease;
}

.audio-volume-slider:hover::-webkit-slider-thumb,
.audio-volume-slider:focus-visible::-webkit-slider-thumb {
    opacity: 1;
}

.audio-volume-slider::-moz-range-track {
    height: 100%;
    border: 0;
    border-radius: 9999px;
    background: transparent;
}

.audio-volume-slider::-moz-range-thumb {
    width: 14px;
    height: 14px;
    border: 0;
    border-radius: 9999px;
    background: var(--color-regal-navy-100);
    box-shadow: 0 0 0 4px rgb(123 190 255 / 18%);
    opacity: 0;
    transition: opacity 120ms ease;
}

.audio-volume-slider:hover::-moz-range-thumb,
.audio-volume-slider:focus-visible::-moz-range-thumb {
    opacity: 1;
}
</style>
