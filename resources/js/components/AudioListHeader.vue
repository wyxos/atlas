<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { ListFilter, ListMusic, Music, Shuffle, Tags } from 'lucide-vue-next';

defineProps<{
    activeFilterLabel: string;
    canShufflePlay: boolean;
    hasQueue: boolean;
}>();

const emit = defineEmits<{
    toggleQueue: [];
    togglePlaylists: [];
    shufflePlay: [];
    scanMetadata: [];
    openFilter: [];
}>();
</script>

<template>
    <div
        class="flex h-14 shrink-0 items-center justify-between border-b border-twilight-indigo-500/70 px-3 md:h-10 md:px-4"
        data-test="audio-list-header"
    >
        <div class="flex items-center gap-2 md:gap-1.5">
            <Button
                type="button"
                variant="outline"
                size="icon-sm"
                data-test="audio-playlists-cta"
                class="size-10 md:size-7"
                aria-label="Toggle playlists"
                title="Toggle playlists"
                @click="emit('togglePlaylists')"
            >
                <ListMusic class="size-5 md:size-4" aria-hidden="true" />
            </Button>
            <Button
                type="button"
                variant="outline"
                size="icon-sm"
                data-test="audio-shuffle-play-cta"
                class="size-10 md:size-7"
                :disabled="!canShufflePlay"
                :aria-disabled="!canShufflePlay"
                aria-label="Shuffle play playlist"
                title="Shuffle play playlist"
                @click="emit('shufflePlay')"
            >
                <Shuffle class="size-5 md:size-4" aria-hidden="true" />
            </Button>
            <Button
                type="button"
                variant="outline"
                size="icon-sm"
                data-test="audio-metadata-scan-cta"
                class="size-10 md:size-7"
                aria-label="Scan metadata"
                title="Scan metadata"
                @click="emit('scanMetadata')"
            >
                <Tags class="size-5 md:size-4" aria-hidden="true" />
            </Button>
        </div>
        <div class="flex items-center gap-2 md:gap-1.5">
            <Button
                type="button"
                variant="outline"
                size="icon-sm"
                data-test="audio-queue-cta"
                class="size-10 md:size-7"
                :disabled="!hasQueue"
                :aria-disabled="!hasQueue"
                aria-label="Open queue"
                title="Open queue"
                @click="emit('toggleQueue')"
            >
                <Music class="size-5 md:size-4" aria-hidden="true" />
            </Button>
            <Button
                type="button"
                variant="outline"
                size="icon-sm"
                data-test="audio-filter-cta"
                class="size-10 md:size-7"
                :aria-label="`Filter: ${activeFilterLabel}`"
                :title="`Filter: ${activeFilterLabel}`"
                @click="emit('openFilter')"
            >
                <ListFilter class="size-5 md:size-4" aria-hidden="true" />
            </Button>
        </div>
    </div>
</template>
