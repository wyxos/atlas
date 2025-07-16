<script setup lang="ts">
import { RecycleScroller } from 'vue-virtual-scroller';
import { audioStore } from '@/stores/audioStore';
import AudioListItem from '@/components/audio/AudioListItem.vue';
import AudioSearch from '@/components/audio/AudioSearch.vue';
import { useAudioList } from '@/composables/useAudioList';
import useContextMenu from '@/composables/useContextMenu';

const {handleContextMenu} = useContextMenu();

interface Props {
    files: any[];
    search: any[];
    noResultsMessage?: string;
    searchRoute?: string;
}

const props = withDefaults(defineProps<Props>(), {
    noResultsMessage: 'No match was found for "{query}"'
});

// Use the audio list composable for all common functionality
const {
    loadedFiles,
    swipedItemId,
    recycleScrollerRef,
    initialQuery,
    playAudio,
    getFileData,
    toggleFavorite,
    likeItem,
    dislikeItem,
    laughedAtItem,
    handleTouchStart,
    handleTouchMove,
    handleTouchEnd,
    handleGlobalClick,
    onScroll
} = useAudioList(props);

// Expose the handleGlobalClick for parent components
defineExpose({
    handleGlobalClick
});
</script>

<template>
    <!-- Search component -->
    <AudioSearch :initial-query="initialQuery" :search-route="props.searchRoute">
        <template #noResults="{ query }">
            <p class="text-gray-500">{{ noResultsMessage.replace('{query}', query) }}</p>
        </template>

        <template #default="{ query }">

            <!-- Results list -->
            <div class="flex-1 md:p-4">
                <p class="mb-4 text-sm">
                    <strong>{{ props.files.length }}</strong> <span class="">tracks</span>
                </p>
                <RecycleScroller
                    ref="recycleScrollerRef"
                    class="h-[600px] RecycleScroller"
                    :items="query ? props.search : props.files"
                    :item-size="74"
                    key-field="id"
                    :emit-update="true"
                    @update="onScroll"
                    v-slot="{ item, index }"
                >
                    <div class="relative overflow-hidden">
                        <!-- List item component -->
                        <AudioListItem
                            :item="item"
                            :index="index + 1"
                            :loaded-file="loadedFiles[item.id]"
                            :is-playing="audioStore.isPlaying"
                            :current-file-id="audioStore.currentFile ? audioStore.currentFile.id : null"
                            :is-swiped-open="swipedItemId === item.id"
                            @play="playAudio(getFileData(item))"
                            @touch-start="handleTouchStart"
                            @touch-move="handleTouchMove"
                            @touch-end="handleTouchEnd"
                            @favorite="toggleFavorite"
                            @like="likeItem"
                            @dislike="dislikeItem"
                            @laughed-at="laughedAtItem"
                            @contextmenu.prevent="handleContextMenu($event, {handler: 'audio-list', item})"
                        />
                    </div>
                </RecycleScroller>
            </div>
        </template>
    </AudioSearch>
</template>
