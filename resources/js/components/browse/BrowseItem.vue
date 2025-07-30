<script lang="ts" setup>
import FileReactions from '@/components/audio/FileReactions.vue';
import { useSeenStatus } from '@/composables/useSeenStatus';
import type { BrowseItem } from '@/types/browse';
import { computed, ref } from 'vue';

interface Props {
    item: BrowseItem;
    downloadProgress?: number;
    isDownloaded: boolean;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    favorite: [item: BrowseItem, event: Event];
    like: [item: BrowseItem, event: Event];
    dislike: [item: BrowseItem, event: Event];
    laughedAt: [item: BrowseItem, event: Event];
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

// Use the seen status composable
const { markAsSeen } = useSeenStatus();
const hasMarkedPreview = ref(false);

// Determine the status badge
const statusBadge = computed(() => {
    if (props.item.seen_file_at) {
        return { text: 'Viewed', class: 'bg-blue-500' };
    }
    if (props.item.seen_preview_at) {
        return { text: 'Previewed', class: 'bg-yellow-500' };
    }
    return { text: 'New', class: 'bg-red-500' };
});

// Handle marking as seen when preview is loaded
const handlePreviewLoaded = () => {
    if (!props.item.seen_preview_at && !hasMarkedPreview.value) {
        hasMarkedPreview.value = true;
        markAsSeen(props.item.id, 'preview');
    }
};

// Handle marking as seen when video completes (for videos only)
const handleVideoCompleted = () => {
    if (!props.item.seen_preview_at && !hasMarkedPreview.value) {
        hasMarkedPreview.value = true;
        markAsSeen(props.item.id, 'preview');
    }
};
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
                @load="handlePreviewLoaded"
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
                @loadeddata="handlePreviewLoaded"
                @ended="handleVideoCompleted"
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

        <!-- Status Badge -->
        <div class="absolute top-2 right-2 z-10">
            <div :class="statusBadge.class" class="rounded px-2 py-1 text-xs font-medium text-white shadow-lg">
                {{ statusBadge.text }}
            </div>
        </div>

        <!-- Footer area for reactions -->
        <div class="absolute right-0 bottom-0 left-0 flex items-center justify-end p-2" style="height: 32px">
            <FileReactions
                :file="item"
                :icon-size="16"
                variant="list"
                @dislike="(file, event) => $emit('dislike', item, event)"
                @favorite="(file, event) => $emit('favorite', item, event)"
                @laughedAt="(file, event) => $emit('laughedAt', item, event)"
                @like="(file, event) => $emit('like', item, event)"
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
