<script setup lang="ts">
import { Loader2, Maximize2, Minimize2, Pause, Play, X } from 'lucide-vue-next';
import FileReactions from './FileReactions.vue';
import type { FeedItem } from '@/composables/useTabs';
import type { ReactionType } from '@/types/reaction';

type OverlayMediaType = 'image' | 'video' | 'audio' | 'file';

interface OverlayState {
    image: { src: string; srcset?: string; sizes?: string; alt?: string } | null;
    mediaType: OverlayMediaType;
    videoSrc: string | null;
    audioSrc: string | null;
    key: number;
    isLoading: boolean;
    isFilled: boolean;
    fillComplete: boolean;
    isClosing: boolean;
    fullSizeImage: string | null;
}

interface VideoControls {
    setRef: (element: HTMLVideoElement | null) => void;
    poster: string | undefined;
    isPlaying: boolean;
    isFullscreen: boolean;
    currentTime: number;
    duration: number;
    volume: number;
    progressPercent: number;
    volumePercent: number;
    onLoadedMetadata: () => void;
    onTimeUpdate: () => void;
    onPlay: () => void;
    onPause: () => void;
    onEnded: () => void;
    onVolumeChange: () => void;
    onTogglePlayback: () => void;
    onSeek: (event: Event) => void;
    onSeekStart: () => void;
    onSeekEnd: () => void;
    onVolumeInput: (event: Event) => void;
    onToggleFullscreen: () => void;
}

interface AudioControls {
    setRef: (element: HTMLAudioElement | null) => void;
    isPlaying: boolean;
    currentTime: number;
    duration: number;
    volume: number;
    progressPercent: number;
    volumePercent: number;
    onLoadedMetadata: () => void;
    onTimeUpdate: () => void;
    onPlay: () => void;
    onPause: () => void;
    onEnded: () => void;
    onVolumeChange: () => void;
    onTogglePlayback: () => void;
    onSeek: (event: Event) => void;
    onSeekStart: () => void;
    onSeekEnd: () => void;
    onVolumeInput: (event: Event) => void;
}

interface Props {
    overlay: OverlayState;
    currentItem: FeedItem | null;
    currentIndex: number;
    itemsLength: number;
    isLoadingMore: boolean;
    overlayMediaTransitionClass: string;
    overlayMediaStyle: unknown;
    video: VideoControls;
    audio: AudioControls;
}

defineProps<Props>();

const emit = defineEmits<{
    close: [];
    openOriginal: [];
    reaction: [type: ReactionType];
}>();

function emitReaction(type: ReactionType): void {
    emit('reaction', type);
}

function handleOverlayImageMouseDown(event: MouseEvent): void {
    if (event.altKey && event.button === 1) {
        event.preventDefault();
        event.stopPropagation();
        emitReaction('love');
        return;
    }

    if (!event.altKey && event.button === 1) {
        event.preventDefault();
        event.stopPropagation();
    }
}

function handleOverlayMediaClick(event: MouseEvent): void {
    if (!event.altKey) {
        return;
    }

    if (event.button !== 0 && event.type !== 'click') {
        return;
    }

    event.preventDefault();
    event.stopPropagation();
    emitReaction('like');
}

function handleOverlayMediaContextMenu(event: MouseEvent): void {
    if (!event.altKey) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();
    emitReaction('dislike');
}

function handleOverlayImageAuxClick(event: MouseEvent): void {
    if (!event.altKey && event.button === 1) {
        event.preventDefault();
        event.stopPropagation();
        emit('openOriginal');
    }
}
</script>

<template>
    <div class="relative flex-1 min-h-0 overflow-hidden" :style="overlayMediaStyle">
        <img v-if="overlay.isLoading" :key="overlay.key + '-preview'" :src="overlay.image?.src"
            :srcset="overlay.image?.srcset" :sizes="overlay.image?.sizes" :alt="overlay.image?.alt" :class="[
                'absolute select-none pointer-events-none object-cover',
                overlayMediaTransitionClass,
            ]" :style="overlayMediaStyle" draggable="false">

        <div v-if="overlay.isLoading" class="absolute inset-0 flex items-center justify-center z-10">
            <Loader2 :size="32" class="animate-spin text-smart-blue-500" />
        </div>

        <img v-if="!overlay.isLoading && overlay.mediaType !== 'video'" :key="overlay.key"
            :src="overlay.fullSizeImage || overlay.image?.src" :alt="overlay.image?.alt" :class="[
                'absolute select-none',
                overlay.isFilled && overlay.fillComplete && !overlay.isClosing ? 'cursor-pointer pointer-events-auto' : 'pointer-events-none',
                overlay.isFilled ? '' : 'object-cover',
                overlayMediaTransitionClass,
            ]" :style="overlayMediaStyle" draggable="false" @click="handleOverlayMediaClick"
            @contextmenu="handleOverlayMediaContextMenu" @mousedown="handleOverlayImageMouseDown"
            @auxclick="handleOverlayImageAuxClick" />

        <video v-else-if="!overlay.isLoading && overlay.mediaType === 'video'" :key="overlay.key" :poster="video.poster"
            :ref="video.setRef" :class="[
                'absolute',
                overlay.isFilled && overlay.fillComplete && !overlay.isClosing ? 'pointer-events-auto' : 'pointer-events-none',
                overlay.isFilled ? 'object-contain' : 'object-cover',
                overlayMediaTransitionClass,
            ]" :style="overlayMediaStyle" playsinline disablepictureinpicture preload="metadata"
            @click="handleOverlayMediaClick" @contextmenu="handleOverlayMediaContextMenu"
            @loadedmetadata="video.onLoadedMetadata" @timeupdate="video.onTimeUpdate" @play="video.onPlay"
            @pause="video.onPause" @ended="video.onEnded" @volumechange="video.onVolumeChange"
            @mousedown="handleOverlayImageMouseDown" @auxclick="handleOverlayImageAuxClick">
            <source v-if="overlay.videoSrc" :src="overlay.videoSrc" type="video/mp4" />
        </video>

        <div v-if="!overlay.isLoading && overlay.mediaType === 'video' && overlay.fillComplete && !overlay.isClosing"
            class="absolute bottom-4 left-0 right-0 z-50 pointer-events-auto px-4">
            <div
                class="flex w-full items-center gap-3 rounded border border-twilight-indigo-500/80 bg-prussian-blue-800/80 px-3 py-2 backdrop-blur">
                <button type="button" class="rounded-full p-2 text-smart-blue-100 hover:text-white"
                    :aria-label="video.isPlaying ? 'Pause video' : 'Play video'" @click.stop="video.onTogglePlayback">
                    <Pause v-if="video.isPlaying" :size="16" />
                    <Play v-else :size="16" />
                </button>
                <div class="flex-1 min-w-0">
                    <input class="file-viewer-video-slider w-full" type="range" min="0" :max="video.duration || 0"
                        step="0.1" :value="video.currentTime" :style="{
                            backgroundImage: `linear-gradient(to right, var(--color-smart-blue-400) ${video.progressPercent}%, var(--color-twilight-indigo-600) ${video.progressPercent}%)`,
                        }" @input="video.onSeek" @pointerdown="video.onSeekStart" @pointerup="video.onSeekEnd" />
                </div>
                <div class="w-24 shrink-0">
                    <input class="file-viewer-video-slider w-full" type="range" min="0" max="1" step="0.01"
                        :value="video.volume" :style="{
                            backgroundImage: `linear-gradient(to right, var(--color-smart-blue-400) ${video.volumePercent}%, var(--color-twilight-indigo-600) ${video.volumePercent}%)`,
                        }" @input="video.onVolumeInput" />
                </div>
                <button type="button" class="rounded-full p-2 text-smart-blue-100 hover:text-white"
                    :aria-label="video.isFullscreen ? 'Exit fullscreen' : 'Enter fullscreen'"
                    @click.stop="video.onToggleFullscreen">
                    <Minimize2 v-if="video.isFullscreen" :size="16" />
                    <Maximize2 v-else :size="16" />
                </button>
            </div>
        </div>

        <audio v-if="!overlay.isLoading && overlay.mediaType === 'audio'" :ref="audio.setRef" class="hidden"
            preload="metadata" @loadedmetadata="audio.onLoadedMetadata" @timeupdate="audio.onTimeUpdate"
            @play="audio.onPlay" @pause="audio.onPause" @ended="audio.onEnded"
            @volumechange="audio.onVolumeChange" />

        <div v-if="!overlay.isLoading && overlay.mediaType === 'audio' && overlay.fillComplete && !overlay.isClosing"
            class="absolute bottom-4 left-0 right-0 z-50 pointer-events-auto px-4">
            <div
                class="flex w-full items-center gap-3 rounded border border-twilight-indigo-500/80 bg-prussian-blue-800/80 px-3 py-2 backdrop-blur">
                <button type="button" class="rounded-full p-2 text-smart-blue-100 hover:text-white"
                    :aria-label="audio.isPlaying ? 'Pause audio' : 'Play audio'" @click.stop="audio.onTogglePlayback">
                    <Pause v-if="audio.isPlaying" :size="16" />
                    <Play v-else :size="16" />
                </button>
                <div class="flex-1 min-w-0">
                    <input class="file-viewer-video-slider w-full" type="range" min="0" :max="audio.duration || 0"
                        step="0.1" :value="audio.currentTime" :style="{
                            backgroundImage: `linear-gradient(to right, var(--color-smart-blue-400) ${audio.progressPercent}%, var(--color-twilight-indigo-600) ${audio.progressPercent}%)`,
                        }" @input="audio.onSeek" @pointerdown="audio.onSeekStart" @pointerup="audio.onSeekEnd" />
                </div>
                <div class="w-24 shrink-0">
                    <input class="file-viewer-video-slider w-full" type="range" min="0" max="1" step="0.01"
                        :value="audio.volume" :style="{
                            backgroundImage: `linear-gradient(to right, var(--color-smart-blue-400) ${audio.volumePercent}%, var(--color-twilight-indigo-600) ${audio.volumePercent}%)`,
                        }" @input="audio.onVolumeInput" />
                </div>
            </div>
        </div>

        <div v-if="overlay.isFilled && overlay.fillComplete && !overlay.isClosing && currentItem"
            class="absolute bottom-4 left-1/2 z-50 -translate-x-1/2 pointer-events-auto">
            <FileReactions :file-id="currentItem.id" :reaction="currentItem.reaction as ({ type: string } | null | undefined)"
                :previewed-count="currentItem.previewed_count ?? 0" :viewed-count="currentItem.seen_count ?? 0"
                :current-index="currentIndex" :total-items="itemsLength" variant="default" @reaction="emitReaction" />
        </div>

        <button v-if="overlay.fillComplete && !overlay.isClosing" @click="emit('close')"
            class="absolute top-4 right-4 z-50 p-2 rounded-full bg-black/50 hover:bg-black/70 text-white transition-colors pointer-events-auto"
            aria-label="Close overlay" data-test="close-overlay-button">
            <X :size="20" />
        </button>

        <div v-if="overlay.fillComplete && !overlay.isClosing && isLoadingMore"
            class="absolute top-4 left-1/2 z-50 -translate-x-1/2 rounded-full border border-smart-blue-500/80 bg-prussian-blue-800/90 px-4 py-2 text-xs font-medium text-smart-blue-100 backdrop-blur">
            <span class="inline-flex items-center gap-2">
                <Loader2 :size="14" class="animate-spin" />
                Loading more items…
            </span>
        </div>
    </div>
</template>
