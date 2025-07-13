<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ListMusic, Plus, ChevronLeft, ChevronRight } from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('dashboard'),
    },
    {
        title: 'Playlists',
        href: route('playlists.index'),
    },
];

interface Playlist {
    id: number;
    name: string;
    description?: string;
    created_at: string;
    files_count?: number;
}

const { playlists } = defineProps<{
    playlists: {
        data: Playlist[];
        links: any[];
        meta: any;
    };
}>();

// New playlist form state
const newPlaylistName = ref('');
const isCreating = ref(false);

// Add new playlist
function addPlaylist(): void {
    if (!newPlaylistName.value.trim()) {
        return;
    }

    isCreating.value = true;

    router.post(route('playlists.store'), {
        name: newPlaylistName.value.trim(),
    }, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            newPlaylistName.value = '';
            isCreating.value = false;
        },
        onError: (errors) => {
            console.error('Failed to create playlist:', errors);
            isCreating.value = false;
        }
    });
}

// Handle enter key press
function handleKeyPress(event: KeyboardEvent): void {
    if (event.key === 'Enter') {
        addPlaylist();
    }
}

// Format date
function formatDate(dateString: string): string {
    return new Date(dateString).toLocaleDateString();
}
</script>

<template>
    <Head title="Playlists" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6">
            <div class="mb-6">
                <h1 class="text-3xl font-bold">Playlists</h1>
                <p class="text-gray-600 mt-2">Manage your custom playlists</p>
            </div>

            <!-- Playlists List -->
            <div class="space-y-4 mb-8">
                <Card
                    v-for="playlist in playlists.data"
                    :key="playlist.id"
                    class="hover:shadow-lg transition-shadow cursor-pointer"
                    @click="() => router.visit(route('playlists.show', { playlist: playlist.id }))"
                >
                    <CardContent class="p-4">
                        <div class="flex items-center space-x-4">
                            <div class="flex-shrink-0">
                                <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
                                    <ListMusic class="w-6 h-6 text-gray-400" />
                                </div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="font-semibold text-lg truncate" :title="playlist.name">
                                    {{ playlist.name }}
                                </h3>
                                <div class="flex items-center space-x-4 text-sm text-gray-500 mt-1">
                                    <span v-if="playlist.files_count !== undefined">
                                        {{ playlist.files_count }} {{ playlist.files_count === 1 ? 'track' : 'tracks' }}
                                    </span>
                                    <span>Created {{ formatDate(playlist.created_at) }}</span>
                                </div>
                                <p v-if="playlist.description" class="text-gray-600 text-sm mt-2 truncate">
                                    {{ playlist.description }}
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Add New Playlist -->
            <Card class="border-dashed border-2 border-gray-300">
                <CardContent class="p-4">
                    <div class="flex items-center space-x-3">
                        <Input
                            v-model="newPlaylistName"
                            placeholder="Enter playlist name..."
                            class="flex-1"
                            :disabled="isCreating"
                            @keypress="handleKeyPress"
                        />
                        <Button
                            @click="addPlaylist"
                            :disabled="!newPlaylistName.trim() || isCreating"
                            size="sm"
                        >
                            <Plus class="w-4 h-4" />
                            {{ isCreating ? 'Creating...' : 'Add' }}
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <!-- Pagination -->
            <div v-if="playlists.links && playlists.links.length > 3" class="mt-4 flex justify-center">
                <nav class="flex space-x-2">
                    <template v-for="link in playlists.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            class="rounded-md px-3 py-2 text-sm"
                            :class="{
                                'bg-blue-500 text-white': link.active,
                                'bg-gray-200 text-gray-700 hover:bg-gray-300': !link.active,
                            }"
                        >
                            <ChevronLeft v-if="link.label.includes('Previous')" class="h-4 w-4" />
                            <ChevronRight v-else-if="link.label.includes('Next')" class="h-4 w-4" />
                            <span v-else>{{ link.label }}</span>
                        </Link>
                        <span v-else class="px-3 py-2 text-sm text-gray-400">
                            <ChevronLeft v-if="link.label.includes('Previous')" class="h-4 w-4" />
                            <ChevronRight v-else-if="link.label.includes('Next')" class="h-4 w-4" />
                            <span v-else>{{ link.label }}</span>
                        </span>
                    </template>
                </nav>
            </div>

            <!-- Empty state -->
            <div v-if="playlists.data.length === 0" class="text-center py-12">
                <ListMusic class="w-16 h-16 text-gray-400 mx-auto mb-4" />
                <h3 class="text-lg font-semibold text-gray-600 mb-2">No Playlists Found</h3>
                <p class="text-gray-500 mb-4">Create your first playlist to get started.</p>
            </div>
        </div>
    </AppLayout>
</template>
