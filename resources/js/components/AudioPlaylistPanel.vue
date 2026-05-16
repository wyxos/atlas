<script setup lang="ts">
import { computed } from 'vue';
import type { AudioPlaylist, AudioPlaylistSection } from '@/types/audio';

const props = defineProps<{
    sections: AudioPlaylistSection[];
    activeSlug: string;
    isLoading: boolean;
    error: string | null;
}>();

const emit = defineEmits<{
    select: [playlist: AudioPlaylist];
}>();

const visibleSections = computed(() => props.sections.filter((section) => section.playlists.length > 0));
</script>

<template>
    <aside
        class="hidden w-72 shrink-0 border-y border-l border-twilight-indigo-500 bg-prussian-blue-800 md:flex md:flex-col"
        data-test="audio-playlist-panel"
    >
        <div
            class="min-h-0 flex-1 overflow-y-auto [scrollbar-gutter:stable]"
            data-test="audio-playlist-scroll"
        >
            <div v-if="props.isLoading" class="px-4 py-3 text-xs text-blue-slate-300">
                Loading playlists...
            </div>
            <div v-else-if="props.error" class="px-4 py-3 text-xs text-danger-200">
                {{ props.error }}
            </div>
            <template v-else>
                <section
                    v-for="section in visibleSections"
                    :key="section.key"
                    data-test="audio-playlist-section"
                >
                    <p
                        class="border-b border-twilight-indigo-500/45 px-4 py-2 text-[0.65rem] font-semibold uppercase tracking-wide text-blue-slate-400"
                        data-test="audio-playlist-section-label"
                    >
                        {{ section.label }}
                    </p>
                    <button
                        v-for="playlist in section.playlists"
                        :key="playlist.id"
                        type="button"
                        :aria-pressed="playlist.slug === props.activeSlug"
                        class="group flex w-full items-center justify-between gap-3 border-b border-twilight-indigo-500/45 px-4 py-3 text-left transition-colors hover:bg-prussian-blue-600/80"
                        :class="playlist.slug === props.activeSlug ? 'bg-prussian-blue-600/80' : ''"
                        data-test="audio-playlist-option"
                        @click="emit('select', playlist)"
                    >
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-regal-navy-100">{{ playlist.name }}</span>
                            <span class="block truncate text-xs text-blue-slate-300">{{ playlist.description }}</span>
                        </span>
                        <span class="shrink-0 text-xs tabular-nums text-blue-slate-300 group-hover:text-regal-navy-100">
                            {{ playlist.count }}
                        </span>
                    </button>
                </section>
            </template>
        </div>
        <button
            type="button"
            class="flex w-full shrink-0 items-center justify-center border-t border-twilight-indigo-500/70 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-smart-blue-200 transition-colors hover:bg-prussian-blue-600/80 hover:text-white"
            data-test="audio-add-playlist-cta"
        >
            Add playlist
        </button>
    </aside>
</template>
