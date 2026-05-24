<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Music, X } from 'lucide-vue-next';
import VirtualList from './VirtualList.vue';
import { Skeleton } from '@/components/ui/skeleton';
import type { AudioPlayerTrack } from '@/composables/useGlobalAudioPlayer';

const props = defineProps<{
    tracks: AudioPlayerTrack[];
    currentTrackId: number | null;
    queueLabel: string | null;
}>();

const emit = defineEmits<{
    close: [];
    play: [trackId: number];
    visibleItemsChange: [items: AudioPlayerTrack[]];
}>();

const virtualListRef = ref<InstanceType<typeof VirtualList> | null>(null);

function handleVisibleItemsChange(items: unknown[]): void {
    emit('visibleItemsChange', items as AudioPlayerTrack[]);
}

function isTrackLoading(track: AudioPlayerTrack): boolean {
    return track.artists === 'Loading metadata...';
}

function scrollToCurrentTrack(): void {
    const currentIndex = props.tracks.findIndex((track) => track.id === props.currentTrackId);
    if (currentIndex < 0) {
        return;
    }

    void nextTick(() => {
        virtualListRef.value?.scrollToIndex(currentIndex, 'center');
    });
}

const trackCountLabel = computed(() => props.tracks.length === 1 ? '1 track' : `${props.tracks.length} tracks`);

onMounted(scrollToCurrentTrack);

watch(() => props.currentTrackId, scrollToCurrentTrack);
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
                <p class="truncate text-sm font-semibold text-regal-navy-100" data-test="audio-queue-title">
                    {{ queueLabel || trackCountLabel }}
                </p>
                <p v-if="queueLabel" class="mt-0.5 truncate text-xs text-blue-slate-300" data-test="audio-queue-count">
                    {{ trackCountLabel }}
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
            ref="virtualListRef"
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
                                <Skeleton
                                    v-if="isTrackLoading(track)"
                                    class="h-full w-full bg-prussian-blue-500/60"
                                />
                                <img
                                    v-else-if="track.coverUrl"
                                    :src="track.coverUrl"
                                    alt=""
                                    class="h-full w-full object-cover"
                                    loading="lazy"
                                >
                                <Music v-else class="size-4 text-blue-slate-300" />
                            </span>
                            <span v-if="isTrackLoading(track)" class="min-w-0 space-y-2" data-test="audio-queue-track-loading">
                                <Skeleton class="h-4 w-3/4 bg-prussian-blue-500/60" />
                                <Skeleton class="h-3 w-1/2 bg-prussian-blue-500/60" />
                            </span>
                            <span v-else class="min-w-0">
                                <span class="block truncate text-sm font-medium text-regal-navy-100">
                                    {{ track.title }}
                                </span>
                                <span class="block truncate text-xs text-blue-slate-300">
                                    {{ track.artists || track.album || 'Unknown artist' }}
                                </span>
                            </span>
                            <span v-if="isTrackLoading(track)" class="flex justify-end">
                                <Skeleton class="h-3 w-10 bg-prussian-blue-500/60" />
                            </span>
                            <span v-else class="text-right text-xs tabular-nums text-blue-slate-300">
                                {{ track.duration }}
                            </span>
                        </button>
                    </li>
                </ol>
            </template>
        </VirtualList>
    </aside>
</template>
