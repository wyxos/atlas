<script setup lang="ts">
import AudioPlaylistPanel from './AudioPlaylistPanel.vue';
import type { AudioPlaylist, AudioPlaylistSection } from '@/types/audio';

defineProps<{
    activeSlug: string;
    error: string | null;
    isLoading: boolean;
    isOpen: boolean;
    sections: AudioPlaylistSection[];
}>();

const emit = defineEmits<{
    close: [];
    select: [playlist: AudioPlaylist];
}>();
</script>

<template>
    <Transition
        enter-active-class="transition-opacity duration-500 ease-in-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition-opacity duration-300 ease-in-out"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <button
            v-if="isOpen"
            type="button"
            class="absolute inset-0 z-20 bg-black/55 md:hidden"
            aria-label="Close playlists"
            data-test="audio-playlist-backdrop"
            @click="emit('close')"
        />
    </Transition>
    <div
        class="absolute inset-y-0 left-0 z-30 min-h-0 w-72 max-w-[calc(100vw-2rem)] shrink-0 overflow-hidden shadow-2xl transition-[transform,opacity,width] ease-in-out md:relative md:inset-auto md:z-auto md:max-w-none md:shadow-none"
        :class="isOpen ? 'translate-x-0 opacity-100 duration-500 md:w-72' : 'pointer-events-none -translate-x-full opacity-0 duration-300 md:w-0 md:translate-x-0 md:opacity-100'"
        data-test="audio-playlist-panel-frame"
    >
        <Transition
            enter-active-class="transition duration-500 ease-in-out"
            enter-from-class="-translate-x-full opacity-0"
            enter-to-class="translate-x-0 opacity-100"
            leave-active-class="transition duration-300 ease-in-out"
            leave-from-class="translate-x-0 opacity-100"
            leave-to-class="-translate-x-full opacity-0"
        >
            <AudioPlaylistPanel
                v-if="isOpen"
                :sections="sections"
                :active-slug="activeSlug"
                :is-loading="isLoading"
                :error="error"
                @select="emit('select', $event)"
            />
        </Transition>
    </div>
</template>
