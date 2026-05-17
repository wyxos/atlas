<script setup lang="ts">
import { Music, X } from 'lucide-vue-next';
import VirtualList from './VirtualList.vue';
import type { AudioPlayerTrack } from '@/composables/useGlobalAudioPlayer';

defineProps<{
    tracks: AudioPlayerTrack[];
    currentTrackId: number | null;
}>();

const emit = defineEmits<{
    close: [];
    play: [trackId: number];
    visibleItemsChange: [items: AudioPlayerTrack[]];
}>();

function handleVisibleItemsChange(items: unknown[]): void {
    emit('visibleItemsChange', items as AudioPlayerTrack[]);
}
</script>

<template>
    <aside
        id="audio-queue-sheet"
        class="fixed inset-y-0 right-0 z-[80] flex w-[min(26rem,100vw)] flex-col border-l-2 border-twilight-indigo-500 bg-prussian-blue-800 text-twilight-indigo-100 shadow-2xl"
        data-test="audio-queue-sheet"
        aria-label="Playback queue"
    >
        <header class="flex h-14 shrink-0 items-center justify-between border-b border-twilight-indigo-500/70 px-4">
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-regal-navy-100">
                    {{ tracks.length === 1 ? '1 track' : `${tracks.length} tracks` }}
                </p>
            </div>
            <button
                type="button"
                class="inline-flex size-9 items-center justify-center rounded text-blue-slate-200 transition hover:bg-prussian-blue-600 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-smart-blue-300"
                aria-label="Close queue"
                data-test="audio-queue-close"
                @click="emit('close')"
            >
                <X class="size-4" />
            </button>
        </header>

        <div v-if="tracks.length === 0" class="p-4 text-sm text-blue-slate-300">
            Queue is empty.
        </div>
        <VirtualList
            v-else
            :items="tracks"
            :item-height="64"
            :overscan="4"
            container-class="min-h-0 flex-1 overflow-y-auto [scrollbar-gutter:stable]"
            @visible-items-change="handleVisibleItemsChange"
        >
            <template #default="{ items, startIndex }">
                <ol class="divide-y divide-twilight-indigo-500/50">
                    <li
                        v-for="(track, index) in (items as AudioPlayerTrack[])"
                        :key="track.id"
                    >
                        <button
                            type="button"
                            class="grid h-16 w-full grid-cols-[2rem_2.5rem_minmax(0,1fr)_3rem] items-center gap-3 px-4 text-left transition hover:bg-prussian-blue-600/80 focus-visible:bg-prussian-blue-600/80 focus-visible:outline-none"
                            :class="track.id === currentTrackId ? 'bg-smart-blue-900/45 text-smart-blue-100' : ''"
                            :aria-current="track.id === currentTrackId ? 'true' : undefined"
                            data-test="audio-queue-track"
                            @click="emit('play', track.id)"
                        >
                            <span class="text-right text-xs tabular-nums text-blue-slate-400">
                                {{ startIndex + index + 1 }}
                            </span>
                            <span class="flex size-10 items-center justify-center overflow-hidden bg-prussian-blue-900 ring-1 ring-twilight-indigo-500/70">
                                <img
                                    v-if="track.coverUrl"
                                    :src="track.coverUrl"
                                    alt=""
                                    class="h-full w-full object-cover"
                                    loading="lazy"
                                >
                                <Music v-else class="size-4 text-blue-slate-300" />
                            </span>
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-regal-navy-100">
                                    {{ track.title }}
                                </span>
                                <span class="block truncate text-xs text-blue-slate-300">
                                    {{ track.artists || track.album || 'Unknown artist' }}
                                </span>
                            </span>
                            <span class="text-right text-xs tabular-nums text-blue-slate-300">
                                {{ track.duration }}
                            </span>
                        </button>
                    </li>
                </ol>
            </template>
        </VirtualList>
    </aside>
</template>
