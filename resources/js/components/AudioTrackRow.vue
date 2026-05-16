<script setup lang="ts">
import { Music } from 'lucide-vue-next';
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
    reaction: { type: ReactionType } | null;
    blacklistedAt: string | null;
    previewedCount: number;
    seenCount: number;
    duration: string;
}>();

const emit = defineEmits<{
    reaction: [audioId: number, type: ReactionType];
    blacklist: [audioId: number];
}>();
</script>

<template>
    <li
        class="grid h-[72px] grid-cols-[2.5rem_minmax(0,1fr)_3rem] items-center gap-2 px-3 text-twilight-indigo-100 transition-colors hover:bg-prussian-blue-600/80 md:grid-cols-[3rem_minmax(18rem,32rem)_minmax(12rem,1fr)_minmax(10rem,auto)_5rem] md:gap-4 md:px-4"
        data-test="audio-track-row"
    >
        <p class="text-right text-sm tabular-nums text-blue-slate-300" data-test="audio-track-index">
            {{ props.displayIndex }}
        </p>

        <template v-if="props.isLoaded">
            <div class="flex min-w-0 items-center gap-3" data-test="audio-track-title-cell">
                <div class="flex size-12 shrink-0 items-center justify-center bg-prussian-blue-800 ring-1 ring-twilight-indigo-500/70">
                    <img
                        v-if="props.coverUrl"
                        :src="props.coverUrl"
                        alt=""
                        class="h-full w-full object-cover"
                        loading="lazy"
                    >
                    <Music v-else class="size-5 text-blue-slate-300" />
                </div>
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-regal-navy-100">{{ props.title }}</p>
                    <p class="truncate text-xs text-blue-slate-300">{{ props.artists }}</p>
                </div>
            </div>
            <p class="hidden truncate text-sm text-blue-slate-300 md:block" data-test="audio-track-album">{{ props.album }}</p>
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
            <div
                class="hidden h-8 w-36 rounded-lg bg-black/40 md:block"
                aria-hidden="true"
            />
            <Skeleton class="ml-auto h-4 w-10 bg-prussian-blue-500/60" />
        </template>
    </li>
</template>
