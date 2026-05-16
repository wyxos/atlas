<script setup lang="ts">
import { Button } from '@/components/ui/button';

defineProps<{
    activeFilterLabel: string;
}>();

const emit = defineEmits<{
    togglePlaylists: [];
    openFilter: [];
}>();
</script>

<template>
    <div
        class="flex h-10 shrink-0 items-center justify-between border-b border-twilight-indigo-500/70 px-3 md:px-4"
        data-test="audio-list-header"
    >
        <Button
            type="button"
            variant="outline"
            size="sm"
            data-test="audio-playlists-cta"
            class="h-7 shrink-0 px-2 text-xs"
            @click="emit('togglePlaylists')"
        >
            Playlists
        </Button>
        <Button
            type="button"
            variant="outline"
            size="sm"
            data-test="audio-filter-cta"
            class="h-7 shrink-0 px-2 text-xs"
            @click="emit('openFilter')"
        >
            Filter: {{ activeFilterLabel }}
        </Button>
    </div>
</template>
