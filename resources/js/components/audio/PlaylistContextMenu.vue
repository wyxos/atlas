<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { ListMusic, Plus } from 'lucide-vue-next';

interface Playlist {
    id: number;
    name: string;
}

interface Props {
    fileId: number;
    visible: boolean;
    x: number;
    y: number;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const page = usePage<{
    playlists: Playlist[];
}>();

const menuRef = ref<HTMLElement | null>(null);

// Get playlists from the page props (shared data)
const playlists = computed(() => page.props.playlists || []);

// Add track to playlist
function addToPlaylist(playlistId: number): void {
    router.post(route('playlists.files.store', { playlist: playlistId }), {
        file_id: props.fileId,
    }, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            emit('close');
        },
        onError: (errors) => {
            console.error('Failed to add track to playlist:', errors);
            emit('close');
        }
    });
}

// Handle clicks outside the menu
function handleClickOutside(event: MouseEvent): void {
    if (menuRef.value && !menuRef.value.contains(event.target as Node)) {
        emit('close');
    }
}

// Handle escape key
function handleEscapeKey(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        emit('close');
    }
}

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
    document.addEventListener('keydown', handleEscapeKey);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
    document.removeEventListener('keydown', handleEscapeKey);
});

// Position the menu to avoid going off-screen
const menuStyle = computed(() => {
    if (!props.visible) return { display: 'none' };

    const maxWidth = window.innerWidth;
    const maxHeight = window.innerHeight;
    const menuWidth = 250; // Approximate menu width
    const menuHeight = Math.min(300, (playlists.value.length * 40) + 60); // Approximate menu height

    let x = props.x;
    let y = props.y;

    // Adjust if menu would go off right edge
    if (x + menuWidth > maxWidth) {
        x = maxWidth - menuWidth - 10;
    }

    // Adjust if menu would go off bottom edge
    if (y + menuHeight > maxHeight) {
        y = maxHeight - menuHeight - 10;
    }

    return {
        position: 'fixed',
        left: `${x}px`,
        top: `${y}px`,
        zIndex: 1000,
    };
});
</script>

<template>
    <div
        v-if="visible"
        ref="menuRef"
        :style="menuStyle"
        class="bg-white border border-gray-200 rounded-lg shadow-lg py-2 min-w-[250px] max-h-[300px] overflow-y-auto"
    >
        <div class="px-3 py-2 text-sm font-semibold text-gray-700 border-b border-gray-100">
            Add to Playlist
        </div>

        <div v-if="playlists.length === 0" class="px-3 py-4 text-sm text-gray-500 text-center">
            <ListMusic class="w-8 h-8 mx-auto mb-2 text-gray-400" />
            <p>No playlists available</p>
            <p class="text-xs mt-1">Create a playlist first</p>
        </div>

        <div v-else class="py-1">
            <button
                v-for="playlist in playlists"
                :key="playlist.id"
                @click="addToPlaylist(playlist.id)"
                class="w-full px-3 py-2 text-left text-sm hover:bg-gray-50 flex items-center space-x-2 transition-colors"
            >
                <ListMusic class="w-4 h-4 text-gray-400" />
                <span class="truncate">{{ playlist.name }}</span>
            </button>
        </div>

        <div class="border-t border-gray-100 mt-1">
            <button
                @click="router.visit(route('playlists.index'))"
                class="w-full px-3 py-2 text-left text-sm hover:bg-gray-50 flex items-center space-x-2 text-blue-600 transition-colors"
            >
                <Plus class="w-4 h-4" />
                <span>Create New Playlist</span>
            </button>
        </div>
    </div>
</template>
