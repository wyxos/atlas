<script lang="ts" setup>
import { Toaster } from '@/components/ui/toast';
import useContextMenu from '@/composables/useContextMenu';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { ContextMenu, ContextMenuGroup, ContextMenuItem, ContextMenuSeparator } from '@imengyu/vue3-context-menu';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { MinusIcon, PlusIcon } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const { show, options, currentContent, loading } = useContextMenu();

function blockPostFromContext() {
    const postId = (currentContent.value?.item as any)?.postId;
    if (!postId) return;
    try {
        window?.dispatchEvent?.(new CustomEvent('browse:block-post', { detail: { postId } }));
    } catch (e) {
        console.error('Failed to dispatch block-post event', e);
    } finally {
        show.value = false;
    }
}

function likePostFromContext() {
    const postId = (currentContent.value?.item as any)?.postId;
    if (!postId) return;
    try {
        window?.dispatchEvent?.(new CustomEvent('browse:like-post', { detail: { postId } }));
    } catch (e) {
        console.error('Failed to dispatch like-post event', e);
    } finally {
        show.value = false;
    }
}

function blockUserFromContext() {
    const username = (currentContent.value?.item as any)?.username;
    if (!username) return;
    try {
        window?.dispatchEvent?.(new CustomEvent('browse:block-user', { detail: { username } }));
    } catch (e) {
        console.error('Failed to dispatch block-user event', e);
    } finally {
        show.value = false;
    }
}
const page = usePage();

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

// Get playlists from global props
const playlists = computed(() => (page.props.playlists as Array<{ id: number; name: string }>) || []);

// Check if current item is an audio file
const isAudioContext = computed(() => currentContent.value?.handler === 'audio-list');

// Track which playlists contain the current file
const filePlaylistIds = ref<number[]>([]);

// Fetch playlist membership when context menu content changes
watch(
    currentContent,
    async (newContent) => {
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
    },
    { immediate: true },
);

// Handle adding/removing file to/from playlist (toggle)
async function togglePlaylist(playlistId: number): Promise<void> {
    if (!currentContent.value?.item?.id) return;

    router.post(
        route('playlists.files.store', { playlist: playlistId }),
        {
            file_id: currentContent.value.item.id,
        },
        {
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
            },
        },
    );
}

// Check if a track is already in a playlist
function isInPlaylist(playlistId: number): boolean {
    return filePlaylistIds.value.includes(playlistId);
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
        <context-menu v-model:show="show" :options="options">
            <template v-if="loading">
                <!-- Spinner with Tailwind CSS -->
                <div class="flex items-center justify-center p-4">
                    <div class="h-6 w-6 animate-spin rounded-full border-4 border-blue-500 border-t-transparent"></div>
                    <span class="ml-2 text-sm">Loading...</span>
                </div>
            </template>
            <template v-else>
                <!-- Show playlist options only for audio files -->
                <template v-if="isAudioContext && playlists.length > 0">
                    <context-menu-group label="Playlists">
                        <context-menu-item
                            v-for="playlist in playlists"
                            :key="playlist.id"
                            :class="{ 'block bg-primary text-primary-foreground': isInPlaylist(playlist.id) }"
                            @click="togglePlaylist(playlist.id)"
                        >
                            <span>{{ playlist.name }}</span>
                            <PlusIcon v-if="!isInPlaylist(playlist.id)" :size="16" />
                            <MinusIcon v-else :size="16" />
                        </context-menu-item>
                    </context-menu-group>
                    <context-menu-separator />
                </template>

                <!-- Default context menu items -->
                <context-menu-item v-if="isAudioContext" label="Play" />

                <!-- Browse context menu actions -->
                <context-menu-item
                    v-if="currentContent?.handler === 'browse-list' && (currentContent?.item as any)?.postId"
                    label="Block post"
                    @click="blockPostFromContext"
                />
                <context-menu-item
                    v-if="currentContent?.handler === 'browse-list' && (currentContent?.item as any)?.username"
                    label="Block user"
                    @click="blockUserFromContext"
                />
                <context-menu-item
                    v-if="currentContent?.handler === 'browse-list' && (currentContent?.item as any)?.postId"
                    label="Like Post"
                    @click="likePostFromContext"
                />

                <context-menu-item label="View Details" />
            </template>
        </context-menu>
        <Toaster />
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
