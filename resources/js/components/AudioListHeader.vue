<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { ListFilter, ListMusic, Shuffle } from 'lucide-vue-next';

defineProps<{
    activeFilterLabel: string;
    canShufflePlay: boolean;
}>();

const emit = defineEmits<{
    togglePlaylists: [];
    shufflePlay: [];
    openFilter: [];
}>();
</script>

<template>
    <div
        class="flex h-10 shrink-0 items-center justify-between border-b border-twilight-indigo-500/70 px-3 md:px-4"
        data-test="audio-list-header"
    >
        <div class="flex items-center gap-1.5">
            <Button
                type="button"
                variant="outline"
                size="icon-sm"
                data-test="audio-playlists-cta"
                class="size-7"
                aria-label="Toggle playlists"
                title="Toggle playlists"
                @click="emit('togglePlaylists')"
            >
                <ListMusic class="size-4" aria-hidden="true" />
            </Button>
            <Button
                type="button"
                variant="outline"
                size="icon-sm"
                data-test="audio-shuffle-play-cta"
                class="size-7"
                :disabled="!canShufflePlay"
                :aria-disabled="!canShufflePlay"
                aria-label="Shuffle play playlist"
                title="Shuffle play playlist"
                @click="emit('shufflePlay')"
            >
                <Shuffle class="size-4" aria-hidden="true" />
            </Button>
        </div>
        <Button
            type="button"
            variant="outline"
            size="icon-sm"
            data-test="audio-filter-cta"
            class="size-7"
            :aria-label="`Filter: ${activeFilterLabel}`"
            :title="`Filter: ${activeFilterLabel}`"
            @click="emit('openFilter')"
        >
            <ListFilter class="size-4" aria-hidden="true" />
        </Button>
    </div>
</template>
