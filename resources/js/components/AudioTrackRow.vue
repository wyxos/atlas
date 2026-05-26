<script setup lang="ts">
import { computed } from 'vue';
import { Music, Pause, Play } from 'lucide-vue-next';
import FileReactions from './FileReactions.vue';
import { Skeleton } from '@/components/ui/skeleton';
import type { ReactionType } from '@/types/reaction';

const props = defineProps<{
    audioId: number;
    displayIndex: number;
    isLoaded: boolean;
    title: string;
    artists: string;
    album: string;
    coverUrl: string | null;
    source: string | null;
    reaction: { type: ReactionType } | null;
    blacklistedAt: string | null;
    previewedCount: number;
    seenCount: number;
    playCount: number;
    skipCount: number;
    duration: string;
    isSelected: boolean;
    isCurrentTrack: boolean;
    isPlaying: boolean;
}>();

const emit = defineEmits<{
    select: [];
    play: [];
    pause: [];
    reaction: [audioId: number, type: ReactionType];
    blacklist: [audioId: number];
}>();

function isInteractiveTarget(event: MouseEvent): boolean {
    return event.target instanceof HTMLElement && Boolean(event.target.closest('button, a, input'));
}

function handleRowClick(event: MouseEvent): void {
    if (isInteractiveTarget(event)) {
        return;
    }

    emit('select');
}

function handleRowDoubleClick(event: MouseEvent): void {
    if (isInteractiveTarget(event)) {
        return;
    }

    emit('play');
}

function handleCoverPlaybackClick(): void {
    if (props.isPlaying) {
        emit('pause');
        return;
    }

    emit('play');
}

const sourceBadgeLabel = computed(() => {
    const source = props.source?.trim();

    if (!source || source.toLowerCase() === 'local') {
        return null;
    }

    return source;
});
</script>

<template>
    <li
        class="relative grid h-[72px] grid-cols-[2.5rem_minmax(0,1fr)_3rem] items-center gap-2 px-3 text-twilight-indigo-100 transition-colors md:grid-cols-[3rem_minmax(18rem,32rem)_minmax(12rem,1fr)_minmax(10rem,auto)_5rem] md:gap-4 md:px-4 lg:grid-cols-[3rem_minmax(18rem,32rem)_minmax(12rem,1fr)_7rem_minmax(10rem,auto)_5rem]"
        :class="[
            props.isPlaying ? 'bg-smart-blue-600/95 shadow-[inset_4px_0_0_rgb(219_238_255/0.95)] ring-2 ring-inset ring-smart-blue-100/90 hover:bg-smart-blue-600' : '',
            props.isCurrentTrack && !props.isPlaying ? 'bg-smart-blue-700/90 shadow-[inset_4px_0_0_rgb(123_190_255/0.95)] ring-2 ring-inset ring-smart-blue-100/75 hover:bg-smart-blue-700' : '',
            props.isSelected && !props.isCurrentTrack ? 'bg-prussian-blue-600/55' : '',
            !props.isCurrentTrack ? 'hover:bg-prussian-blue-600/80' : '',
        ]"
        data-test="audio-track-row"
        :data-audio-id="props.audioId"
        :data-current-track="props.isCurrentTrack ? 'true' : 'false'"
        :aria-selected="props.isSelected"
        @click="handleRowClick"
        @dblclick="handleRowDoubleClick"
    >
        <p
            class="text-right text-sm tabular-nums"
            :class="props.isCurrentTrack ? 'text-smart-blue-100' : 'text-blue-slate-300'"
            data-test="audio-track-index"
        >
            {{ props.displayIndex }}
        </p>

        <template v-if="props.isLoaded">
            <div class="flex min-w-0 items-center gap-3" data-test="audio-track-title-cell">
                <div
                    class="group/cover relative flex size-12 shrink-0 items-center justify-center overflow-hidden bg-prussian-blue-800 ring-1 ring-twilight-indigo-500/70"
                    data-test="audio-track-cover"
                >
                    <div
                        v-if="props.isPlaying"
                        class="audio-visualizer flex h-full w-full items-end justify-center gap-0.5 bg-prussian-blue-900"
                        aria-hidden="true"
                        data-test="audio-track-playing-bars"
                    >
                        <span class="audio-visual-bar"></span>
                        <span class="audio-visual-bar"></span>
                        <span class="audio-visual-bar"></span>
                        <span class="audio-visual-bar"></span>
                        <span class="audio-visual-bar"></span>
                        <span class="audio-visual-bar"></span>
                        <span class="audio-visual-bar"></span>
                        <span class="audio-visual-bar"></span>
                    </div>
                    <img
                        v-else-if="props.coverUrl"
                        :src="props.coverUrl"
                        alt=""
                        class="h-full w-full object-cover"
                        loading="lazy"
                    >
                    <Music v-else class="size-5 text-blue-slate-300" />
                    <button
                        type="button"
                        class="absolute inset-0 flex items-center justify-center bg-black/55 text-white opacity-0 transition-opacity group-hover/cover:opacity-100 focus-visible:opacity-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-smart-blue-300"
                        :aria-label="props.isPlaying ? 'Pause track' : 'Play track'"
                        @click="handleCoverPlaybackClick"
                    >
                        <Pause v-if="props.isPlaying" class="size-5 fill-current" />
                        <Play v-else class="ml-0.5 size-5 fill-current" />
                    </button>
                </div>
                <div class="min-w-0">
                    <div class="flex min-w-0 items-center gap-2">
                        <p
                            class="min-w-0 truncate text-sm font-medium"
                            :class="props.isCurrentTrack ? 'text-smart-blue-100' : 'text-regal-navy-100'"
                        >
                            {{ props.title }}
                        </p>
                        <span
                            v-if="sourceBadgeLabel"
                            class="shrink-0 rounded border border-smart-blue-400/60 bg-smart-blue-950/85 px-1.5 py-0.5 text-[0.625rem] font-semibold leading-none text-smart-blue-100 uppercase"
                            data-test="audio-track-source-badge"
                        >
                            {{ sourceBadgeLabel }}
                        </span>
                    </div>
                    <p class="truncate text-xs text-blue-slate-300">{{ props.artists }}</p>
                </div>
            </div>
            <p class="hidden truncate text-sm text-blue-slate-300 md:block" data-test="audio-track-album">{{ props.album }}</p>
            <p class="hidden text-xs leading-4 tabular-nums text-blue-slate-300 lg:block" data-test="audio-track-playback-counts">
                <span class="block" data-test="audio-track-play-count">{{ props.playCount }} plays</span>
                <span class="block" data-test="audio-track-skip-count">{{ props.skipCount }} skips</span>
            </p>
            <div class="hidden md:block">
                <FileReactions
                    :file-id="props.audioId"
                    :reaction="props.reaction"
                    :blacklisted-at="props.blacklistedAt"
                    :previewed-count="props.previewedCount"
                    :viewed-count="props.seenCount"
                    variant="small"
                    mode="reaction-only"
                    :show-blacklist="true"
                    surface="none"
                    :icon-size="23"
                    @reaction="(type) => emit('reaction', props.audioId, type)"
                    @blacklist="() => emit('blacklist', props.audioId)"
                />
            </div>
            <p class="text-right text-sm tabular-nums text-blue-slate-300" data-test="audio-track-duration">
                {{ props.duration }}
            </p>
        </template>

        <template v-else>
            <div class="flex min-w-0 items-center gap-3">
                <Skeleton class="size-12 shrink-0 bg-prussian-blue-500/60" />
                <div class="min-w-0 flex-1 space-y-2">
                    <Skeleton class="h-4 w-2/3 bg-prussian-blue-500/60" />
                    <Skeleton class="h-3 w-1/2 bg-prussian-blue-500/60" />
                </div>
            </div>
            <Skeleton class="hidden h-4 w-2/3 bg-prussian-blue-500/60 md:block" />
            <Skeleton class="hidden h-8 w-20 bg-prussian-blue-500/60 lg:block" />
            <div
                class="hidden h-8 w-36 rounded-lg bg-black/40 md:block"
                aria-hidden="true"
            />
            <Skeleton class="ml-auto h-4 w-10 bg-prussian-blue-500/60" />
        </template>
    </li>
</template>

<style scoped>
.audio-visualizer {
    padding-block: 9px;
}

.audio-visual-bar {
    width: 3px;
    height: 8px;
    border-radius: 1px;
    background: currentColor;
    color: var(--color-smart-blue-100);
    box-shadow: 0 0 10px rgb(123 190 255 / 32%);
    animation: audio-visual-bar 0.58s steps(5, end) infinite;
    transform-origin: bottom;
}

.audio-visual-bar:nth-child(2) {
    animation-delay: -0.18s;
    animation-duration: 0.46s;
}

.audio-visual-bar:nth-child(3) {
    animation-delay: -0.32s;
    animation-duration: 0.63s;
}

.audio-visual-bar:nth-child(4) {
    animation-delay: -0.09s;
    animation-duration: 0.52s;
}

.audio-visual-bar:nth-child(5) {
    animation-delay: -0.41s;
    animation-duration: 0.49s;
}

.audio-visual-bar:nth-child(6) {
    animation-delay: -0.25s;
    animation-duration: 0.67s;
}

.audio-visual-bar:nth-child(7) {
    animation-delay: -0.13s;
    animation-duration: 0.43s;
}

.audio-visual-bar:nth-child(8) {
    animation-delay: -0.36s;
    animation-duration: 0.61s;
}

@keyframes audio-visual-bar {
    0% {
        height: 7px;
        opacity: 0.62;
    }

    12% {
        height: 25px;
        opacity: 1;
    }

    25% {
        height: 13px;
        opacity: 0.78;
    }

    38% {
        height: 28px;
        opacity: 1;
    }

    52% {
        height: 10px;
        opacity: 0.68;
    }

    68% {
        height: 22px;
        opacity: 0.94;
    }

    82% {
        height: 15px;
        opacity: 0.82;
    }

    100% {
        height: 7px;
        opacity: 0.62;
    }
}
</style>
