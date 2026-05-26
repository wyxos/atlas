<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ListMusic, Music } from 'lucide-vue-next';
import PageLayout from '../components/PageLayout.vue';
import type { AudioPlaylist, AudioPlaylistSection, AudioPlaylistsResponse } from '@/types/audio';

const sections = ref<AudioPlaylistSection[]>([]);
const isLoading = ref(true);
const error = ref<string | null>(null);

const visibleSections = computed(() => sections.value.filter((section) => section.playlists.length > 0));
const playlistCount = computed(() => visibleSections.value.reduce((count, section) => count + section.playlists.length, 0));

function playlistRoute(playlist: AudioPlaylist) {
    return {
        name: 'audio',
        params: {
            playlistSlug: playlist.slug,
        },
    };
}

async function loadPlaylists(): Promise<void> {
    isLoading.value = true;
    error.value = null;

    try {
        const { data } = await window.axios.get<AudioPlaylistsResponse>('/api/audio/playlists');
        sections.value = Array.isArray(data.sections) ? data.sections : [];
    } catch (loadError) {
        console.error('Failed to load audio playlists:', loadError);
        error.value = 'Failed to load playlists.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(() => {
    void loadPlaylists();
});
</script>

<template>
    <PageLayout flush>
        <div class="flex h-full min-h-0 w-full flex-col overflow-hidden bg-prussian-blue-900" data-test="audio-playlist-grid-page">
            <div class="flex h-14 shrink-0 items-center justify-between border-b border-twilight-indigo-500 bg-prussian-blue-800 px-4">
                <div class="flex min-w-0 items-center gap-3">
                    <ListMusic class="size-5 shrink-0 text-smart-blue-200" />
                    <h1 class="truncate text-sm font-semibold text-regal-navy-100">Audio</h1>
                </div>
                <p class="text-xs tabular-nums text-blue-slate-300" data-test="audio-playlist-grid-count">
                    {{ playlistCount }} playlists
                </p>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto p-4 [scrollbar-gutter:stable]">
                <div v-if="isLoading" class="text-sm text-blue-slate-300" data-test="audio-playlist-grid-loading">
                    Loading playlists...
                </div>
                <div v-else-if="error" class="border border-danger-500 bg-prussian-blue-700 p-4 text-sm text-danger-200" data-test="audio-playlist-grid-error">
                    {{ error }}
                </div>
                <div v-else-if="playlistCount === 0" class="text-sm text-blue-slate-300" data-test="audio-playlist-grid-empty">
                    No playlists available.
                </div>
                <div v-else class="space-y-6" data-test="audio-playlist-grid">
                    <section
                        v-for="section in visibleSections"
                        :key="section.key"
                        data-test="audio-playlist-grid-section"
                    >
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <h2 class="text-xs font-semibold uppercase tracking-wide text-blue-slate-400">
                                {{ section.label }}
                            </h2>
                            <p class="text-xs tabular-nums text-blue-slate-400">
                                {{ section.playlists.length }}
                            </p>
                        </div>
                        <div class="grid grid-cols-2 gap-x-4 gap-y-6 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-6 2xl:grid-cols-8">
                            <RouterLink
                                v-for="playlist in section.playlists"
                                :key="playlist.id"
                                v-slot="{ href, navigate }"
                                :to="playlistRoute(playlist)"
                                custom
                            >
                                <a
                                    :href="href"
                                    class="group block min-w-0 text-left focus-visible:outline focus-visible:outline-2 focus-visible:outline-smart-blue-300"
                                    data-test="audio-playlist-card"
                                    @click="navigate"
                                >
                                    <span
                                        class="flex aspect-square w-full items-center justify-center overflow-hidden bg-prussian-blue-800 ring-1 ring-twilight-indigo-500/70 transition group-hover:ring-smart-blue-500"
                                        data-test="audio-playlist-card-cover"
                                    >
                                        <img
                                            v-if="playlist.cover_url"
                                            :src="playlist.cover_url"
                                            alt=""
                                            class="h-full w-full object-cover"
                                            loading="lazy"
                                        >
                                        <Music v-else class="size-8 text-blue-slate-300" />
                                    </span>
                                    <span class="mt-2 block min-w-0">
                                        <span class="block truncate text-sm font-semibold text-regal-navy-100" data-test="audio-playlist-card-title">
                                            {{ playlist.name }}
                                        </span>
                                        <span class="mt-1 block text-xs tabular-nums text-blue-slate-300" data-test="audio-playlist-card-count">
                                            {{ playlist.count }} tracks
                                        </span>
                                    </span>
                                </a>
                            </RouterLink>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </PageLayout>
</template>
