<script setup lang="ts">
import {
    Ban,
    Heart,
    ListMusic,
    MoreVertical,
    Music,
    Play,
    Repeat,
    Shuffle,
    SkipBack,
    SkipForward,
    Smile,
    ThumbsUp,
    Volume2,
} from 'lucide-vue-next';
import { Skeleton } from '@/components/ui/skeleton';

const controlButtonClass = [
    'player-control-button inline-flex size-12 items-center justify-center rounded-full 2xl:size-14',
    'text-blue-slate-300 transition-colors',
    'enabled:cursor-pointer enabled:hover:bg-smart-blue-700 enabled:hover:text-white',
    'disabled:cursor-not-allowed disabled:text-blue-slate-500 disabled:opacity-50',
    'focus-visible:outline focus-visible:outline-2 focus-visible:outline-smart-blue-500',
].join(' ');

const reactionButtonClass = [
    'inline-flex items-center justify-center rounded p-1.5 text-white transition-colors',
    'enabled:cursor-pointer disabled:cursor-not-allowed disabled:text-blue-slate-500 disabled:opacity-50',
].join(' ');
</script>

<template>
    <section
        class="shrink-0 border-t border-twilight-indigo-500 bg-prussian-blue-900 px-4 py-3 text-twilight-indigo-100 shadow-lg lg:px-0 lg:py-0"
        data-test="global-audio-player"
        aria-label="Global audio player"
    >
        <div class="grid gap-3 md:min-h-24 lg:grid-cols-[minmax(280px,1fr)_minmax(420px,2fr)_minmax(220px,1fr)] lg:items-stretch 2xl:min-h-32">
            <div class="flex h-full min-w-0 items-stretch justify-center gap-3 md:justify-start" data-test="global-audio-player-track">
                <div
                    class="hidden size-12 aspect-square shrink-0 items-center justify-center overflow-hidden bg-prussian-blue-700 ring-1 ring-twilight-indigo-500 md:flex md:h-full md:w-auto"
                    data-test="global-audio-player-cover"
                >
                    <Music class="size-6 max-h-full max-w-full text-smart-blue-100 md:size-10 2xl:size-12" />
                </div>
                <div class="min-w-0 self-center text-center md:text-left lg:py-3" data-test="global-audio-player-details">
                    <Skeleton
                        class="h-4 w-40 bg-prussian-blue-500/60 max-md:mx-auto"
                        data-test="global-audio-player-title"
                        aria-hidden="true"
                    />
                    <Skeleton
                        class="mt-2 h-3 w-28 bg-prussian-blue-500/60 max-md:mx-auto"
                        data-test="global-audio-player-subtitle"
                        aria-hidden="true"
                    />
                    <div
                        class="mt-2 flex w-fit items-center justify-center gap-3 max-lg:mx-auto md:gap-2.5 2xl:mt-3 2xl:gap-3"
                        data-test="global-audio-player-reactions"
                    >
                        <button
                            type="button"
                            :class="[reactionButtonClass, 'enabled:hover:text-red-400']"
                            disabled
                            aria-label="Favorite"
                        >
                            <Heart class="size-6 md:size-8" />
                        </button>
                        <button
                            type="button"
                            :class="[reactionButtonClass, 'enabled:hover:text-smart-blue-400']"
                            disabled
                            aria-label="Like"
                        >
                            <ThumbsUp class="size-6 md:size-8" />
                        </button>
                        <button
                            type="button"
                            :class="[reactionButtonClass, 'enabled:hover:text-danger-300']"
                            disabled
                            aria-label="Blacklist"
                        >
                            <Ban class="size-6 md:size-8" />
                        </button>
                        <button
                            type="button"
                            :class="[reactionButtonClass, 'enabled:hover:text-yellow-400']"
                            disabled
                            aria-label="Funny"
                        >
                            <Smile class="size-6 md:size-8" />
                        </button>
                    </div>
                </div>
            </div>

            <div class="min-w-0 self-center md:max-lg:mt-3 lg:py-3" data-test="global-audio-player-playback">
                <div class="grid grid-cols-[2.5rem_minmax(0,1fr)_2.5rem] items-center gap-2 text-xs text-blue-slate-300 2xl:grid-cols-[3.75rem_minmax(0,1fr)_3.75rem] 2xl:text-sm">
                    <span class="text-right tabular-nums">0:00</span>
                    <div
                        class="h-2 overflow-hidden rounded-full bg-twilight-indigo-500 2xl:h-3"
                        role="progressbar"
                        aria-label="Playback progress"
                        aria-valuemin="0"
                        aria-valuemax="0"
                        aria-valuenow="0"
                    >
                        <div class="h-full w-0 rounded-full bg-smart-blue-100"></div>
                    </div>
                    <span class="tabular-nums">0:00</span>
                </div>

                <div class="mt-3 flex items-center justify-center gap-3 md:mt-4 md:gap-5 2xl:mt-4 2xl:gap-6" data-test="global-audio-player-controls">
                    <button type="button" :class="controlButtonClass" disabled aria-disabled="true" aria-label="Shuffle">
                        <Shuffle class="size-6 2xl:size-7" />
                    </button>
                    <button type="button" :class="controlButtonClass" disabled aria-disabled="true" aria-label="Previous">
                        <SkipBack class="size-7 2xl:size-8" />
                    </button>
                    <button
                        type="button"
                        class="inline-flex size-14 items-center justify-center rounded-full bg-smart-blue-600 text-white shadow-lg shadow-smart-blue-900/30 transition enabled:cursor-pointer enabled:hover:scale-105 enabled:hover:bg-smart-blue-500 disabled:cursor-not-allowed disabled:bg-smart-blue-900/60 disabled:text-blue-slate-400 disabled:opacity-60 disabled:shadow-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-smart-blue-300 2xl:size-16"
                        disabled
                        aria-disabled="true"
                        aria-label="Play"
                    >
                        <Play class="ml-0.5 size-7 fill-current 2xl:size-8" />
                    </button>
                    <button type="button" :class="controlButtonClass" disabled aria-disabled="true" aria-label="Next">
                        <SkipForward class="size-7 2xl:size-8" />
                    </button>
                    <button type="button" :class="controlButtonClass" disabled aria-disabled="true" aria-label="Repeat">
                        <Repeat class="size-6 2xl:size-7" />
                    </button>
                </div>
            </div>

            <div class="hidden min-w-0 items-center justify-end gap-2 lg:flex lg:py-3 lg:pr-4 2xl:gap-3">
                <button type="button" :class="controlButtonClass" disabled aria-disabled="true" aria-label="Queue">
                    <ListMusic class="size-4 2xl:size-6" />
                </button>
                <div class="flex w-24 items-center gap-2 2xl:w-36 2xl:gap-3">
                    <Volume2 class="size-4 shrink-0 text-blue-slate-300 2xl:size-6" />
                    <div
                        class="h-1.5 flex-1 overflow-hidden rounded-full bg-twilight-indigo-500 2xl:h-2.5"
                        role="progressbar"
                        aria-label="Volume"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-valuenow="70"
                    >
                        <div class="h-full w-2/3 rounded-full bg-blue-slate-300"></div>
                    </div>
                </div>
                <button type="button" :class="controlButtonClass" disabled aria-disabled="true" aria-label="More options">
                    <MoreVertical class="size-4 2xl:size-6" />
                </button>
            </div>
        </div>
    </section>
</template>
