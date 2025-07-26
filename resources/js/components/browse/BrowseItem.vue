<template>
    <div class="relative h-full">
        <!-- Image container with fixed imageHeight -->
        <div :style="{ height: item.imageHeight + 'px' }" class="relative">
            <img
                :alt="`Image ${item.id}`"
                :src="item.src"
                class="h-full w-full cursor-pointer object-cover transition-all duration-500 ease-in-out"
                loading="lazy"
                @error="(e) => console.warn('Failed to load image:', item.id, e)"
                @load="() => console.debug('Loaded image:', item.id)"
                @click.alt.exact.prevent="handleAltClick"
                @contextmenu.alt.exact.prevent="handleAltRightClick"
            />
        </div>

        <!-- Footer area for reactions -->
        <div class="absolute right-0 bottom-0 left-0 flex items-center justify-end p-2" style="height: 32px">
            <AudioReactions
                :file="item"
                :icon-size="16"
                variant="list"
                @dislike="(file, event) => $emit('dislike', file, event)"
                @favorite="$emit('favorite', $event)"
                @laughedAt="$emit('laughedAt', $event)"
                @like="$emit('like', $event)"
            />
        </div>

        <!-- Download progress bar - positioned at bottom of image area -->
        <div
            v-if="downloadProgress !== undefined"
            :style="{ bottom: '32px' }"
            class="absolute right-0 left-0 bg-black/50"
        >
            <div :style="{ width: downloadProgress + '%' }" class="h-1 bg-blue-500 transition-all duration-300"></div>
            <div class="p-1 text-center text-xs text-white">Downloading... {{ downloadProgress }}%</div>
        </div>

        <!-- Downloaded indicator -->
        <div v-if="isDownloaded" class="absolute top-2 left-2 rounded bg-green-500 px-2 py-1 text-xs text-white">
            ✓ Downloaded
        </div>
    </div>
</template>

<script setup lang="ts">
import AudioReactions from '@/components/audio/AudioReactions.vue';
import type { BrowseItem } from '@/types/browse';

interface Props {
    item: BrowseItem;
    downloadProgress?: number;
    isDownloaded: boolean;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    favorite: [file: any, event: Event];
    like: [file: any, event: Event];
    dislike: [file: any, event: Event];
    laughedAt: [file: any, event: Event];
    altClick: [item: BrowseItem];
    altRightClick: [item: BrowseItem];
}>();

const handleAltClick = () => {
    emit('altClick', props.item);
};

const handleAltRightClick = () => {
    emit('altRightClick', props.item);
};
</script>
