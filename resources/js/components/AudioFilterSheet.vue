<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import type { AudioSourceFilter } from '@/types/audio';

const props = defineProps<{
    open: boolean;
    activeFilter: AudioSourceFilter;
    visibleCount: number;
    totalCount: number;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    'update:activeFilter': [value: AudioSourceFilter];
}>();

const isOpen = computed({
    get: () => props.open,
    set: (value: boolean) => emit('update:open', value),
});
</script>

<template>
    <Sheet v-model:open="isOpen">
        <SheetContent side="right" class="w-full sm:max-w-sm">
            <SheetTitle class="sr-only">Audio filter</SheetTitle>
            <SheetDescription class="sr-only">Filter the audio list by source.</SheetDescription>
            <div class="space-y-4 px-6 pt-12" data-test="audio-filter-sheet-body">
                <p class="text-xs font-semibold uppercase tracking-wide text-twilight-indigo-200">Source</p>
                <div class="inline-flex rounded-lg border border-twilight-indigo-500 bg-prussian-blue-700 p-1">
                    <Button
                        type="button"
                        size="sm"
                        :variant="props.activeFilter === 'all' ? 'default' : 'ghost'"
                        data-test="audio-filter-all"
                        @click="emit('update:activeFilter', 'all')"
                    >
                        All
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :variant="props.activeFilter === 'spotify' ? 'default' : 'ghost'"
                        data-test="audio-filter-spotify"
                        @click="emit('update:activeFilter', 'spotify')"
                    >
                        Spotify
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :variant="props.activeFilter === 'local' ? 'default' : 'ghost'"
                        data-test="audio-filter-local"
                        @click="emit('update:activeFilter', 'local')"
                    >
                        Library
                    </Button>
                </div>
                <p class="text-xs text-blue-slate-300">
                    Showing {{ props.visibleCount }} of {{ props.totalCount }}
                </p>
            </div>
        </SheetContent>
    </Sheet>
</template>
