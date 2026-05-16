<script setup lang="ts">
const staticPlaylists = [
    { name: 'Spotify favorites', count: 124, description: 'Source: Spotify / Reaction: favorite' },
    { name: 'Spotify likes', count: 86, description: 'Source: Spotify / Reaction: like' },
    { name: 'Funny audio', count: 31, description: 'Reaction: funny / Any source' },
    { name: 'Banned from feed', count: 19, description: 'Reaction: ban / Out of feed' },
    { name: 'Local liked', count: 18, description: 'Source: local / Reaction: like' },
    { name: 'Unreacted Spotify', count: 54, description: 'Source: Spotify / No reaction' },
];
</script>

<template>
    <aside
        class="hidden w-72 shrink-0 border-y border-l border-twilight-indigo-500 bg-prussian-blue-800 md:flex md:flex-col"
        data-test="audio-playlist-panel"
    >
        <div class="min-h-0 flex-1 overflow-y-auto">
            <button
                v-for="playlist in staticPlaylists"
                :key="playlist.name"
                type="button"
                class="group flex w-full items-center justify-between gap-3 border-b border-twilight-indigo-500/45 px-4 py-3 text-left transition-colors hover:bg-prussian-blue-600/80"
            >
                <span class="min-w-0">
                    <span class="block truncate text-sm font-medium text-regal-navy-100">{{ playlist.name }}</span>
                    <span class="block truncate text-xs text-blue-slate-300">{{ playlist.description }}</span>
                </span>
                <span class="shrink-0 text-xs tabular-nums text-blue-slate-300 group-hover:text-regal-navy-100">
                    {{ playlist.count }}
                </span>
            </button>
            <button
                type="button"
                class="flex w-full items-center justify-center border-b border-twilight-indigo-500/45 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-smart-blue-200 transition-colors hover:bg-prussian-blue-600/80 hover:text-white"
                data-test="audio-add-playlist-cta"
            >
                Add playlist
            </button>
        </div>
    </aside>
</template>
