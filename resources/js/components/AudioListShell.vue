<script setup lang="ts">
import { ref } from 'vue';
import AudioListHeader from './AudioListHeader.vue';
import AudioTrackRow from './AudioTrackRow.vue';
import VirtualList from './VirtualList.vue';
import type { ReactionType } from '@/types/reaction';

const props = defineProps<{
    activeFilterLabel: string;
    audioIds: number[];
    canShufflePlay: boolean;
    isLoading: boolean;
    hasDetails: (audioId: number) => boolean;
    detailTitle: (audioId: number) => string;
    detailArtists: (audioId: number) => string;
    detailAlbum: (audioId: number) => string;
    detailCoverUrl: (audioId: number) => string | null;
    detailReaction: (audioId: number) => { type: ReactionType } | null;
    detailBlacklistedAt: (audioId: number) => string | null;
    detailPreviewedCount: (audioId: number) => number;
    detailSeenCount: (audioId: number) => number;
    detailDuration: (audioId: number) => string;
    selectedAudioId: number | null;
    currentTrackId: number | null;
    isPlaying: boolean;
}>();

const emit = defineEmits<{
    togglePlaylists: [];
    shufflePlay: [];
    openFilter: [];
    scroll: [];
    visibleItemsChange: [items: unknown[]];
    select: [audioId: number];
    play: [audioId: number];
    pause: [audioId: number];
    reaction: [audioId: number, type: ReactionType];
    blacklist: [audioId: number];
}>();

const virtualListRef = ref<InstanceType<typeof VirtualList> | null>(null);

function scrollToAudioId(audioId: number): boolean {
    const index = props.audioIds.indexOf(audioId);
    if (index < 0) {
        return false;
    }

    virtualListRef.value?.scrollToIndex(index, 'center');

    return true;
}

defineExpose({
    scrollToAudioId,
});
</script>

<template>
    <div
        class="flex min-h-0 min-w-0 flex-1 flex-col border border-twilight-indigo-500 bg-prussian-blue-700"
        data-test="audio-list-shell"
    >
        <AudioListHeader
            :active-filter-label="activeFilterLabel"
            :can-shuffle-play="canShufflePlay"
            @toggle-playlists="emit('togglePlaylists')"
            @shuffle-play="emit('shufflePlay')"
            @open-filter="emit('openFilter')"
        />
        <div v-if="isLoading" class="p-4 text-twilight-indigo-100">Preparing full audio index...</div>
        <div v-else-if="audioIds.length === 0" class="p-4 text-twilight-indigo-100">
            No audio files match this filter.
        </div>
        <VirtualList
            v-else
            ref="virtualListRef"
            :items="audioIds"
            :item-height="72"
            :overscan="4"
            container-class="min-h-0 flex-1 overflow-y-auto [scrollbar-gutter:stable]"
            @scroll="emit('scroll')"
            @visible-items-change="emit('visibleItemsChange', $event)"
        >
            <template #default="{ items, startIndex }">
                <ul class="divide-y divide-twilight-indigo-500/70">
                    <AudioTrackRow
                        v-for="(audioId, index) in items"
                        :key="audioId"
                        :audio-id="audioId"
                        :display-index="startIndex + index + 1"
                        :is-loaded="hasDetails(audioId)"
                        :title="detailTitle(audioId)"
                        :artists="detailArtists(audioId)"
                        :album="detailAlbum(audioId)"
                        :cover-url="detailCoverUrl(audioId)"
                        :reaction="detailReaction(audioId)"
                        :blacklisted-at="detailBlacklistedAt(audioId)"
                        :previewed-count="detailPreviewedCount(audioId)"
                        :seen-count="detailSeenCount(audioId)"
                        :duration="detailDuration(audioId)"
                        :is-selected="selectedAudioId === audioId"
                        :is-current-track="currentTrackId === audioId"
                        :is-playing="isPlaying && currentTrackId === audioId"
                        @select="emit('select', audioId)"
                        @play="emit('play', audioId)"
                        @pause="emit('pause', audioId)"
                        @reaction="(audioId, type) => emit('reaction', audioId, type)"
                        @blacklist="(audioId) => emit('blacklist', audioId)"
                    />
                </ul>
            </template>
        </VirtualList>
    </div>
</template>
