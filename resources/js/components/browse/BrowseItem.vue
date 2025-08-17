<script lang="ts" setup>
import FileReactions from '@/components/audio/FileReactions.vue';
import { useSeenStatus } from '@/composables/useSeenStatus';
import type { BrowseItem } from '@/types/browse';
import { computed, onMounted, onUnmounted, ref } from 'vue';

interface Props {
    item: BrowseItem;
    downloadProgress?: number;
    isDownloaded: boolean;
    isLoading?: boolean;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    favorite: [item: BrowseItem, event: Event];
    like: [item: BrowseItem, event: Event];
    dislike: [item: BrowseItem, event: Event];
    laughedAt: [item: BrowseItem, event: Event];
    altClick: [item: BrowseItem];
    altMiddleClick: [item: BrowseItem];
    altRightClick: [item: BrowseItem];
    leftClick: [item: BrowseItem];
    contextmenu: [event: MouseEvent];
}>();


const handleLeftClick = () => {
    // Navigate to single file view with preserved state
    emit('leftClick', props.item);
};

const handleAltClick = () => {
    emit('altClick', props.item);
};

const handleAltMiddleClick = () => {
    emit('altMiddleClick', props.item);
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

// Check if element is within the viewport
const isElementInViewport = (el) => {
    const rect = el.getBoundingClientRect();
    return (
        rect.top >= 0 &&
        rect.left >= 0 &&
        rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
        rect.right <= (window.innerWidth || document.documentElement.clientWidth)
    );
};

// Handle marking as seen when preview is loaded (but only if fully visible)
const handlePreviewLoaded = () => {
    if (!props.item.seen_preview_at && !hasMarkedPreview.value && isFullyVisible.value) {
        hasMarkedPreview.value = true;
        markAsSeen(props.item.id, 'preview');
    }
};

// Lazy loading state
const isInViewport = ref(false);
const isFullyVisible = ref(false);

// Observer for lazy loading
let lazyLoadObserver;
// Observer for full visibility tracking
let visibilityObserver;

onMounted(() => {
    // Lazy loading observer - loads content when partially visible
    const lazyLoadOptions = { root: null, rootMargin: '50px', threshold: 0.1 };
    lazyLoadObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                isInViewport.value = true;
                lazyLoadObserver.unobserve(entry.target);
            }
        });
    }, lazyLoadOptions);

    // Full visibility observer - marks as seen when fully visible
    const visibilityOptions = { root: null, rootMargin: '0px', threshold: 1.0 };
    visibilityObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting && entry.intersectionRatio >= 1.0) {
                isFullyVisible.value = true;
                // If content is already loaded and now fully visible, mark as seen
                if (isInViewport.value && !props.item.seen_preview_at && !hasMarkedPreview.value) {
                    hasMarkedPreview.value = true;
                    markAsSeen(props.item.id, 'preview');
                }
            } else {
                isFullyVisible.value = false;
            }
        });
    }, visibilityOptions);

    const element = document.getElementById(`browse-item-${props.item.id}`);
    if (element) {
        lazyLoadObserver.observe(element);
        visibilityObserver.observe(element);
    }
});

onUnmounted(() => {
    if (lazyLoadObserver) {
        lazyLoadObserver.disconnect();
    }
    if (visibilityObserver) {
        visibilityObserver.disconnect();
    }
});

// Handle marking as seen when video completes (for videos only) - but only if fully visible
const handleVideoCompleted = () => {
    if (!props.item.seen_preview_at && !hasMarkedPreview.value && isFullyVisible.value) {
        hasMarkedPreview.value = true;
        markAsSeen(props.item.id, 'preview');
    }
};
</script>

<template>
    <div :id="`browse-item-${item.id}`" class="relative h-full" @contextmenu="(event) => !event.altKey && $emit('contextmenu', event)">
        <!-- Media container with fixed imageHeight -->
        <div>
            <!-- Image element for image files -->
            <img
                v-if="isImage"
                :alt="`Image ${item.id}`"
                :height="item.imageHeight"
                :src="isInViewport ? item.src : undefined"
                class="h-full w-full cursor-pointer object-cover"
                loading="lazy"
                @error="(e) => console.warn('Failed to load image:', item.id, e)"
                @load="handlePreviewLoaded"
                @click.left.exact="handleLeftClick"
                @click.alt.exact.prevent="handleAltClick"
                @click.middle.alt.exact.prevent="handleAltMiddleClick"
                @contextmenu.alt.exact.prevent="handleAltRightClick"
            />

            <!-- Video element for video files -->
            <video
                v-else-if="isVideo"
                :src="isInViewport ? item.src : undefined"
                class="h-full w-full cursor-pointer object-cover transition-all duration-500 ease-in-out"
                loop
                muted
                playsinline
                preload="metadata"
                @ended="handleVideoCompleted"
                @error="(e) => console.warn('Failed to load video:', item.id, e)"
                @loadeddata="handlePreviewLoaded"
                @mouseenter="(e) => e.target.play().catch(() => {})"
                @mouseleave="(e) => e.target.pause()"
                @click.left.exact="handleLeftClick"
                @click.alt.exact.prevent="handleAltClick"
                @click.middle.alt.exact.prevent="handleAltMiddleClick"
                @contextmenu.alt.exact.prevent="handleAltRightClick"
            >
                <source v-if="isInViewport" :src="item.src" type="video/mp4" />
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
            <div>
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
