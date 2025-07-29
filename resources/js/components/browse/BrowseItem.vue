<script lang="ts" setup>
import FileReactions from '@/components/audio/FileReactions.vue';
import type { BrowseItem } from '@/types/browse';
import { computed } from 'vue';

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
    leftClick: [item: BrowseItem];
}>();

const handleLeftClick = () => {
    // Navigate to single file view with preserved state
    emit('leftClick', props.item);
};

const handleAltClick = () => {
    emit('altClick', props.item);
};

const handleAltRightClick = () => {
    emit('altRightClick', props.item);
};

// Utility function to detect if the file is a video based on its URL
const isVideo = computed(() => {
    const src = props.item.src.toLowerCase();
    return (
        src.includes('.mp4') ||
        src.includes('.webm') ||
        src.includes('.mov') ||
        src.includes('.avi') ||
        src.includes('.mkv') ||
        src.includes('.wmv') ||
        src.includes('.m4v')
    );
});

// Utility function to detect if the file is an image
const isImage = computed(() => {
    const src = props.item.src.toLowerCase();
    return (
        src.includes('.jpg') ||
        src.includes('.jpeg') ||
        src.includes('.png') ||
        src.includes('.gif') ||
        src.includes('.webp') ||
        src.includes('.svg') ||
        src.includes('.bmp') ||
        !isVideo.value
    );
});
</script>

<template>
    <div class="relative h-full">
        <!-- Media container with fixed imageHeight -->
        <div :style="{ height: item.imageHeight + 'px' }" class="relative">
            <!-- Image element for image files -->
            <img
                v-if="isImage"
                :alt="`Image ${item.id}`"
                :src="item.src"
                class="h-full w-full cursor-pointer object-cover"
                loading="lazy"
                @error="(e) => console.warn('Failed to load image:', item.id, e)"
                @load="() => console.debug('Loaded image:', item.id)"
                @click.left.exact="handleLeftClick"
                @click.alt.exact.prevent="handleAltClick"
                @contextmenu.alt.exact.prevent="handleAltRightClick"
            />

            <!-- Video element for video files -->
            <video
                v-else-if="isVideo"
                :src="item.src"
                class="h-full w-full cursor-pointer object-cover transition-all duration-500 ease-in-out"
                loop
                muted
                playsinline
                preload="metadata"
                @error="(e) => console.warn('Failed to load video:', item.id, e)"
                @loadeddata="() => console.debug('Loaded video:', item.id)"
                @mouseenter="(e) => e.target.play().catch(() => {})"
                @mouseleave="(e) => e.target.pause()"
                @click.left.exact="handleLeftClick"
                @click.alt.exact.prevent="handleAltClick"
                @contextmenu.alt.exact.prevent="handleAltRightClick"
            >
                <source :src="item.src" type="video/mp4" />
                Your browser does not support the video tag.
            </video>
        </div>

        <!-- Footer area for reactions -->
        <div class="absolute right-0 bottom-0 left-0 flex items-center justify-end p-2" style="height: 32px">
            <FileReactions
                :file="item"
                :icon-size="16"
                variant="list"
                @dislike="(file, event) => $emit('dislike', file, event)"
                @favorite="(file, event) => $emit('favorite', file, event)"
                @laughedAt="(file, event) => $emit('laughedAt', file, event)"
                @like="(file, event) => $emit('like', file, event)"
            />
        </div>

        <!-- Download progress bar - positioned at bottom of image area -->
        <div v-if="downloadProgress !== undefined" :style="{ bottom: '32px' }" class="absolute right-0 left-0 bg-black/50">
            <div :style="{ width: downloadProgress + '%' }" class="h-1 bg-blue-500 transition-all duration-300"></div>
            <div class="p-1 text-center text-xs text-white">Downloading... {{ downloadProgress }}%</div>
        </div>

        <!-- Downloaded indicator -->
        <div v-if="isDownloaded" class="absolute top-2 left-2 rounded bg-green-500 px-2 py-1 text-xs text-white">✓ Downloaded</div>
    </div>
</template>
