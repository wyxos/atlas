<script setup lang="ts">
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { ContextMenu, ContextMenuItem, ContextMenuSeparator, ContextMenuGroup } from '@imengyu/vue3-context-menu';
import useContextMenu from '@/composables/useContextMenu';
import { usePage, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { PlusIcon, MinusIcon } from 'lucide-vue-next';

const { show, options, currentContent } = useContextMenu();
const page = usePage();

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

// Get playlists from global props
const playlists = computed(() => page.props.playlists as Array<{ id: number; name: string }> || []);

// Check if current item is an audio file
const isAudioContext = computed(() => currentContent.value?.handler === 'audio-list');

// Track which playlists contain the current file
const filePlaylistIds = ref<number[]>([]);

// Fetch playlist membership when context menu content changes
watch(currentContent, async (newContent) => {
    if (newContent?.handler === 'audio-list' && newContent?.item?.id) {
        try {
            const response = await axios.get(route('files.playlists', { file: newContent.item.id }));
            filePlaylistIds.value = response.data;
        } catch (error) {
            console.error('Failed to fetch playlist membership:', error);
            filePlaylistIds.value = [];
        }
    } else {
        filePlaylistIds.value = [];
    }
}, { immediate: true });

// Handle adding/removing file to/from playlist (toggle)
async function togglePlaylist(playlistId: number): Promise<void> {
    if (!currentContent.value?.item?.id) return;

    router.post(route('playlists.files.store', { playlist: playlistId }), {
        file_id: currentContent.value.item.id,
    }, {
        preserveScroll: true,
        onSuccess: async () => {
            // Refresh playlist membership data
            try {
                const response = await axios.get(route('files.playlists', { file: currentContent.value!.item!.id }));
                filePlaylistIds.value = response.data;
            } catch (error) {
                console.error('Failed to refresh playlist membership:', error);
            }
        },
        onError: (errors) => {
            console.error('Failed to toggle playlist:', errors);
        }
    });
}

// Check if a track is already in a playlist
function isInPlaylist(playlistId: number): boolean {
    return filePlaylistIds.value.includes(playlistId);
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
        <context-menu
            v-model:show="show"
            :options="options"
        >
            <!-- Show playlist options only for audio files -->
            <template v-if="isAudioContext && playlists.length > 0">
                <context-menu-group label="Playlists">
                    <context-menu-item
                        v-for="playlist in playlists"
                        :key="playlist.id"
                        :class="{ 'bg-primary text-primary-foreground block': isInPlaylist(playlist.id) }"
                        @click="togglePlaylist(playlist.id)"
                        >
                        <span>{{ playlist.name }}</span>
                        <PlusIcon v-if="!isInPlaylist(playlist.id)" size="16" />
                        <MinusIcon v-else size="16" />
                    </context-menu-item>
                </context-menu-group>
                <context-menu-separator />
            </template>

            <!-- Default context menu items -->
            <context-menu-item label="Play" v-if="isAudioContext" />
            <context-menu-item label="View Details" />
        </context-menu>
    </AppLayout>
</template>

<style scoped>
:deep(.context-menu-item.active) {
    background-color: rgba(34, 197, 94, 0.1);
    color: rgb(34, 197, 94);
}

:deep(.context-menu-item.active:hover) {
    background-color: rgba(34, 197, 94, 0.2);
}
</style>
