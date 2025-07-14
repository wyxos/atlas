<script setup lang="ts">
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { ContextMenu, ContextMenuItem, ContextMenuSeparator, ContextMenuGroup } from '@imengyu/vue3-context-menu';
import useContextMenu from '@/composables/useContextMenu';
import { usePage, router } from '@inertiajs/vue3';
import { computed } from 'vue';

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

// Handle adding file to playlist
function addToPlaylist(playlistId: number): void {
    if (!currentContent.value?.item?.id) return;

    router.post(route('playlists.files.store', { playlist: playlistId }), {
        file_id: currentContent.value.item.id,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            show.value = false;
        },
        onError: (errors) => {
            console.error('Failed to add to playlist:', errors);
        }
    });
}

// Check if a track is already in a playlist (placeholder - would need backend support)
// For now, we'll keep all playlists enabled since we don't have membership data
// In a real implementation, you'd need to pass playlist membership data from backend
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
                <context-menu-group label="Add to Playlist">
                    <context-menu-item
                        v-for="playlist in playlists"
                        :key="playlist.id"
                        :label="playlist.name"
                        @click="addToPlaylist(playlist.id)"
                    />
                </context-menu-group>
                <context-menu-separator />
            </template>

            <!-- Default context menu items -->
            <context-menu-item label="Play" v-if="isAudioContext" />
            <context-menu-item label="View Details" />
        </context-menu>
    </AppLayout>
</template>
